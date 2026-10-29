<?php
declare(strict_types=1);

/** Authoritative identity checks. Missing/invalid flags never become humans. */
final class SystemIdentity
{
    public static function human(array $user): bool
    {
        return array_key_exists('is_system', $user)
            && ($user['is_system'] === 0 || $user['is_system'] === '0');
    }

    public static function humanId(PDO $db, int $id): bool
    {
        $stmt = $db->prepare('SELECT is_system FROM users WHERE id=?');
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user !== false && self::human($user);
    }

    public static function reserved(PDO $db, string $username): bool
    {
        // Match the users column's actual collation, including case/accent rules.
        $stmt = $db->query("SELECT collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='username'");
        $collation = $stmt->fetchColumn();
        if (!is_string($collation) || !preg_match('/^utf8mb4_[a-z0-9_]+$/D', $collation)) {
            throw new RuntimeException('Unsupported identity collation');
        }
        $stmt = $db->prepare('SELECT CONVERT(? USING utf8mb4) COLLATE '.$collation.' = _utf8mb4\'ecollab_ai\' COLLATE '.$collation);
        $stmt->execute([$username]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public static function assertHuman(PDO $db, int $id): void
    {
        if (!self::humanId($db, $id)) {
            throw new RuntimeException('This account cannot authenticate.', 403);
        }
    }
}
