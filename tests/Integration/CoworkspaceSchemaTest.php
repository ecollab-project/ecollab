<?php
declare(strict_types=1);
namespace Tests\Integration;
use PHPUnit\Framework\TestCase;
final class CoworkspaceSchemaTest extends TestCase
{
    public function testActiveUniquenessArchivingAndServerDeleteCascade(): void
    {
        $db = \Database::getInstance();
        $db->beginTransaction();
        try {
            $owner = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
            $db->prepare('INSERT INTO servers(owner_id,name,slug) VALUES(?,?,?)')->execute([$owner,'Schema fixture','schema_' . uniqid()]);
            $server = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO channels(server_id,name,slug) VALUES(?,?,?)')->execute([$server,'Fixture','fixture']);
            $channel = (int)$db->lastInsertId();
            $insert = $db->prepare('INSERT INTO collab_workspaces(channel_id,server_id,name,host_id) VALUES(?,?,?,?)');
            $insert->execute([$channel,$server,'First',$owner]);
            $first = (int)$db->lastInsertId();
            try {
                $insert->execute([$channel,$server,'Duplicate',$owner]);
                self::fail('Two active workspaces must not share one server');
            } catch (\PDOException $e) { self::assertSame('23000', $e->getCode()); }
            $db->prepare('UPDATE collab_workspaces SET archived=1 WHERE id=?')->execute([$first]);
            $insert->execute([$channel,$server,'Replacement',$owner]);
            self::assertSame(2,(int)$db->query('SELECT COUNT(*) FROM collab_workspaces WHERE server_id='.$server)->fetchColumn());
            self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM collab_workspaces WHERE active_server_id='.$server)->fetchColumn());
            $db->prepare('DELETE FROM servers WHERE id=?')->execute([$server]);
            self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM collab_workspaces WHERE server_id='.$server)->fetchColumn());
        } finally { if ($db->inTransaction()) $db->rollBack(); }
    }
}
