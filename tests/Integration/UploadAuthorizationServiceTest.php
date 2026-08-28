<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class UploadAuthorizationServiceTest extends TestCase
{
    private \PDO $db;
    private \UploadAuthorizationService $service;

    protected function setUp(): void
    {
        $this->db = \Database::getInstance();
        $this->service = new \UploadAuthorizationService($this->db);
    }

    public function testAuthorizedServerMemberCanUploadToAuthorizedChannel(): void
    {
        $fixture = $this->findRegularMemberWithUploadableChannel();
        if ($fixture === null) {
            $this->markTestSkipped('No suitable non-privileged server/channel fixture exists in ecollab_test.');
        }

        $result = $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$fixture['server_id'],
            (int)$fixture['channel_id']
        );

        $this->assertSame((int)$fixture['server_id'], $result['server_id']);
        $this->assertSame((int)$fixture['channel_id'], $result['channel_id']);
    }

    public function testOutsiderIsBlockedFromAnotherServer(): void
    {
        $fixture = $this->findRegularMemberWithUploadableChannel();
        if ($fixture === null) {
            $this->markTestSkipped('No suitable non-privileged server/channel fixture exists in ecollab_test.');
        }

        $outsiderStmt = $this->db->prepare("
            SELECT u.id
            FROM users u
            WHERE u.role NOT IN ('admin', 'super_admin', 'moderator')
              AND NOT EXISTS (
                  SELECT 1 FROM server_members sm
                  WHERE sm.server_id = :sid AND sm.user_id = u.id
              )
            LIMIT 1
        ");
        $outsiderStmt->execute([':sid' => (int)$fixture['server_id']]);
        $outsiderId = $outsiderStmt->fetchColumn();
        if (!$outsiderId) {
            $this->markTestSkipped('No outsider fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$outsiderId,
            (int)$fixture['server_id'],
            (int)$fixture['channel_id']
        );
    }

    public function testGlobalModeratorWithoutServerMembershipIsBlocked(): void
    {
        $fixture = $this->findUploadableChannelWithGlobalRoleOutsider();
        if ($fixture === null) {
            $this->markTestSkipped('No global-role outsider fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$fixture['server_id'],
            (int)$fixture['channel_id']
        );
    }

    public function testGlobalAdminWithoutServerMembershipIsBlocked(): void
    {
        $fixture = $this->findUploadableChannelWithGlobalRoleOutsider('admin');
        if ($fixture === null) {
            $this->markTestSkipped('No global-admin outsider fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$fixture['server_id'],
            (int)$fixture['channel_id']
        );
    }

    public function testGlobalSuperAdminWithoutServerMembershipIsBlocked(): void
    {
        $fixture = $this->findUploadableChannelWithGlobalRoleOutsider('super_admin');
        if ($fixture === null) {
            $this->markTestSkipped('No global-super-admin outsider fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$fixture['server_id'],
            (int)$fixture['channel_id']
        );
    }

    public function testGlobalModeratorInServerAIsBlockedFromServerB(): void
    {
        $stmt = $this->db->query("
            SELECT u.id AS user_id, target.id AS server_id, c.id AS channel_id
            FROM users u
            CROSS JOIN servers target
            JOIN channels c ON c.server_id = target.id
            WHERE u.role = 'moderator'
              AND c.is_locked = 0
              AND c.type IN ('text', 'study_room')
              AND NOT EXISTS (
                  SELECT 1 FROM server_members sm
                  WHERE sm.server_id = target.id AND sm.user_id = u.id
              )
            LIMIT 1
        ");
        $fixture = $stmt->fetch();
        if (!$fixture) {
            $this->markTestSkipped('No moderator/non-member cross-server fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$fixture['server_id'],
            (int)$fixture['channel_id']
        );
    }

    public function testGlobalRoleDoesNotBypassPrivateChannelMembership(): void
    {
        $stmt = $this->db->query("
            SELECT u.id AS user_id, c.server_id, c.id AS channel_id
            FROM users u
            JOIN channels c ON c.is_private = 1
            WHERE u.role IN ('admin', 'super_admin', 'moderator')
              AND c.is_locked = 0
              AND c.type IN ('text', 'study_room')
              AND EXISTS (
                  SELECT 1 FROM server_members sm
                  WHERE sm.server_id = c.server_id AND sm.user_id = u.id
              )
              AND NOT EXISTS (
                  SELECT 1 FROM channel_members cm
                  WHERE cm.channel_id = c.id AND cm.user_id = u.id
              )
              AND NOT EXISTS (
                  SELECT 1 FROM server_members sm2
                  WHERE sm2.server_id = c.server_id
                    AND sm2.user_id = u.id
                    AND sm2.server_role IN ('owner', 'admin', 'moderator')
              )
              AND c.created_by <> u.id
            LIMIT 1
        ");
        $fixture = $stmt->fetch();
        if (!$fixture) {
            $this->markTestSkipped('No global-role/private-channel non-member fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$fixture['server_id'],
            (int)$fixture['channel_id']
        );
    }

    public function testForgedServerIdIsBlocked(): void
    {
        $fixture = $this->findRegularMemberWithUploadableChannel();
        if ($fixture === null) {
            $this->markTestSkipped('No suitable non-privileged server/channel fixture exists in ecollab_test.');
        }

        $otherServerStmt = $this->db->prepare("
            SELECT s.id
            FROM servers s
            WHERE s.id <> :sid
            LIMIT 1
        ");
        $otherServerStmt->execute([':sid' => (int)$fixture['server_id']]);
        $otherServerId = $otherServerStmt->fetchColumn();
        if (!$otherServerId) {
            $this->markTestSkipped('No second server fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$otherServerId,
            (int)$fixture['channel_id']
        );
    }

    public function testForgedChannelIdIsBlocked(): void
    {
        $fixture = $this->findRegularMemberWithUploadableChannel();
        if ($fixture === null) {
            $this->markTestSkipped('No suitable non-privileged server/channel fixture exists in ecollab_test.');
        }

        $otherChannelStmt = $this->db->prepare("
            SELECT c.id
            FROM channels c
            WHERE c.server_id <> :sid
              AND c.type IN ('text', 'study_room')
              AND c.is_locked = 0
            LIMIT 1
        ");
        $otherChannelStmt->execute([':sid' => (int)$fixture['server_id']]);
        $otherChannelId = $otherChannelStmt->fetchColumn();
        if (!$otherChannelId) {
            $this->markTestSkipped('No second-server channel fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$fixture['server_id'],
            (int)$otherChannelId
        );
    }

    public function testServerAndChannelMismatchIsBlocked(): void
    {
        $fixture = $this->findRegularMemberWithUploadableChannel();
        if ($fixture === null) {
            $this->markTestSkipped('No suitable non-privileged server/channel fixture exists in ecollab_test.');
        }

        $otherServerStmt = $this->db->prepare("SELECT id FROM servers WHERE id <> :sid LIMIT 1");
        $otherServerStmt->execute([':sid' => (int)$fixture['server_id']]);
        $otherServerId = $otherServerStmt->fetchColumn();
        if (!$otherServerId) {
            $this->markTestSkipped('No second server fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$otherServerId,
            (int)$fixture['channel_id']
        );
    }

    public function testPrivateChannelWithoutChannelMembershipIsBlocked(): void
    {
        $stmt = $this->db->query("
            SELECT sm.user_id, sm.server_id, c.id AS channel_id
            FROM server_members sm
            JOIN channels c ON c.server_id = sm.server_id
            LEFT JOIN channel_members cm
              ON cm.channel_id = c.id AND cm.user_id = sm.user_id
            WHERE c.is_private = 1
              AND c.is_locked = 0
              AND sm.server_role NOT IN ('owner', 'admin', 'moderator')
              AND c.created_by <> sm.user_id
              AND cm.user_id IS NULL
              AND c.type IN ('text', 'study_room')
            LIMIT 1
        ");
        $fixture = $stmt->fetch();
        if (!$fixture) {
            $this->markTestSkipped('No private-channel outsider fixture exists in ecollab_test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->authorize(
            (int)$fixture['user_id'],
            (int)$fixture['server_id'],
            (int)$fixture['channel_id']
        );
    }

    private function findUploadableChannelWithGlobalRoleOutsider(?string $role = null): ?array
    {
        $roleClause = $role === null
            ? "u.role IN ('admin', 'super_admin', 'moderator')"
            : 'u.role = :role';

        $sql = "
            SELECT u.id AS user_id, c.server_id, c.id AS channel_id
            FROM users u
            JOIN channels c
              ON c.is_locked = 0
             AND c.type IN ('text', 'study_room')
            WHERE {$roleClause}
              AND NOT EXISTS (
                  SELECT 1 FROM server_members sm
                  WHERE sm.server_id = c.server_id AND sm.user_id = u.id
              )
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        if ($role !== null) {
            $stmt->execute([':role' => $role]);
        } else {
            $stmt->execute();
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function findRegularMemberWithUploadableChannel(): ?array
    {
        $stmt = $this->db->query("
            SELECT sm.user_id, sm.server_id, c.id AS channel_id
            FROM server_members sm
            JOIN users u ON u.id = sm.user_id
            JOIN channels c ON c.server_id = sm.server_id
            WHERE u.role NOT IN ('admin', 'super_admin', 'moderator')
              AND c.is_locked = 0
              AND c.type IN ('text', 'study_room')
              AND (
                  c.is_private = 0
                  OR EXISTS (
                      SELECT 1 FROM channel_members cm
                      WHERE cm.channel_id = c.id AND cm.user_id = sm.user_id
                  )
                  OR sm.server_role IN ('owner', 'admin', 'moderator')
                  OR c.created_by = sm.user_id
              )
            LIMIT 1
        ");

        $row = $stmt->fetch();
        return $row ?: null;
    }
}
