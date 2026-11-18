<?php
declare(strict_types=1);

/** Email-based badge classification, independent of authorization roles. */
final class AccountType
{
    public const LABELS = ['student' => 'Student', 'professor' => 'Professor', 'personal' => 'Personal'];

    private const NAME = '[a-z]+([._-][a-z]+)*';
    private const STUDENT = '^' . self::NAME . '[0-9]{4}[a-z]{3}@student[.]fatima[.]edu[.]ph$';
    private const PROFESSOR = '^' . self::NAME . '@fatima[.]edu[.]ph$';

    public static function classify(string $email, bool $verified): string
    {
        if (!$verified) return 'personal';
        $email = strtolower(trim($email));
        if (preg_match('~' . self::STUDENT . '~D', $email)) return 'student';
        if (preg_match('~' . self::PROFESSOR . '~D', $email)) return 'professor';
        return 'personal';
    }

    /** All account directory queries alias users as u. Only return the badge type. */
    public static function sql(): string
    {
        $student = self::STUDENT;
        $professor = self::PROFESSOR;
        return "CASE
            WHEN u.email_verified = 1 AND LOWER(TRIM(u.email)) REGEXP '$student' THEN 'student'
            WHEN u.email_verified = 1 AND LOWER(TRIM(u.email)) REGEXP '$professor' THEN 'professor'
            ELSE 'personal' END";
    }

    public static function forUser(PDO $db, int $userId): string
    {
        $stmt = $db->prepare('SELECT email,email_verified FROM users WHERE id=? AND deleted_at IS NULL');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ? self::classify((string)$user['email'], (bool)$user['email_verified']) : 'personal';
    }
}
