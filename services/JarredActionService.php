<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/database/config/db.php';
require_once __DIR__.'/TemporaryVoiceService.php';
require_once __DIR__.'/JarredCapabilities.php';
require_once __DIR__.'/DocumentAccessService.php';

final class JarredActionService
{
    public const IMMEDIATE=0;
    public const CONFIRM=1;
    public const EXPLICIT_CONFIRM=2;
    private PDO $db;
    private TemporaryVoiceService $temporaryVoice;
    public function __construct(?PDO $db=null)
    {
        $this->db=$db??Database::getInstance();
        $this->temporaryVoice=$db===null?new TemporaryVoiceService():new TemporaryVoiceService($db);
    }
    public function capabilities(): array { return JarredCapabilities::actions(); }

    public function handleMessage(int $userId,int $conversationId,string $text,?int $activeServerId,array $surface=[]): ?array
    {
        // Existing PHP confirmation state remains authoritative for voice writes.
        $voice=$this->temporaryVoice->handleJarredMessage($userId,$conversationId,$text,$activeServerId);
        if($voice!==null)return $voice;
        if(preg_match('/^(?:please\s+)?(?:(?:can|could|would)\s+you\s+)?(open|take me to|go to|show me)\s+(.{1,160}?)[.!?]?$/iu',trim($text),$m)){
            $target=trim($m[2]);
            if(strtolower($m[1])==='show me' && !preg_match('/^(?:#|(?:(?:the|this|current|my)\s+)*(?:channel|document|doc|file|whiteboard|board)\b)/i',$target))return null;
            return $this->navigate($userId,$activeServerId,$surface,$target);
        }
        return null;
    }

    private function navigate(int $uid,?int $sid,array $surface,string $target): ?array
    {
        if(!$sid||!$this->canAccessServer($uid,$sid))return ['reply'=>'Choose a server you can access first, then name its channel or open a document or whiteboard there.'];
        $target=trim($target," \t\n\r\0\x0B\"'");
        if(preg_match('/^(?:(?:the|this|my|current)\s+)*(document|doc|file|whiteboard|board)$/i',$target,$m)){
            $board=in_array(strtolower($m[1]),['board','whiteboard'],true);
            $id=(int)($surface[$board?'whiteboard_id':'document_id']??0);
            if($id<=0)return ['reply'=>'Open the document or whiteboard in Coworkspace first so I can identify the resource you mean.'];
            try{
                if(!$board){
                    $a=DocumentAccessService::get($this->db,$id,$uid);
                    $workspace=$a['workspace'];$resource=$a['document'];
                }else{
                    $q=$this->db->prepare('SELECT b.id,b.title,b.workspace_id,w.channel_id FROM collab_whiteboards b JOIN collab_workspaces w ON w.id=b.workspace_id WHERE b.id=?');$q->execute([$id]);$resource=$q->fetch(PDO::FETCH_ASSOC);
                    if(!$resource)return ['reply'=>'That resource is unavailable or you no longer have access.'];
                    $workspace=CoworkspaceService::get($this->db,(int)$resource['workspace_id'],$uid);
                    if(CoworkspaceService::resolveWhiteboardAccess($this->db,$id,(int)$workspace['channel_id'],$uid)===null)return ['reply'=>'That resource is unavailable or you no longer have access.'];
                }
                if((int)$workspace['server_id']!==$sid||!$this->channelAccessible($uid,$sid,(int)$workspace['channel_id']))return ['reply'=>'That resource is unavailable or you no longer have access.'];
                return ['reply'=>'Opening '.($resource['title']?:($board?'the whiteboard':'the document')).'.','action'=>['type'=>$board?'open_whiteboard':'open_document',($board?'whiteboard_id':'document_id')=>$id,'workspace_id'=>(int)$workspace['id']]];
            }catch(RuntimeException $e){if(!in_array($e->getCode(),[403,404],true))throw $e;return ['reply'=>'That resource is unavailable or you no longer have access.'];}
        }
        // Names of other documents are never treated as the current resource.
        if(preg_match('/\b(document|whiteboard|file)\b/i',$target))return ['reply'=>'I can open your current authorized document or whiteboard. Open it in Coworkspace first; I cannot identify another resource by that name.'];
        $current=in_array(strtolower($target),['channel','this channel','current channel','the current channel'],true);
        $name=preg_replace('/^(?:the\s+)?channel\s+|\s+channel$/i','',$target);
        $name=mb_strtolower(ltrim(trim((string)$name," \t\"'"),'#'));
        if($current){
            $cid=(int)($surface['channel_id']??0);
            $where='c.id=?';$params=[$uid,$sid,$cid,$uid,$uid];
        }elseif(str_starts_with($target,'#')){
            $where='LOWER(c.slug)=?';$params=[$uid,$sid,$name,$uid,$uid];
        }else{
            $where='(LOWER(c.name)=? OR LOWER(c.slug)=?)';$params=[$uid,$sid,$name,$name,$uid,$uid];
        }
        $q=$this->db->prepare("SELECT c.id,c.slug,c.name,c.type FROM channels c JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=? WHERE c.server_id=? AND $where AND (c.is_private=0 OR c.created_by=? OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=?)) ORDER BY c.id LIMIT 6");
        $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows)return ['reply'=>'I could not find an accessible channel with that exact name in this server. Try its #slug.'];
        if(count($rows)>1)return ['reply'=>'More than one accessible channel matches. Use an exact #slug: '.implode(', ',array_map(fn($r)=>'#'.$r['slug'],$rows)).'.'];
        $r=$rows[0];return ['reply'=>'Opening #'.$r['name'].'.','action'=>['type'=>'open_channel','channel_id'=>(int)$r['id'],'channel_slug'=>$r['slug'],'channel_type'=>$r['type']]];
    }
    private function channelAccessible(int $uid,int $sid,int $cid): bool
    {
        $q=$this->db->prepare('SELECT 1 FROM channels c JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=? WHERE c.id=? AND c.server_id=? AND (c.is_private=0 OR c.created_by=? OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=?))');$q->execute([$uid,$cid,$sid,$uid,$uid]);return (bool)$q->fetchColumn();
    }
    private function canAccessServer(int $uid,int $sid): bool
    {
        $q=$this->db->prepare("SELECT 1 FROM server_members sm JOIN servers s ON s.id=sm.server_id JOIN users u ON u.id=sm.user_id WHERE sm.server_id=? AND sm.user_id=? AND s.status='active' AND u.deleted_at IS NULL AND u.status NOT IN ('banned','suspended','deactivated')");$q->execute([$sid,$uid]);return (bool)$q->fetchColumn();
    }
}
