<?php
declare(strict_types=1);

/** Small tus 1.0 creation/core/termination store. Partial files never live in the web root. */
final class ChatTusStorage
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const CHUNK_BYTES = 1024 * 1024;
    public const TTL = 86400;
    public const MAX_PENDING_BYTES = 512 * 1024 * 1024;
    private string $root;
    private string $destination;
    private string $baseUrl;

    public function __construct(string $root, string $destination, string $baseUrl)
    {
        $this->root = rtrim($root, '/');
        $this->destination = rtrim($destination, '/');
        $this->baseUrl = rtrim($baseUrl, '/');
        foreach ([$this->root, $this->destination] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Upload storage unavailable.', 503);
        }
    }

    public function create(int $uid, string $kind, int $target, int $length, string $name): string
    {
        if ($length < 1 || $length > self::MAX_BYTES) throw new RuntimeException('File must be between 1 byte and 20 MB.', 413);
        if (!in_array($kind, ['channel','dm','group'], true) || $target < 1) throw new RuntimeException('Invalid upload target.', 400);
        // Serialize quotas across creators, including the shared 512 MB reservation cap.
        $lock = fopen($this->root . '/quota.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Upload storage unavailable.', 503);
        try {
            $count = 0;
            $reserved = 0;
            foreach (glob($this->root . '/*.json') ?: [] as $path) {
                $meta = json_decode((string)file_get_contents($path), true);
                if (($meta['expires'] ?? 0) > time() && empty($meta['result'])) {
                    $reserved += $meta['length'] ?? 0;
                    if (($meta['uid'] ?? 0) === $uid) $count++;
                }
            }
            if ($count >= 10) throw new RuntimeException('Too many unfinished uploads. Cancel one or retry later.', 429);
            if ($reserved + $length > self::MAX_PENDING_BYTES) throw new RuntimeException('Upload capacity is busy. Retry later.', 429);
            $id = bin2hex(random_bytes(24));
            $name = substr(preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name)), 0, 180);
            $meta = ['uid'=>$uid,'kind'=>$kind,'target'=>$target,'length'=>$length,'name'=>$name ?: 'attachment','expires'=>time()+self::TTL];
            if (file_put_contents($this->root . '/' . $id . '.part', '') === false) throw new RuntimeException('Upload storage unavailable.', 503);
            $this->save($id, $meta);
            return $id;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function save(string $id, array $meta): void
    {
        $tmp = $this->root . '/' . $id . '.tmp';
        if (file_put_contents($tmp, json_encode($meta, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $this->root . '/' . $id . '.json')) {
            throw new RuntimeException('Upload storage unavailable.', 503);
        }
    }

    /** Every operation is serialized and reauthorizes the stored target, not request-supplied metadata. */
    public function withUpload(string $id, int $uid, callable $authorize, callable $operation)
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $id)) throw new RuntimeException('Upload not found.', 404);
        if (!is_file($this->root . '/' . $id . '.json')) throw new RuntimeException('Upload not found.', 404);
        $lock = fopen($this->root . '/' . $id . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Upload storage unavailable.', 503);
        try {
            $meta = json_decode((string)@file_get_contents($this->root . '/' . $id . '.json'), true);
            if (!$meta || $meta['uid'] !== $uid) throw new RuntimeException('Upload not found.', 404);
            if ($meta['expires'] < time()) throw new RuntimeException('Upload expired. Select the file again.', 410);
            $authorize($meta['kind'], (int)$meta['target']);
            return $operation($meta, $id);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function offset(array $meta, string $id): int
    {
        if (!empty($meta['result'])) return $meta['length'];
        clearstatcache(true, $this->root . '/' . $id . '.part');
        $size = @filesize($this->root . '/' . $id . '.part');
        if ($size === false) throw new RuntimeException('Upload data unavailable.', 410);
        return $size;
    }

    public function append(array $meta, string $id, int $offset, string $chunk): int
    {
        $current = $this->offset($meta, $id);
        if ($offset !== $current) throw new RuntimeException('Upload offset mismatch.', 409);
        if (!empty($meta['result'])) return $current;
        if (strlen($chunk) > self::CHUNK_BYTES || $current + strlen($chunk) > $meta['length']) throw new RuntimeException('Upload chunk exceeds limit.', 413);
        $path = $this->root . '/' . $id . '.part';
        $file = fopen($path, 'r+b');
        if (!$file || fseek($file, $current) !== 0) throw new RuntimeException('Upload write failed.', 503);
        try {
            $written = 0;
            while ($written < strlen($chunk)) {
                $n = fwrite($file, substr($chunk, $written));
                if (!$n) { ftruncate($file, $current); throw new RuntimeException('Upload write failed.', 503); }
                $written += $n;
            }
            fflush($file);
        } finally { fclose($file); }
        return $current + strlen($chunk);
    }

    /** GET finalizes idempotently after the full file has arrived. */
    public function complete(array $meta, string $id): array
    {
        if (!empty($meta['result'])) return $meta['result'];
        if ($this->offset($meta, $id) !== $meta['length']) throw new RuntimeException('Upload is incomplete.', 409);
        $part = $this->root . '/' . $id . '.part';
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($part);
        $extensions = [
            'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp',
            'application/pdf'=>'pdf','text/plain'=>'txt','text/csv'=>'csv','application/zip'=>'zip',
            'application/msword'=>'doc','application/vnd.ms-excel'=>'xls','application/vnd.ms-powerpoint'=>'ppt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
            'video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov',
            'audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/wave'=>'wav',
            'audio/ogg'=>'ogg','audio/webm'=>'webm','audio/mp4'=>'m4a','audio/x-m4a'=>'m4a',
        ];
        if (!isset($extensions[$mime]) || ($meta['kind'] !== 'channel' && (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')))) {
            $this->terminate($id); // A rejected file must not consume quota until expiry.
            throw new RuntimeException('File type not allowed.', 415);
        }
        // Never preserve a client extension such as .php, .html or .svg.
        $name = 'tus_' . $id . '.' . $extensions[$mime];
        $dest = $this->destination . '/' . $name;
        // Copy rather than move so a crash before the metadata commit can be retried.
        if (!copy($part, $dest) || !chmod($dest, 0640)) throw new RuntimeException('Upload finalization failed.', 503);
        $result = ['success'=>true,'file_name'=>$meta['name'],'file_path'=>'uploads/'.$name,
            'file_size'=>$meta['length'],'mime_type'=>$mime,'url'=>$this->baseUrl.'/uploads/'.$name];
        $meta['result'] = $result;
        $this->save($id, $meta);
        unlink($part);
        return $result;
    }

    public function terminate(string $id): void
    {
        foreach (['.part','.json','.tmp'] as $suffix) if (is_file($this->root.'/'.$id.$suffix)) unlink($this->root.'/'.$id.$suffix);
        // Do not delete published attachments; a message may already reference them.
    }

    public function cleanup(): int
    {
        $count = 0;
        foreach (glob($this->root.'/*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            $lock = fopen($this->root.'/'.$id.'.lock', 'c');
            if (!$lock) continue;
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                $meta = json_decode((string)@file_get_contents($path), true);
                if ($meta && $meta['expires'] < time()) { $this->terminate($id); $count++; }
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
        return $count;
    }
}
