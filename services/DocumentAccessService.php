<?php
declare(strict_types=1);
require_once __DIR__ . '/CoworkspaceService.php';

/** The same authorization boundary is used for launch, downloads, AI and every WOPI request. */
final class DocumentAccessService
{
    public static function permission(bool $owner, ?string $explicit, string $visibility, string $public): ?string
    {
        if ($owner) return 'edit';
        if ($explicit !== null) return in_array($explicit, ['edit', 'comment', 'view'], true) ? $explicit : 'view';
        return $visibility === 'public' ? (in_array($public, ['edit', 'comment', 'view'], true) ? $public : 'view') : null;
    }

    public static function get(PDO $db, int $id, int $uid): array
    {
        $s = $db->prepare('SELECT * FROM collab_documents WHERE id=?');
        $s->execute([$id]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        if (!$d) throw new RuntimeException('Document not found.', 404);
        $w = CoworkspaceService::get($db, (int)$d['workspace_id'], $uid);
        $s = $db->prepare('SELECT 1 FROM server_members sm JOIN users u ON u.id=sm.user_id WHERE sm.server_id=? AND sm.user_id=? AND sm.status="active" AND u.status IN ("active","offline","idle")');
        $s->execute([(int)$w['server_id'], $uid]);
        if (!$s->fetchColumn()) throw new RuntimeException('Document access denied.', 403);
        $s = $db->prepare('SELECT permission FROM collab_resource_permissions WHERE resource_type="document" AND resource_id=? AND workspace_id=? AND user_id=?');
        $s->execute([$id, (int)$w['id'], $uid]);
        $p = $s->fetchColumn();
        $owner = (int)$d['created_by'] === $uid || (int)$w['host_id'] === $uid;
        $permission = self::permission($owner, $p === false ? null : (string)$p, (string)$d['visibility'], (string)$d['public_permission']);
        if ($permission === null) throw new RuntimeException('Document access denied.', 403);
        return ['document' => $d, 'workspace' => $w, 'permission' => $permission, 'owner' => $owner];
    }

    public static function path(array $d): string
    {
        $base = realpath(ROOT_PATH . '/uploads/collab-docs');
        $path = realpath(ROOT_PATH . '/' . ltrim((string)$d['storage_path'], '/'));
        if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException('Document file unavailable.', 404);
        }
        return $path;
    }
}
