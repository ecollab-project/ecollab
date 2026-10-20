<?php
declare(strict_types=1);
require_once __DIR__ . '/DocumentAccessService.php';

final class CollaboraService
{
    public static function enabled(): bool { return env('DOCUMENT_EDITOR', 'onlyoffice') === 'collabora'; }

    public static function origin(string $url): string
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            throw new RuntimeException('Configure an HTTPS Collabora and WOPI URL.', 503);
        }
        return 'https://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public static function timestamp(array $d): string
    {
        // Disambiguate saves within the same database timestamp second.
        $time = new DateTimeImmutable($d['updated_at'], new DateTimeZone('UTC'));
        return $time->format('Y-m-d\TH:i:s') . sprintf('.%06dZ', (int)$d['version'] % 1000000);
    }

    public static function sameTimestamp(string $received, string $expected): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})$/D', $received)) return false;
        try { return (new DateTimeImmutable($received))->format('U.u') === (new DateTimeImmutable($expected))->format('U.u'); }
        catch (Throwable) { return false; }
    }

    public static function issue(PDO $db, int $id, int $uid): array
    {
        $a = DocumentAccessService::get($db, $id, $uid);
        $token = bin2hex(random_bytes(32));
        $expiry = time() + 8 * 3600;
        $db->prepare('DELETE FROM collab_wopi_tokens WHERE expires_at < UTC_TIMESTAMP() LIMIT 1000')->execute();
        $db->prepare('INSERT INTO collab_wopi_tokens(token_hash,document_id,user_id,can_write,expires_at) VALUES(?,?,?,?,?)')
            ->execute([hash('sha256', $token), $id, $uid, (int)($a['permission'] === 'edit'), gmdate('Y-m-d H:i:s', $expiry)]);
        return ['token' => $token, 'ttl' => $expiry * 1000, 'access' => $a];
    }

    public static function authorize(PDO $db, int $id, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('Invalid access token.', 401);
        $s = $db->prepare('SELECT user_id,can_write FROM collab_wopi_tokens WHERE token_hash=? AND document_id=? AND expires_at>UTC_TIMESTAMP()');
        $s->execute([hash('sha256', $token), $id]);
        $t = $s->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new RuntimeException('Expired or invalid access token.', 401);
        $a = DocumentAccessService::get($db, $id, (int)$t['user_id']);
        $a['uid'] = (int)$t['user_id'];
        $a['write'] = !empty($t['can_write']) && $a['permission'] === 'edit';
        return $a;
    }

    public static function editorUrl(string $extension, bool $write, int $id): string
    {
        $base = rtrim((string)env('COLLABORA_URL', ''), '/');
        $origin = self::origin($base);
        $host = rtrim((string)env('COLLABORA_WOPI_URL', ''), '/');
        self::origin($host);
        if (!in_array($extension, ['docx', 'xlsx', 'pptx'], true)) throw new RuntimeException('Unsupported format.', 400);
        $xml = '';
        $ch = curl_init($base . '/hosting/discovery');
        curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$xml): int {
                if (strlen($xml) + strlen($chunk) > 2097152) return 0;
                $xml .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($ok === false || $status !== 200 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('Collabora discovery unavailable.', 503);
        }
        $previous = libxml_use_internal_errors(true);
        $discovery = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if ($discovery === false) throw new RuntimeException('Invalid Collabora discovery.', 503);
        $action = $write ? 'edit' : 'view';
        foreach ($discovery->xpath('//action') ?: [] as $entry) {
            if ((string)$entry['ext'] !== $extension || (string)$entry['name'] !== $action) continue;
            $url = preg_replace('/<[^>]*>/', '', (string)$entry['urlsrc']);
            if (self::origin($url) !== $origin) throw new RuntimeException('Untrusted discovery origin.', 503);
            return $url . (str_contains($url, '?') ? '&' : '?') . 'WOPISrc=' . rawurlencode($host . '/API/collaboration/wopi/files/' . $id);
        }
        throw new RuntimeException('Collabora does not advertise this format and permission.', 503);
    }

    /** Save immutable file first, then atomically publish its path and archive the old version. */
    public static function save(PDO $db, int $id, string $token, string $temporary, ?string $timestamp): array
    {
        $published = false; $destination = null;
        $db->beginTransaction();
        try {
            $s = $db->prepare('SELECT id FROM collab_documents WHERE id=? FOR UPDATE'); $s->execute([$id]);
            $a = self::authorize($db, $id, $token);
            if (!$a['write']) throw new RuntimeException('Read-only document.', 403);
            $d = $a['document'];
            if ($timestamp !== null && !self::sameTimestamp($timestamp, self::timestamp($d))) throw new RuntimeException('Document changed in storage.', 409);
            $old = DocumentAccessService::path($d);
            $relative = 'uploads/collab-docs/' . bin2hex(random_bytes(24)) . '.' . $d['file_type'];
            $destination = ROOT_PATH . '/' . $relative;
            if (!rename($temporary, $destination)) throw new RuntimeException('Could not save document.', 500);
            chmod($destination, 0640);
            $db->prepare('INSERT IGNORE INTO collab_document_versions(document_id,version,storage_path,created_by) VALUES(?,?,?,?)')
                ->execute([$id, $d['version'], $d['storage_path'], $d['updated_by'] ?: $d['created_by']]);
            $db->prepare('UPDATE collab_documents SET storage_path=?,version=version+1,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([$relative, $a['uid'], $id]);
            $s = $db->prepare('SELECT version,updated_at FROM collab_documents WHERE id=?');
            $s->execute([$id]); $d = $s->fetch(PDO::FETCH_ASSOC);
            $db->commit(); $published = true;
            return ['LastModifiedTime' => self::timestamp($d)];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        } finally {
            if (!$published && $destination !== null && is_file($destination)) unlink($destination);
        }
    }
}
