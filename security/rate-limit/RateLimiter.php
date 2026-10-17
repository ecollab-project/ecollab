<?php
declare(strict_types=1);

if (!defined('APP_NAME')) require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__) . '/ClientIp.php';

/** Database-backed sliding window. Migration 061 owns the schema, never a hot request. */
class RateLimiter
{
    private PDO $db;
    public function __construct(?PDO $db = null) { $this->db = $db ?? Database::getInstance(); }

    private static function key(string $action, string $identity): string
    {
        // Preserve existing short-action counters; long actions must fit VARCHAR(80).
        return strlen($action) <= 15 ? $action.':'.hash('sha256', $identity) : hash('sha256', $action.':'.$identity);
    }

    public function attempt(string $action, string $identity, int $maxAttempts = 10, int $windowSeconds = 900): array
    {
        if ($maxAttempts < 1 || $windowSeconds < 1) throw new InvalidArgumentException('Invalid rate limit.');
        $key = self::key($action, $identity);
        // No transaction/row lock survives this short count+insert section. Different keys run independently.
        $lockName = 'ec-rl-'.hash('sha224', (string)$this->db->query('SELECT DATABASE()')->fetchColumn().':'.$key);
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 1)'); $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) return ['allowed'=>false,'attempts'=>$maxAttempts,'retry_after'=>1];
        try {
            $count = $this->db->prepare("SELECT COUNT(*) AS attempts,
                GREATEST(1, COALESCE($windowSeconds - TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()), 1)) AS retry_after
                FROM rate_limit_log WHERE lookup_key=? AND created_at >= (NOW() - INTERVAL $windowSeconds SECOND)");
            $count->execute([$key]); $row=$count->fetch(PDO::FETCH_ASSOC); $attempts=(int)$row['attempts'];
            if ($attempts >= $maxAttempts) return ['allowed'=>false,'attempts'=>$attempts,'retry_after'=>(int)$row['retry_after']];
            $this->db->prepare('INSERT INTO rate_limit_log (lookup_key,created_at) VALUES (?,NOW())')->execute([$key]);
            $this->db->prepare("DELETE FROM rate_limit_log WHERE lookup_key=? AND created_at < (NOW() - INTERVAL $windowSeconds SECOND) LIMIT 100")->execute([$key]);
            return ['allowed'=>true,'attempts'=>$attempts+1,'retry_after'=>0];
        } finally {
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    public function clear(string $action, string $identity): void
    {
        $this->db->prepare('DELETE FROM rate_limit_log WHERE lookup_key=?')->execute([self::key($action,$identity)]);
    }

    public function remaining(string $action, string $identity, int $maxAttempts, int $windowSeconds): int
    {
        if ($maxAttempts < 1 || $windowSeconds < 1) throw new InvalidArgumentException('Invalid rate limit.');
        $count=$this->db->prepare("SELECT COUNT(*) FROM rate_limit_log WHERE lookup_key=? AND created_at >= (NOW() - INTERVAL $windowSeconds SECOND)");
        $count->execute([self::key($action,$identity)]);
        return max(0,$maxAttempts-(int)$count->fetchColumn());
    }

    public static function getIP(): string { return ClientIp::resolve($_SERVER); }
}
