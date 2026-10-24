<?php
declare(strict_types=1);

/** Bounded request-local persistence; scoring and candidate selection remain in the caller. */
final class PeerMatchBatch
{
    public static function requestStatuses(PDO $db, int $userId, array $candidateIds): array
    {
        if (!$candidateIds) return [];
        if (count($candidateIds) > 100) throw new InvalidArgumentException('Candidate batch exceeds endpoint bound');
        $in = implode(',', array_fill(0, count($candidateIds), '?'));
        $stmt = $db->prepare("SELECT addressee_id,status FROM pm_match_requests WHERE requester_id=? AND addressee_id IN ($in)");
        $stmt->execute([$userId, ...$candidateIds]);
        $statuses = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $statuses[(int)$row['addressee_id']] = $row['status'] ?: null;
        return $statuses;
    }

    public static function persistCompatibility(PDO $db, array $rows): void
    {
        if (!$rows) return;
        if (count($rows) > 100) throw new InvalidArgumentException('Candidate batch exceeds endpoint bound');
        // Stable primary-key order reduces conflicting lock acquisition between overlapping requests.
        usort($rows, static fn(array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        $values = implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?,?,?,?)'));
        $params = [];
        foreach ($rows as $row) {
            if (count($row) !== 11) throw new InvalidArgumentException('Invalid compatibility row');
            array_push($params, ...$row);
        }
        // A single InnoDB statement is atomic; do not hold a transaction during scoring/provider work.
        $stmt = $db->prepare("INSERT INTO pm_compatibility
            (user_a_id,user_b_id,score_total,score_subjects,score_interests,score_hobbies,score_style,shared_subjects,shared_interests,shared_hobbies,match_tags)
            VALUES $values
            ON DUPLICATE KEY UPDATE score_total=VALUES(score_total),score_subjects=VALUES(score_subjects),score_interests=VALUES(score_interests),score_hobbies=VALUES(score_hobbies),score_style=VALUES(score_style),shared_subjects=VALUES(shared_subjects),shared_interests=VALUES(shared_interests),shared_hobbies=VALUES(shared_hobbies),match_tags=VALUES(match_tags),computed_at=CURRENT_TIMESTAMP");
        $stmt->execute($params);
    }
}
