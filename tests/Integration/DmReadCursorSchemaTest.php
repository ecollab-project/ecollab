<?php
declare(strict_types=1);
namespace Tests\Integration;
use PHPUnit\Framework\TestCase;
final class DmReadCursorSchemaTest extends TestCase
{
    public function testDmAndGroupCursorsRemainDistinctAndIdempotent(): void
    {
        $db=\Database::getInstance();$db->beginTransaction();
        try {
            $user=2147483000;
            $q=$db->prepare('INSERT INTO dm_reads(user_id,conversation_id,group_id,last_read_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_read_at=VALUES(last_read_at)');
            $q->execute([$user,101,null,'2026-11-02 01:00:00']);
            $q->execute([$user,null,101,'2026-11-02 02:00:00']);
            $q->execute([$user,null,102,'2026-11-02 03:00:00']);
            $q->execute([$user,101,null,'2026-11-02 04:00:00']);
            $q->execute([$user,null,101,'2026-11-02 05:00:00']);
            $read=$db->prepare('SELECT conversation_id,group_id,last_read_at FROM dm_reads WHERE user_id=? ORDER BY conversation_id,group_id');$read->execute([$user]);$rows=$read->fetchAll(\PDO::FETCH_ASSOC);
            self::assertCount(3,$rows);
            self::assertSame('2026-11-02 05:00:00',$rows[0]['last_read_at']);
            self::assertSame('2026-11-02 03:00:00',$rows[1]['last_read_at']);
            self::assertSame('2026-11-02 04:00:00',$rows[2]['last_read_at']);
        } finally {if($db->inTransaction())$db->rollBack();}
    }
}
