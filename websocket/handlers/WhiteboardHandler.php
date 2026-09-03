<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/config/db.php';

/**
 * WhiteboardHandler — Tracks active whiteboard rooms, relays ops,
 * and persists full state snapshots for late-joining peers.
 *
 * Op types relayed:
 *   stroke_start  { path_id, tool, color, size, x, y }
 *   stroke_point  { path_id, x, y }
 *   stroke_end    { path_id }
 *   erase         { x, y, radius }
 *   sticky_add    { obj_id, x, y, color, text }
 *   sticky_move   { obj_id, x, y }
 *   sticky_text   { obj_id, text }
 *   text_add      { obj_id, x, y, color, text }
 *   text_move     { obj_id, x, y }
 *   text_edit     { obj_id, text }
 *   undo          { path_id }          (last path of that user)
 *   clear         {}
 *   cursor        { x, y }             (high-freq, NOT persisted)
 */
class WhiteboardHandler
{
    private PDO $db;

    /**
     * @var array<int, array<int, array{user_id:int,username:string,color:string,resourceId:int}>>
     *   channel_id => [ user_id => participant ]
     */
    private array $rooms = [];

    /**
     * @var array<int, array[]> channel_id => [op, op, ...]  (persisted ops only)
     */
    private array $opLog = [];

    /** @var array<int, string> channel_id => last full stateJson from DB */
    private array $stateCache = [];

    /** Palette for remote cursor / avatar colours (gradient pairs) */
    private const PALETTE = [
        ['#3b82f6', '#1d4ed8'],
        ['#ec4899', '#be185d'],
        ['#14b8a6', '#0f766e'],
        ['#f59e0b', '#b45309'],
        ['#22c55e', '#15803d'],
        ['#6366f1', '#4338ca'],
        ['#ef4444', '#b91c1c'],
        ['#f97316', '#c2410c'],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Room management
    // ─────────────────────────────────────────────────────────────────

    /**
     * Add user to a whiteboard room.
     * Returns the initial state payload to send back to the joiner.
     */
    public function join(int $channelId, int $userId, string $username): array
    {
        if (!isset($this->rooms[$channelId])) {
            $this->rooms[$channelId] = [];
        }

        $colorIdx = count($this->rooms[$channelId]) % count(self::PALETTE);
        [$c1, $c2] = self::PALETTE[$colorIdx];

        $this->rooms[$channelId][$userId] = [
            'user_id'  => $userId,
            'username' => $username,
            'color'    => $c1,
            'grad'     => "linear-gradient(135deg,{$c1},{$c2})",
            'initial'  => mb_strtoupper(mb_substr($username, 0, 1)),
        ];

        // Build peers list (everyone already in the room, excluding the joiner)
        $peers = [];
        foreach ($this->rooms[$channelId] as $uid => $p) {
            if ($uid !== $userId) {
                $peers[] = $p;
            }
        }

        // Fetch persisted state + buffered ops for late-join replay
        $stateJson = $this->getState($channelId);
        $pendingOps = $this->opLog[$channelId] ?? [];

        return [
            'type'        => 'wb_joined',
            'channel_id'  => $channelId,
            'peers'       => array_values($peers),
            'you'         => $this->rooms[$channelId][$userId],
            'state_json'  => $stateJson,
            'pending_ops' => $pendingOps,
        ];
    }

    /**
     * Remove user from a whiteboard room.
     * Returns list of remaining peer user_ids to notify.
     */
    public function leave(int $channelId, int $userId): array
    {
        if (!isset($this->rooms[$channelId])) return [];
        unset($this->rooms[$channelId][$userId]);
        if (empty($this->rooms[$channelId])) {
            unset($this->rooms[$channelId]);
            // Flush op-log — full state was already persisted on last op
            unset($this->opLog[$channelId]);
        }
        return $this->getRoomUserIds($channelId);
    }

    /** Returns all user_ids currently in the room (excluding $excludeId). */
    public function getRoomUserIds(int $channelId, int $excludeId = 0): array
    {
        $ids = array_keys($this->rooms[$channelId] ?? []);
        return array_values(array_filter($ids, fn($id) => $id !== $excludeId));
    }

    public function getUserMeta(int $channelId, int $userId): ?array
    {
        return $this->rooms[$channelId][$userId] ?? null;
    }

    public function getMembers(int $channelId): array
    {
        return array_values($this->rooms[$channelId] ?? []);
    }

    public function canEdit(int $channelId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT created_by, locked FROM whiteboards WHERE channel_id=:cid ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([':cid' => $channelId]);
            $board = $stmt->fetch();
            return !$board || !(bool)$board['locked'] || (int)$board['created_by'] === $userId;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Op handling
    // ─────────────────────────────────────────────────────────────────

    /**
     * Record and optionally persist an op.
     * Cursor ops are NOT logged.
     * Returns the stamped op to broadcast (with sender info attached).
     */
    public function recordOp(int $channelId, int $userId, array $op): array
    {
        $meta = $this->rooms[$channelId][$userId] ?? [];

        $stamped = array_merge($op, [
            'user_id'  => $userId,
            'username' => $meta['username'] ?? '',
            'color'    => $meta['color']    ?? '#a855f7',
            'grad'     => $meta['grad']     ?? '',
            'initial'  => $meta['initial']  ?? '?',
            'ts'       => round(microtime(true) * 1000),
        ]);

        $opType = $op['op'] ?? '';

        // Cursor is ephemeral — skip logging
        if ($opType === 'cursor') {
            return $stamped;
        }

        if (!isset($this->opLog[$channelId])) {
            $this->opLog[$channelId] = [];
        }
        $this->opLog[$channelId][] = $stamped;

        // Persist full snapshot every 20 ops (cheap debounce)
        if (count($this->opLog[$channelId]) % 20 === 0) {
            $this->persistSnapshot($channelId, $userId);
        }

        return $stamped;
    }

    // ─────────────────────────────────────────────────────────────────
    //  State persistence
    // ─────────────────────────────────────────────────────────────────

    public function persistSnapshot(int $channelId, int $userId, string $stateJson = ''): void
    {
        // If caller supplies a full state (e.g. from client on leave), use it.
        // Otherwise we just record the op-log as a structured snapshot.
        $payload = $stateJson ?: json_encode([
            'source'  => 'op_log_snapshot',
            'ops'     => $this->opLog[$channelId] ?? [],
            'savedAt' => date('c'),
        ]);

        try {
            $exists = $this->db->prepare(
                "SELECT id FROM whiteboards WHERE channel_id=:cid ORDER BY updated_at DESC LIMIT 1"
            );
            $exists->execute([':cid' => $channelId]);
            $row = $exists->fetch();

            if ($row) {
                $this->db->prepare(
                    "UPDATE whiteboards SET state_json=:s, updated_by=:uid, updated_at=NOW() WHERE id=:id"
                )->execute([':s' => $payload, ':uid' => $userId, ':id' => $row['id']]);
            } else {
                $this->db->prepare(
                    "INSERT INTO whiteboards (channel_id, state_json, created_by, created_at, updated_at)
                     VALUES (:cid, :s, :uid, NOW(), NOW())"
                )->execute([':cid' => $channelId, ':s' => $payload, ':uid' => $userId]);
            }
            $this->stateCache[$channelId] = $payload;
        } catch (\Exception $e) {
            echo "[WhiteboardHandler] DB error: {$e->getMessage()}\n";
        }
    }

    public function getState(int $channelId): ?string
    {
        if (isset($this->stateCache[$channelId])) {
            return $this->stateCache[$channelId];
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT state_json FROM whiteboards WHERE channel_id=:cid ORDER BY updated_at DESC LIMIT 1"
            );
            $stmt->execute([':cid' => $channelId]);
            $row = $stmt->fetch();
            if ($row) {
                $this->stateCache[$channelId] = $row['state_json'];
                return $row['state_json'];
            }
        } catch (\Exception) {
        }
        return null;
    }
}
