<?php
declare(strict_types=1);

/** Only the CLI collector contacts LiveKit; readers cannot trigger upstream work. */
final class LiveKitCountSnapshot
{
    public function __construct(private PDO $db) {}

    public function publish(array $response, int $startedAt): void
    {
        $counts = [];
        foreach ($response['rooms'] ?? [] as $room) {
            if (preg_match('/^ecollab-channel-([1-9][0-9]*)$/D', (string)($room['name'] ?? ''), $m)) {
                $counts[(int)$m[1]] = max(0, (int)($room['num_participants'] ?? $room['numParticipants'] ?? 0));
            }
        }
        $q = $this->db->prepare('INSERT INTO livekit_count_snapshot (id, counts_json, collected_at) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE counts_json=VALUES(counts_json), collected_at=VALUES(collected_at)');
        $q->execute([json_encode((object)$counts, JSON_THROW_ON_ERROR), $startedAt]);
    }

    public function read(array $allowed, int $now, int $maxAge = 15): array
    {
        $row = $this->db->query('SELECT counts_json, collected_at FROM livekit_count_snapshot WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $at = $row ? (int)$row['collected_at'] : null;
        $stale = $at === null || $at > $now || $now - $at > $maxAge;
        $all = $row ? json_decode($row['counts_json'], true, 512, JSON_THROW_ON_ERROR) : [];
        $counts = [];
        foreach ($allowed as $id) $counts[(int)$id] = $stale ? null : (int)($all[$id] ?? 0);
        return ['success'=>true, 'counts'=>(object)$counts, 'collected_at'=>$at, 'stale'=>$stale];
    }
}
