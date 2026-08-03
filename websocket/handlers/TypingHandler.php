<?php
declare(strict_types=1);

/**
 * TypingHandler — Manages per-channel typing state with TTL auto-expiry.
 */
class TypingHandler {
    /** @var array<int, array<int, float>> channelId => [userId => timestamp] */
    private array $typing = [];

    private const TTL = 5.0; // seconds

    public function setTyping(int $channelId, int $userId, bool $isTyping): void {
        $this->gc();
        if ($isTyping) {
            $this->typing[$channelId][$userId] = microtime(true);
        } else {
            unset($this->typing[$channelId][$userId]);
            if (empty($this->typing[$channelId])) {
                unset($this->typing[$channelId]);
            }
        }
    }

    public function getTypingUserIds(int $channelId): array {
        $this->gc();
        return array_keys($this->typing[$channelId] ?? []);
    }

    /**
     * Garbage collect expired typing events.
     */
    private function gc(): void {
        $cutoff = microtime(true) - self::TTL;
        foreach ($this->typing as $cid => $users) {
            foreach ($users as $uid => $ts) {
                if ($ts < $cutoff) unset($this->typing[$cid][$uid]);
            }
            if (empty($this->typing[$cid])) unset($this->typing[$cid]);
        }
    }
}
