<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/database/config/db.php';
require_once dirname(__DIR__) . '/services/TemporaryVoiceService.php';

final class JarredActionService
{
    public const IMMEDIATE = 0;
    public const CONFIRM = 1;
    public const EXPLICIT_CONFIRM = 2;

    private PDO $db;
    private TemporaryVoiceService $temporaryVoice;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->temporaryVoice = new TemporaryVoiceService();
    }

    public function capabilities(): array
    {
        return [
            'read_and_navigate' => ['level'=>self::IMMEDIATE],
            'temporary_voice' => ['level'=>self::CONFIRM],
            'coworkspace_create' => ['level'=>self::CONFIRM],
            'social_actions' => ['level'=>self::CONFIRM],
            'important_changes' => ['level'=>self::EXPLICIT_CONFIRM],
        ];
    }

    public function handleMessage(int $userId, int $conversationId, string $text, ?int $activeServerId, array $surface = []): ?array
    {
        // Enabled write authority. PHP owns its state, validation and execution.
        $voice = $this->temporaryVoice->handleJarredMessage($userId, $conversationId, $text, $activeServerId);
        if ($voice !== null) return $voice;

        // Safe navigation for the currently authorized surface.
        if (preg_match('/\b(?:open|take me to|go to|show me)\b/i', $text)) {
            return $this->currentSurfaceNavigation($userId, $activeServerId, $surface, $text);
        }
        return null;
    }

    private function currentSurfaceNavigation(int $userId, ?int $serverId, array $surface, string $text): ?array
    {
        if (!$serverId || !$this->canAccessServer($userId, $serverId)) return null;

        $documentId=(int)($surface['document_id']??0);
        if ($documentId>0 && preg_match('/\b(?:document|doc|file)\b/i',$text)) {
            $s=$this->db->prepare("SELECT d.id,d.title,d.workspace_id FROM collab_documents d JOIN collab_workspaces w ON w.id=d.workspace_id JOIN channels c ON c.id=w.channel_id JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid WHERE d.id=:did AND c.server_id=:sid LIMIT 1");
            $s->execute([':uid'=>$userId,':did'=>$documentId,':sid'=>$serverId]);
            if($r=$s->fetch(PDO::FETCH_ASSOC)) return ['reply'=>'Opening '.($r['title']?:'the document').'.','action'=>['type'=>'open_document','document_id'=>(int)$r['id'],'workspace_id'=>(int)$r['workspace_id']]];
        }

        $whiteboardId=(int)($surface['whiteboard_id']??0);
        if ($whiteboardId>0 && preg_match('/\b(?:whiteboard|board)\b/i',$text)) {
            $s=$this->db->prepare("SELECT b.id,b.title,b.workspace_id FROM collab_whiteboards b JOIN collab_workspaces w ON w.id=b.workspace_id JOIN channels c ON c.id=w.channel_id JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid WHERE b.id=:bid AND c.server_id=:sid LIMIT 1");
            $s->execute([':uid'=>$userId,':bid'=>$whiteboardId,':sid'=>$serverId]);
            if($r=$s->fetch(PDO::FETCH_ASSOC)) return ['reply'=>'Opening '.($r['title']?:'the whiteboard').'.','action'=>['type'=>'open_whiteboard','whiteboard_id'=>(int)$r['id'],'workspace_id'=>(int)$r['workspace_id']]];
        }

        $channelId=(int)($surface['channel_id']??0);
        if ($channelId>0 && preg_match('/\bchannel\b/i',$text)) {
            $s=$this->db->prepare("SELECT c.id,c.slug,c.name,c.type FROM channels c JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid WHERE c.id=:cid AND c.server_id=:sid AND (c.is_private=0 OR c.created_by=:uid2 OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=:uid3)) LIMIT 1");
            $s->execute([':uid'=>$userId,':cid'=>$channelId,':sid'=>$serverId,':uid2'=>$userId,':uid3'=>$userId]);
            if($r=$s->fetch(PDO::FETCH_ASSOC)) return ['reply'=>'Opening #'.$r['name'].'.','action'=>['type'=>'open_channel','channel_id'=>(int)$r['id'],'channel_slug'=>$r['slug'],'channel_type'=>$r['type']]];
        }
        return null;
    }

    private function canAccessServer(int $userId,int $serverId): bool
    {
        $s=$this->db->prepare("SELECT 1 FROM server_members WHERE server_id=? AND user_id=? LIMIT 1");
        $s->execute([$serverId,$userId]);
        return (bool)$s->fetchColumn();
    }
}
