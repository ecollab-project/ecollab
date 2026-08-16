<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PeerMatchingService;


final class PeerMatchingServiceTest extends TestCase
{
    private PeerMatchingService $service;

    protected function setUp(): void
    {
        $this->service = new \PeerMatchingService();
    }

    public function testIdenticalProfilesProduceHighCompatibility(): void
    {
        $profile = [
            'prefs' => [
                'study_style' => 'group',
                'session_length' => 'medium',
                'time_preference' => 'evening',
                'learning_mode' => 'mixed',
                'pace' => 'moderate',
                'comm_style' => 'frequent',
                'primary_goal' => 'improve_skills',
                'availability_days' => 31,
            ],
            'subjects' => [
                [
                    'subject_id' => 1,
                    'role' => 'studying',
                    'proficiency' => 'intermediate',
                ],
            ],
            'interests' => [
                ['interest_id' => 1],
                ['interest_id' => 2],
            ],
            'hobbies' => [
                ['hobby_id' => 1],
                ['hobby_id' => 2],
            ],
        ];

        $result = $this->service->scoreProfiles($profile, $profile);

        self::assertGreaterThanOrEqual(90, $result['total']);
        self::assertSame(1, $result['shared_subjects']);
        self::assertSame(2, $result['shared_interests']);
        self::assertSame(2, $result['shared_hobbies']);
    }

    public function testNoSharedTagsProduceLowCompatibility(): void
    {
        $profileA = [
            'prefs' => [
                'study_style' => 'solo',
                'session_length' => 'short',
                'time_preference' => 'morning',
                'learning_mode' => 'visual',
                'pace' => 'slow',
                'comm_style' => 'minimal',
                'primary_goal' => 'pass_exams',
                'availability_days' => 1,
            ],
            'subjects' => [
                [
                    'subject_id' => 1,
                    'role' => 'studying',
                    'proficiency' => 'beginner',
                ],
            ],
            'interests' => [
                ['interest_id' => 1],
            ],
            'hobbies' => [
                ['hobby_id' => 1],
            ],
        ];

        $profileB = [
            'prefs' => [
                'study_style' => 'group',
                'session_length' => 'long',
                'time_preference' => 'night',
                'learning_mode' => 'auditory',
                'pace' => 'fast',
                'comm_style' => 'frequent',
                'primary_goal' => 'research',
                'availability_days' => 64,
            ],
            'subjects' => [
                [
                    'subject_id' => 99,
                    'role' => 'tutoring',
                    'proficiency' => 'expert',
                ],
            ],
            'interests' => [
                ['interest_id' => 99],
            ],
            'hobbies' => [
                ['hobby_id' => 99],
            ],
        ];

        $result = $this->service->scoreProfiles($profileA, $profileB);

        self::assertSame(0, $result['shared_subjects']);
        self::assertSame(0, $result['shared_interests']);
        self::assertSame(0, $result['shared_hobbies']);
        self::assertLessThan(50, $result['total']);
    }

    public function testComplementaryStudyRolesImproveSubjectScore(): void
    {
        $baseA = [
            'prefs' => [],
            'subjects' => [
                [
                    'subject_id' => 1,
                    'role' => 'studying',
                    'proficiency' => 'beginner',
                ],
            ],
            'interests' => [],
            'hobbies' => [],
        ];

        $tutor = [
            'prefs' => [],
            'subjects' => [
                [
                    'subject_id' => 1,
                    'role' => 'tutoring',
                    'proficiency' => 'advanced',
                ],
            ],
            'interests' => [],
            'hobbies' => [],
        ];

        $sameRole = [
            'prefs' => [],
            'subjects' => [
                [
                    'subject_id' => 1,
                    'role' => 'studying',
                    'proficiency' => 'beginner',
                ],
            ],
            'interests' => [],
            'hobbies' => [],
        ];

        $complementary = $this->service->scoreProfiles(
            $baseA,
            $tutor
        );

        $same = $this->service->scoreProfiles(
            $baseA,
            $sameRole
        );

        self::assertGreaterThan(
            $same['subjects'],
            $complementary['subjects']
        );
    }

    public function testSharedAvailabilityIsRecognized(): void
    {
        $a = [
            'prefs' => [
                'availability_days' => 31,
            ],
            'subjects' => [],
            'interests' => [],
            'hobbies' => [],
        ];

        $b = [
            'prefs' => [
                'availability_days' => 15,
            ],
            'subjects' => [],
            'interests' => [],
            'hobbies' => [],
        ];

        $result = $this->service->scoreProfiles($a, $b);

        self::assertContains(
            'Compatible availability',
            $result['tags']
        );
    }

    public function testScoreAlwaysStaysWithinBounds(): void
    {
        $profiles = [
            [
                'prefs' => [],
                'subjects' => [],
                'interests' => [],
                'hobbies' => [],
            ],
            [
                'prefs' => [
                    'study_style' => 'group',
                    'session_length' => 'medium',
                    'time_preference' => 'evening',
                    'learning_mode' => 'mixed',
                    'pace' => 'moderate',
                    'comm_style' => 'occasional',
                    'primary_goal' => 'improve_skills',
                    'availability_days' => 127,
                ],
                'subjects' => [
                    [
                        'subject_id' => 1,
                        'role' => 'both',
                        'proficiency' => 'expert',
                    ],
                ],
                'interests' => [
                    ['interest_id' => 1],
                ],
                'hobbies' => [
                    ['hobby_id' => 1],
                ],
            ],
        ];

        $result = $this->service->scoreProfiles(
            $profiles[0],
            $profiles[1]
        );

        self::assertGreaterThanOrEqual(0, $result['total']);
        self::assertLessThanOrEqual(100, $result['total']);

        self::assertGreaterThanOrEqual(0, $result['subjects']);
        self::assertLessThanOrEqual(100, $result['subjects']);

        self::assertGreaterThanOrEqual(0, $result['style']);
        self::assertLessThanOrEqual(100, $result['style']);

        self::assertGreaterThanOrEqual(0, $result['interests']);
        self::assertLessThanOrEqual(100, $result['interests']);

        self::assertGreaterThanOrEqual(0, $result['hobbies']);
        self::assertLessThanOrEqual(100, $result['hobbies']);
    }
}