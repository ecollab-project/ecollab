<?php
declare(strict_types=1);

final class ThreadAttachmentService
{
    /** Only IDs from the caller's already-selected page; per-thread ID ordering matches detail reads. */
    public static function forThreads(PDO $db, array $threadIds): array
    {
        if (!$threadIds) return [];
        if (count($threadIds) > 50) throw new InvalidArgumentException('Attachment page exceeds endpoint bound');
        $in = implode(',', array_fill(0, count($threadIds), '?'));
        $stmt = $db->prepare("SELECT id, thread_id, reply_id, file_url, file_name, mime_type, file_size, created_by, created_at FROM thread_attachments WHERE thread_id IN ($in) ORDER BY thread_id ASC,id ASC");
        $stmt->execute($threadIds);
        $byThread = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byThread[(int)$row['thread_id']][] = $row;
        return $byThread;
    }
}
