<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';
require_once dirname(__DIR__) . '/services/ChannelService.php';
require_once dirname(__DIR__) . '/services/PeerMatchingService.php';

/**
 * JarredTools
 *
 * Permission-scoped, read-only application context for Jarred.
 * Jarred never receives database credentials and never executes model-written SQL.
 */
final class JarredTools
{
    private PDO $db;
    private ChannelService $channels;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->channels = new ChannelService();
    }

    public function contextForPrompt(int $requesterId, string $prompt, ?int $activeServerId = null, array $surface = []): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') return '';

        $serverId = ($activeServerId && $this->canAccessServer($requesterId, $activeServerId))
            ? $activeServerId
            : $this->resolveServerId($requesterId, $prompt);

        $parts = [];
        $parts[] = 'ECOLLAB REQUEST CONTEXT: ' . json_encode([
            'server_id' => $serverId,
            'surface' => $surface['surface'] ?? 'dm',
            'channel_id' => isset($surface['channel_id']) ? (int)$surface['channel_id'] : null,
            'voice_channel_id' => isset($surface['voice_channel_id']) ? (int)$surface['voice_channel_id'] : null,
            'workspace_id' => isset($surface['workspace_id']) ? (int)$surface['workspace_id'] : null,
            'document_id' => isset($surface['document_id']) ? (int)$surface['document_id'] : null,
            'whiteboard_id' => isset($surface['whiteboard_id']) ? (int)$surface['whiteboard_id'] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (preg_match('/\b(online|active|who(?:\'s| is) (?:here|online|active)|members? (?:online|active)|voice|call|connected)\b/i', $prompt)) {
            if ($serverId) {
                $parts[] = 'LIVE ECOLLAB PRESENCE: ' . json_encode(
                    $this->activeMembers($requesterId, $serverId),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            } else {
                $parts[] = 'LIVE ECOLLAB PRESENCE: No server could be resolved. Ask the user which accessible server they mean.';
            }
        }

        if (preg_match('/\b(match|matches|matching|peer|partner|study buddy|study partner|compatible|compatibility|recommend(?:ed)? (?:person|people|student|peer)|help me with|good at|skill)\b/i', $prompt)) {
            $parts[] = 'INTELLIGENT PEER MATCHING: ' . json_encode(
                $this->peerMatches($requesterId, $serverId, 8),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        if (preg_match('/\b(document|documents|docx|xlsx|pptx|coworkspace|workspace|whiteboard|board|excalidraw|sticky|diagram)\b/i', $prompt)) {
            $parts[] = 'ECOLLAB COLLABORATION CONTEXT: ' . json_encode(
                $this->collaborationContext($requesterId, $serverId, $surface),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        if (preg_match('/\b(find|search|look for|where|thread|message|said|mentioned|discussed|conversation|chat|summari[sz]e|recap)\b/i', $prompt)) {
            $query = $this->extractSearchQuery($prompt);
            if ($query !== '') {
                $parts[] = 'ECOLLAB MESSAGE SEARCH: ' . json_encode(
                    $this->searchMessages($requesterId, $query, 12, $serverId),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }

        if (preg_match('/\b(server|servers|channel|channels|where am i|what server|what channel)\b/i', $prompt)) {
            $parts[] = 'REQUESTER ACCESSIBLE SERVERS/CHANNELS: ' . json_encode(
                $this->accessibleServersAndChannels($requesterId),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return implode("\n\n", $parts);
    }

    public function activeMembers(int $requesterId, int $serverId): array
    {
        if (!$this->canAccessServer($requesterId, $serverId)) return [];
        $stmt = $this->db->prepare(
            "SELECT u.id,u.username,u.full_name,u.role,u.is_online,u.last_active_at,
                    u.voice_channel_id,c.name AS voice_channel_name,sm.server_role,sm.nickname,
                    CASE
                      WHEN u.voice_channel_id IS NOT NULL THEN 'voice'
                      WHEN u.last_active_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'active'
                      WHEN u.last_active_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'idle'
                      ELSE 'offline'
                    END AS presence
             FROM users u
             JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=:sid
             LEFT JOIN channels c ON c.id=u.voice_channel_id AND c.server_id=:sid2
             LEFT JOIN user_settings us ON us.user_id=u.id
             WHERE u.deleted_at IS NULL
               AND COALESCE(u.is_system,0)=0
               AND u.status NOT IN ('banned','suspended','deactivated')
               AND u.last_active_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
               AND (us.activity_status IS NULL OR us.activity_status=1 OR u.id=:self)
             ORDER BY FIELD(presence,'voice','active','idle','offline'),u.full_name,u.username"
        );
        $stmt->execute([':sid'=>$serverId, ':sid2'=>$serverId, ':self'=>$requesterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function peerMatches(int $requesterId, ?int $serverId = null, int $limit = 8): array
    {
        $limit = max(1, min(12, $limit));
        $mine = $this->loadMatchingProfile($requesterId);
        if (!$this->profileReady($mine)) return ['profile_ready'=>false,'matches'=>[]];

        $sql = "SELECT DISTINCT u.id,u.username,u.full_name,u.role,u.bio,u.is_online,u.last_active_at
                FROM users u";
        $params = [':uid'=>$requesterId];
        if ($serverId && $this->canAccessServer($requesterId, $serverId)) {
            $sql .= " JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=:sid";
            $params[':sid'] = $serverId;
        }
        $sql .= " WHERE u.id<>:uid AND u.deleted_at IS NULL
                  AND u.status NOT IN ('banned','suspended','deactivated')
                  AND COALESCE(u.is_system,0)=0
                  ORDER BY u.is_online DESC,u.last_active_at DESC LIMIT 100";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $engine = new PeerMatchingService();
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $profile = $this->loadMatchingProfile((int)$candidate['id']);
            if (!$this->profileReady($profile)) continue;
            $score = $engine->scoreProfiles($mine, $profile);
            $matches[] = [
                'id'=>(int)$candidate['id'],
                'name'=>(string)($candidate['full_name'] ?: $candidate['username']),
                'role'=>$candidate['role'],
                'bio'=>$candidate['bio'] ?? '',
                'online'=>(bool)$candidate['is_online'],
                'score'=>$score['total'],
                'components'=>[
                    'subjects'=>$score['subjects'],
                    'study_style'=>$score['style'],
                    'interests'=>$score['interests'],
                    'hobbies'=>$score['hobbies'],
                ],
                'shared'=>[
                    'subjects'=>$score['shared_subjects'],
                    'interests'=>$score['shared_interests'],
                    'hobbies'=>$score['shared_hobbies'],
                ],
                'reasons'=>$score['tags'],
            ];
        }
        usort($matches, static fn(array $a,array $b):int => $b['score'] <=> $a['score']);
        return ['profile_ready'=>true,'scope'=>$serverId ? 'server' : 'accessible_users','matches'=>array_slice($matches,0,$limit)];
    }

    public function collaborationContext(int $requesterId, ?int $serverId, array $surface = []): array
    {
        $out = ['workspaces'=>[], 'document'=>null, 'whiteboard'=>null];
        if (!$serverId || !$this->canAccessServer($requesterId,$serverId)) return $out;

        $stmt = $this->db->prepare(
            "SELECT w.id,w.channel_id,w.name,w.visibility,w.host_id,w.allow_create_documents,
                    w.allow_edit_documents,w.allow_whiteboard,wm.role AS member_role,
                    c.name AS channel_name
             FROM collab_workspaces w
             JOIN channels c ON c.id=w.channel_id AND c.server_id=:sid
             LEFT JOIN collab_workspace_members wm ON wm.workspace_id=w.id AND wm.user_id=:uid
             WHERE w.archived=0 AND (w.visibility='public' OR wm.user_id IS NOT NULL OR w.host_id=:uid2)
             ORDER BY w.updated_at DESC LIMIT 20"
        );
        $stmt->execute([':sid'=>$serverId,':uid'=>$requesterId,':uid2'=>$requesterId]);
        $out['workspaces']=$stmt->fetchAll(PDO::FETCH_ASSOC);

        $documentId=(int)($surface['document_id'] ?? 0);
        if ($documentId>0) {
            $d=$this->db->prepare(
                "SELECT d.id,d.workspace_id,d.title,d.file_name,d.file_type,d.visibility,d.public_permission,
                        d.created_by,d.updated_by,d.updated_at
                 FROM collab_documents d
                 JOIN collab_workspaces w ON w.id=d.workspace_id
                 JOIN channels c ON c.id=w.channel_id AND c.server_id=:sid
                 LEFT JOIN collab_workspace_members wm ON wm.workspace_id=w.id AND wm.user_id=:uid
                 LEFT JOIN collab_resource_permissions rp ON rp.resource_type='document' AND rp.resource_id=d.id AND rp.user_id=:uid2
                 WHERE d.id=:did AND (d.visibility='public' OR d.created_by=:uid3 OR w.host_id=:uid4 OR wm.user_id IS NOT NULL OR rp.user_id IS NOT NULL)
                 LIMIT 1"
            );
            $d->execute([':sid'=>$serverId,':uid'=>$requesterId,':uid2'=>$requesterId,':did'=>$documentId,':uid3'=>$requesterId,':uid4'=>$requesterId]);
            $doc=$d->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($doc) {
                $p=$this->db->prepare("SELECT p.user_id,p.mode,p.last_seen,COALESCE(NULLIF(u.full_name,''),u.username) display_name FROM collab_document_presence p JOIN users u ON u.id=p.user_id WHERE p.document_id=:did AND p.last_seen >= (NOW()-INTERVAL 45 SECOND) ORDER BY p.mode,display_name");
                $p->execute([':did'=>$documentId]);
                $doc['presence']=$p->fetchAll(PDO::FETCH_ASSOC);
            }
            $out['document']=$doc;
        }

        $whiteboardId=(int)($surface['whiteboard_id'] ?? 0);
        if ($whiteboardId>0) {
            $w=$this->db->prepare(
                "SELECT b.id,b.workspace_id,b.title,b.description,b.visibility,b.public_permission,b.created_by,b.updated_by,b.updated_at,b.state_json
                 FROM collab_whiteboards b
                 JOIN collab_workspaces cw ON cw.id=b.workspace_id
                 JOIN channels c ON c.id=cw.channel_id AND c.server_id=:sid
                 LEFT JOIN collab_workspace_members wm ON wm.workspace_id=cw.id AND wm.user_id=:uid
                 LEFT JOIN collab_resource_permissions rp ON rp.resource_type='whiteboard' AND rp.resource_id=b.id AND rp.user_id=:uid2
                 WHERE b.id=:bid AND (b.visibility='public' OR b.created_by=:uid3 OR cw.host_id=:uid4 OR wm.user_id IS NOT NULL OR rp.user_id IS NOT NULL)
                 LIMIT 1"
            );
            $w->execute([':sid'=>$serverId,':uid'=>$requesterId,':uid2'=>$requesterId,':bid'=>$whiteboardId,':uid3'=>$requesterId,':uid4'=>$requesterId]);
            $board=$w->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($board) {
                $state=json_decode((string)$board['state_json'],true);
                unset($board['state_json']);
                $board['state_summary']=$this->summarizeWhiteboardState(is_array($state)?$state:[]);
            }
            $out['whiteboard']=$board;
        }
        return $out;
    }

    public function searchMessages(int $requesterId, string $query, int $limit = 12, ?int $serverId = null): array
    {
        $query=trim($query); if($query==='') return [];
        $limit=max(1,min(25,$limit));
        $sql="SELECT m.id,m.channel_id,m.content,m.created_at,u.username,u.full_name,c.name channel_name,c.server_id,s.name server_name,m.parent_id
              FROM messages m JOIN users u ON u.id=m.sender_id JOIN channels c ON c.id=m.channel_id
              JOIN servers s ON s.id=c.server_id JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid
              WHERE m.is_deleted=0 AND m.content LIKE :q
                AND (c.is_private=0 OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=:uid2)
                     OR sm.server_role IN ('owner','admin','moderator') OR c.created_by=:uid3)";
        $params=[':uid'=>$requesterId,':uid2'=>$requesterId,':uid3'=>$requesterId,':q'=>'%'.$query.'%'];
        if($serverId && $this->canAccessServer($requesterId,$serverId)){ $sql.=" AND c.server_id=:sid"; $params[':sid']=$serverId; }
        $sql.=" ORDER BY m.created_at DESC LIMIT {$limit}";
        $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function accessibleServersAndChannels(int $requesterId): array
    {
        $result=[];
        foreach($this->channels->getServersForUser($requesterId) as $server){
            $sid=(int)$server['id'];
            $result[]=['server'=>['id'=>$sid,'name'=>$server['name'],'server_role'=>$server['server_role']??'member'],
                'channels'=>array_map(static fn(array $c):array=>['id'=>(int)$c['id'],'name'=>$c['name'],'type'=>$c['type'],'is_private'=>(int)($c['is_private']??0)],$this->channels->getChannelsForUser($sid,$requesterId))];
        }
        return $result;
    }

    private function loadMatchingProfile(int $userId): array
    {
        $p=$this->db->prepare('SELECT * FROM pm_user_study_prefs WHERE user_id=?');$p->execute([$userId]);
        $s=$this->db->prepare('SELECT subject_id,role,proficiency FROM pm_user_subjects WHERE user_id=?');$s->execute([$userId]);
        $i=$this->db->prepare('SELECT interest_id FROM pm_user_interests WHERE user_id=?');$i->execute([$userId]);
        $h=$this->db->prepare('SELECT hobby_id FROM pm_user_hobbies WHERE user_id=?');$h->execute([$userId]);
        return ['prefs'=>$p->fetch(PDO::FETCH_ASSOC)?:[],'subjects'=>$s->fetchAll(PDO::FETCH_ASSOC),'interests'=>$i->fetchAll(PDO::FETCH_ASSOC),'hobbies'=>$h->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function profileReady(array $p): bool
    { return !empty($p['subjects']) || !empty($p['interests']) || !empty($p['hobbies']); }

    private function summarizeWhiteboardState(array $state): array
    {
        $texts=[];
        $walk=function($v) use (&$walk,&$texts):void {
            if(is_array($v)){foreach($v as $k=>$x){if(is_string($x) && in_array((string)$k,['text','label','content','title'],true)){ $t=trim($x); if($t!=='')$texts[]=$t; } elseif(is_array($x))$walk($x);}}
        };
        $walk($state);
        return ['paths'=>count(is_array($state['paths']??null)?$state['paths']:[]),'objects'=>count(is_array($state['objects']??null)?$state['objects']:[]),'text_items'=>array_slice(array_values(array_unique($texts)),0,50)];
    }

    private function canAccessServer(int $requesterId,int $serverId): bool
    {
        $stmt=$this->db->prepare('SELECT 1 FROM server_members WHERE server_id=:sid AND user_id=:uid LIMIT 1');
        $stmt->execute([':sid'=>$serverId,':uid'=>$requesterId]); return (bool)$stmt->fetchColumn();
    }

    private function resolveServerId(int $requesterId,string $prompt): ?int
    {
        if(preg_match('/server(?:_id)?\s*[:#]?\s*(\d+)/i',$prompt,$m)){ $id=(int)$m[1]; return $this->canAccessServer($requesterId,$id)?$id:null; }
        $servers=$this->channels->getServersForUser($requesterId);
        if(count($servers)===1)return (int)$servers[0]['id'];
        foreach($servers as $server){$name=trim((string)($server['name']??''));if($name!==''&&stripos($prompt,$name)!==false)return (int)$server['id'];}
        return null;
    }

    private function extractSearchQuery(string $prompt): string
    {
        if(preg_match('/["“](.+?)["”]/u',$prompt,$m))return mb_substr(trim($m[1]),0,120);
        $clean=preg_replace('/\b(jarred|please|can you|could you|find|search|look for|where|the|thread|threads|message|messages|chat|conversation|that|about|for|in|our|we|discussed|said|mentioned|summarize|summarise|recap)\b/i',' ',$prompt);
        return mb_substr(trim(preg_replace('/\s+/',' ',(string)$clean)),0,120);
    }
}
