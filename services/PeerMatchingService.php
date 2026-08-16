<?php

declare(strict_types=1);

/**
 * Ecollab Peer Matching Service
 *
 * Explainable multi-factor compatibility engine.
 *
 * Score components:
 *   Subjects  = 35%
 *   Study     = 25%
 *   Interests = 25%
 *   Hobbies   = 15%
 *
 * The service intentionally contains no HTTP/session logic so it can be
 * tested independently from API/chat/peer-match.php.
 */
class PeerMatchingService
{
    public const WEIGHT_SUBJECTS = 35;
    public const WEIGHT_STYLE = 25;
    public const WEIGHT_INTERESTS = 25;
    public const WEIGHT_HOBBIES = 15;

    /**
     * Score two already-loaded profiles.
     *
     * Expected profile structure:
     *
     * [
     *   'prefs' => [...],
     *   'subjects' => [
     *      ['subject_id' => 1, 'role' => 'studying',
     *       'proficiency' => 'beginner']
     *   ],
     *   'interests' => [
     *      ['interest_id' => 1]
     *   ],
     *   'hobbies' => [
     *      ['hobby_id' => 1]
     *   ]
     * ]
     *
     * @return array<string,mixed>
     */
    public function scoreProfiles(array $profileA, array $profileB): array
    {
        $subjects = $this->scoreSubjects(
            $profileA['subjects'] ?? [],
            $profileB['subjects'] ?? []
        );

        $interests = $this->scoreTagSet(
            $profileA['interests'] ?? [],
            $profileB['interests'] ?? [],
            'interest_id'
        );

        $hobbies = $this->scoreTagSet(
            $profileA['hobbies'] ?? [],
            $profileB['hobbies'] ?? [],
            'hobby_id'
        );

        $style = $this->scoreStudyPreferences(
            $profileA['prefs'] ?? [],
            $profileB['prefs'] ?? []
        );

        $total =
            ($subjects['score'] * self::WEIGHT_SUBJECTS / 100)
            + ($style['score'] * self::WEIGHT_STYLE / 100)
            + ($interests['score'] * self::WEIGHT_INTERESTS / 100)
            + ($hobbies['score'] * self::WEIGHT_HOBBIES / 100);

        $tags = [];

        foreach ($subjects['tags'] as $tag) {
            $tags[] = $tag;
        }

        foreach ($style['tags'] as $tag) {
            $tags[] = $tag;
        }

        foreach ($interests['tags'] as $tag) {
            $tags[] = $tag;
        }

        foreach ($hobbies['tags'] as $tag) {
            $tags[] = $tag;
        }

        return [
            'total' => round($total, 2),

            'subjects' => round($subjects['score'], 2),
            'style' => round($style['score'], 2),
            'interests' => round($interests['score'], 2),
            'hobbies' => round($hobbies['score'], 2),

            'shared_subjects' => $subjects['shared_count'],
            'shared_interests' => $interests['shared_count'],
            'shared_hobbies' => $hobbies['shared_count'],

            'tags' => array_values(array_unique($tags)),
        ];
    }

    /**
     * Calculate subject compatibility.
     *
     * Shared subjects are important, but complementary roles receive
     * additional value:
     *
     *   studying <-> tutoring = strongest
     *   both = strong
     *   studying <-> studying = useful
     */
    private function scoreSubjects(array $subjectsA, array $subjectsB): array
    {
        $a = [];
        $b = [];

        foreach ($subjectsA as $subject) {
            $id = (int)($subject['subject_id'] ?? $subject['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $a[$id] = $subject;
        }

        foreach ($subjectsB as $subject) {
            $id = (int)($subject['subject_id'] ?? $subject['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $b[$id] = $subject;
        }

        if (!$a && !$b) {
            return [
                'score' => 0.0,
                'shared_count' => 0,
                'tags' => [],
            ];
        }

        $shared = array_intersect_key($a, $b);
        $unionCount = count(array_unique(array_merge(
            array_keys($a),
            array_keys($b)
        )));

        if (!$shared || $unionCount === 0) {
            return [
                'score' => 0.0,
                'shared_count' => 0,
                'tags' => [],
            ];
        }

        $roleScores = [];
        $tags = [];

        foreach ($shared as $subjectId => $subjectA) {
            $subjectB = $b[$subjectId];

            $roleA = (string)($subjectA['role'] ?? 'studying');
            $roleB = (string)($subjectB['role'] ?? 'studying');

            $roleScore = $this->roleCompatibility($roleA, $roleB);

            $profA = (string)($subjectA['proficiency'] ?? 'intermediate');
            $profB = (string)($subjectB['proficiency'] ?? 'intermediate');

            $proficiencyScore = $this->proficiencyCompatibility(
                $profA,
                $profB
            );

            $roleScores[] = ($roleScore * 0.70) + ($proficiencyScore * 0.30);

            if (
                ($roleA === 'studying' && $roleB === 'tutoring')
                || ($roleA === 'tutoring' && $roleB === 'studying')
            ) {
                $tags[] = 'Complementary study roles';
            } elseif ($roleA === 'both' || $roleB === 'both') {
                $tags[] = 'Flexible study roles';
            } else {
                $tags[] = 'Shared subject';
            }
        }

        $overlapRatio = count($shared) / $unionCount;
        $roleQuality = array_sum($roleScores) / count($roleScores);

        /*
         * 70% of subject score comes from how much their academic interests
         * overlap. 30% comes from role/proficiency compatibility.
         */
        $score = ($overlapRatio * 70) + ($roleQuality * 0.30);

        return [
            'score' => min(100.0, $score),
            'shared_count' => count($shared),
            'tags' => array_values(array_unique($tags)),
        ];
    }

    private function roleCompatibility(string $a, string $b): float
    {
        if (
            ($a === 'studying' && $b === 'tutoring')
            || ($a === 'tutoring' && $b === 'studying')
        ) {
            return 100.0;
        }

        if ($a === 'both' || $b === 'both') {
            return 90.0;
        }

        if ($a === $b) {
            return 75.0;
        }

        return 50.0;
    }

    private function proficiencyCompatibility(string $a, string $b): float
    {
        $levels = [
            'beginner' => 1,
            'intermediate' => 2,
            'advanced' => 3,
            'expert' => 4,
        ];

        $levelA = $levels[$a] ?? 2;
        $levelB = $levels[$b] ?? 2;

        $difference = abs($levelA - $levelB);

        return match ($difference) {
            0 => 100.0,
            1 => 85.0,
            2 => 65.0,
            default => 45.0,
        };
    }

    /**
     * Jaccard-style tag compatibility.
     */
    private function scoreTagSet(
        array $tagsA,
        array $tagsB,
        string $key
    ): array {
        $a = [];

        foreach ($tagsA as $tag) {
            $id = (int)($tag[$key] ?? $tag['id'] ?? 0);

            if ($id > 0) {
                $a[$id] = true;
            }
        }

        $b = [];

        foreach ($tagsB as $tag) {
            $id = (int)($tag[$key] ?? $tag['id'] ?? 0);

            if ($id > 0) {
                $b[$id] = true;
            }
        }

        if (!$a && !$b) {
            return [
                'score' => 0.0,
                'shared_count' => 0,
                'tags' => [],
            ];
        }

        $shared = array_intersect_key($a, $b);
        $union = array_unique(array_merge(
            array_keys($a),
            array_keys($b)
        ));

        if (!$union) {
            return [
                'score' => 0.0,
                'shared_count' => 0,
                'tags' => [],
            ];
        }

        $score = count($shared) / count($union) * 100;

        $label = $key === 'interest_id'
            ? 'Shared interests'
            : 'Shared hobbies';

        return [
            'score' => min(100.0, $score),
            'shared_count' => count($shared),
            'tags' => $shared ? [$label] : [],
        ];
    }

    /**
     * Study preference compatibility.
     *
     * Availability uses the existing 7-bit representation:
     *
     * Monday    = 1
     * Tuesday   = 2
     * Wednesday = 4
     * Thursday  = 8
     * Friday    = 16
     * Saturday  = 32
     * Sunday    = 64
     */
    private function scoreStudyPreferences(
        array $prefsA,
        array $prefsB
    ): array {
        $scores = [];
        $tags = [];

        $scores[] = $this->categoricalCompatibility(
            $prefsA['study_style'] ?? null,
            $prefsB['study_style'] ?? null,
            ['mixed' => 80.0]
        );

        if (
            ($prefsA['study_style'] ?? null) !== null
            && ($prefsA['study_style'] ?? null)
                === ($prefsB['study_style'] ?? null)
        ) {
            $tags[] = 'Same study style';
        }

        $scores[] = $this->categoricalCompatibility(
            $prefsA['session_length'] ?? null,
            $prefsB['session_length'] ?? null,
            []
        );

        $scores[] = $this->categoricalCompatibility(
            $prefsA['time_preference'] ?? null,
            $prefsB['time_preference'] ?? null,
            ['flexible' => 85.0]
        );

        $scores[] = $this->categoricalCompatibility(
            $prefsA['learning_mode'] ?? null,
            $prefsB['learning_mode'] ?? null,
            ['mixed' => 80.0]
        );

        $scores[] = $this->categoricalCompatibility(
            $prefsA['pace'] ?? null,
            $prefsB['pace'] ?? null,
            ['adaptive' => 85.0]
        );

        $scores[] = $this->categoricalCompatibility(
            $prefsA['comm_style'] ?? null,
            $prefsB['comm_style'] ?? null,
            []
        );

        $scores[] = $this->goalCompatibility(
            $prefsA['primary_goal'] ?? null,
            $prefsB['primary_goal'] ?? null
        );

        $scores[] = $this->availabilityCompatibility(
            (int)($prefsA['availability_days'] ?? 0),
            (int)($prefsB['availability_days'] ?? 0)
        );

        $score = $scores
            ? array_sum($scores) / count($scores)
            : 0.0;

        if (
            $this->availabilityOverlap(
                (int)($prefsA['availability_days'] ?? 0),
                (int)($prefsB['availability_days'] ?? 0)
            ) > 0
        ) {
            $tags[] = 'Compatible availability';
        }

        if (
            ($prefsA['primary_goal'] ?? null) !== null
            && ($prefsA['primary_goal'] ?? null)
                === ($prefsB['primary_goal'] ?? null)
        ) {
            $tags[] = 'Shared learning goal';
        }

        return [
            'score' => min(100.0, $score),
            'tags' => array_values(array_unique($tags)),
        ];
    }

    private function categoricalCompatibility(
        ?string $a,
        ?string $b,
        array $specialValues
    ): float {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return 50.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        if (isset($specialValues[$a])) {
            return (float)$specialValues[$a];
        }

        if (isset($specialValues[$b])) {
            return (float)$specialValues[$b];
        }

        return 40.0;
    }

    private function goalCompatibility(
        ?string $a,
        ?string $b
    ): float {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return 50.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        $compatibleGroups = [
            'pass_exams' => [
                'improve_skills',
                'find_study_partners',
            ],
            'build_projects' => [
                'network_collaborate',
                'improve_skills',
                'research',
            ],
            'find_study_partners' => [
                'pass_exams',
                'improve_skills',
                'network_collaborate',
            ],
            'improve_skills' => [
                'pass_exams',
                'build_projects',
                'find_study_partners',
                'research',
            ],
            'network_collaborate' => [
                'build_projects',
                'find_study_partners',
            ],
            'research' => [
                'build_projects',
                'improve_skills',
            ],
        ];

        if (
            isset($compatibleGroups[$a])
            && in_array($b, $compatibleGroups[$a], true)
        ) {
            return 75.0;
        }

        return 40.0;
    }

    private function availabilityCompatibility(
        int $a,
        int $b
    ): float {
        if ($a === 0 || $b === 0) {
            return 50.0;
        }

        $intersection = $a & $b;
        $union = $a | $b;

        if ($union === 0) {
            return 0.0;
        }

        return ($this->bitCount($intersection) / $this->bitCount($union))
            * 100.0;
    }

    private function availabilityOverlap(int $a, int $b): int
    {
        return $this->bitCount($a & $b);
    }

    private function bitCount(int $value): int
    {
        $count = 0;

        while ($value > 0) {
            $count += $value & 1;
            $value >>= 1;
        }

        return $count;
    }

    /**
     * Load a profile directly from the peer-matching schema.
     *
     * This method deliberately returns the same structure used by
     * scoreProfiles(), keeping database access separate from scoring logic.
     *
     * @return array<string,mixed>
     */
    public function loadProfile(PDO $db, int $userId): array
    {
        $prefsStmt = $db->prepare(
            'SELECT * FROM pm_user_study_prefs WHERE user_id = :user_id LIMIT 1'
        );

        $prefsStmt->execute([
            ':user_id' => $userId,
        ]);

        $prefs = $prefsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $subjectsStmt = $db->prepare(
            'SELECT subject_id, role, proficiency
             FROM pm_user_subjects
             WHERE user_id = :user_id'
        );

        $subjectsStmt->execute([
            ':user_id' => $userId,
        ]);

        $subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

        $interestsStmt = $db->prepare(
            'SELECT interest_id
             FROM pm_user_interests
             WHERE user_id = :user_id'
        );

        $interestsStmt->execute([
            ':user_id' => $userId,
        ]);

        $interests = $interestsStmt->fetchAll(PDO::FETCH_ASSOC);

        $hobbiesStmt = $db->prepare(
            'SELECT hobby_id
             FROM pm_user_hobbies
             WHERE user_id = :user_id'
        );

        $hobbiesStmt->execute([
            ':user_id' => $userId,
        ]);

        $hobbies = $hobbiesStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'prefs' => $prefs,
            'subjects' => $subjects,
            'interests' => $interests,
            'hobbies' => $hobbies,
        ];
    }

    /**
     * Compute a database-backed compatibility score.
     *
     * @return array<string,mixed>
     */
    public function computeScore(
        PDO $db,
        int $userA,
        int $userB
    ): array {
        $profileA = $this->loadProfile($db, $userA);
        $profileB = $this->loadProfile($db, $userB);

        return $this->scoreProfiles($profileA, $profileB);
    }
}