<?php
declare(strict_types=1);

final class BookRecommendationEvents
{
    public static function enabled(): bool
    {
        return filter_var($_ENV['BOOK_RECOMMENDATION_EVENTS_ENABLED'] ?? getenv('BOOK_RECOMMENDATION_EVENTS_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN);
    }

    public static function allowed(PDO $db, int $uid): bool
    {
        if (!self::enabled()) return false;
        $q = $db->prepare("SELECT 1 FROM users u LEFT JOIN user_settings s ON s.user_id=u.id
            WHERE u.id=? AND u.deleted_at IS NULL AND u.status NOT IN ('banned','suspended','deactivated')
            AND COALESCE(u.is_system,0)=0 AND COALESCE(s.ai_matching,1)=1");
        $q->execute([$uid]);
        return (bool)$q->fetchColumn();
    }

    /** Only books actually returned for personal recommendations can be recorded. */
    public static function offer(PDO $db, int $uid, array &$books): bool
    {
        if (!self::allowed($db, $uid)) return false;
        $offered = $_SESSION['recommendation_books'][$uid] ?? [];
        $offered = array_filter($offered, static fn($r) => ($r['expires'] ?? 0) > time());
        foreach ($books as &$book) {
            $key = substr((string)($book['url'] ?? ''), strlen('https://openlibrary.org'));
            if (!preg_match('~^/works/OL[0-9]+W$~D', $key)) continue;
            $features = [];
            foreach (array_slice($book['subjects'] ?? [], 0, 6) as $subject) {
                $subject = mb_substr(mb_strtolower(trim((string)$subject)), 0, 100);
                if ($subject !== '') $features[] = 'subject:' . $subject;
            }
            $book['work_key'] = $key;
            $offered[$key] = ['expires'=>time()+1800, 'features'=>array_values(array_unique($features))];
        }
        unset($book);
        $_SESSION['recommendation_books'][$uid] = array_slice($offered, -200, null, true);
        return true;
    }

    public static function record(PDO $db, int $uid, string $key, string $type): void
    {
        if (!self::allowed($db, $uid)) throw new InvalidArgumentException('Collection disabled');
        if (!in_array($type, ['click','useful'], true) || !preg_match('~^/works/OL[0-9]+W$~D', $key)) {
            throw new InvalidArgumentException('Invalid event');
        }
        $offered = $_SESSION['recommendation_books'][$uid][$key] ?? null;
        if (!$offered || $offered['expires'] <= time()) throw new InvalidArgumentException('Reload recommendations first');
        $db->beginTransaction();
        try {
            $q = $db->prepare('INSERT IGNORE INTO recommendation_books (work_key,features_json) VALUES (?,?)');
            $q->execute([$key,json_encode($offered['features'], JSON_THROW_ON_ERROR)]);
            $q = $db->prepare('INSERT IGNORE INTO recommendation_book_events (user_id,work_key,event_type,event_day) VALUES (?,?,?,UTC_DATE())');
            $q->execute([$uid,$key,$type]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
