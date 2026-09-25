<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/services/CoworkspaceService.php';

class WhiteboardHandler
{
    private PDO $db;
    private array $rooms = [];
    private array $opLog = [];
    private array $stateCache = [];
    private array $accessCache = [];

    private const PALETTE = [
        ['#3b82f6','#1d4ed8'],['#ec4899','#be185d'],['#14b8a6','#0f766e'],['#f59e0b','#b45309'],
        ['#22c55e','#15803d'],['#6366f1','#4338ca'],['#ef4444','#b91c1c'],['#f97316','#c2410c']
    ];

    private const COMMENT_ALLOWED_OPS = ['sticky_add','sticky_move','sticky_text'];
    private const MAX_EXCALIDRAW_SCENE_BYTES = 5242880;

    public function __construct(){ $this->db=Database::getInstance(); }

    private function roomKey(int $channelId, ?int $whiteboardId): string
    {
        return $whiteboardId !== null ? "wb:{$whiteboardId}" : "ch:{$channelId}";
    }

    public function authorize(int $channelId,int $userId,?int $whiteboardId=null): ?array
    {
        if($channelId<1||$userId<1)return null;
        if($whiteboardId===null){
            return $this->canEdit($channelId,$userId,null)
                ? ['channel_id'=>$channelId,'whiteboard_id'=>null,'permission'=>'edit','is_owner'=>true]
                : ['channel_id'=>$channelId,'whiteboard_id'=>null,'permission'=>'view','is_owner'=>false];
        }
        if(isset($this->accessCache[$whiteboardId][$userId]))return $this->accessCache[$whiteboardId][$userId];
        return $this->accessCache[$whiteboardId][$userId]=CoworkspaceService::resolveWhiteboardAccess($this->db,$whiteboardId,$channelId,$userId);
    }

    public function join(int $channelId,int $userId,string $username,?int $whiteboardId=null):array
    {
        $key=$this->roomKey($channelId,$whiteboardId);
        $this->rooms[$key]??=[];
        if(isset($this->rooms[$key][$userId])){
            return [
                'type'=>'wb_joined','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId,
                'peers'=>array_values(array_filter($this->rooms[$key],fn($p)=>(int)$p['user_id']!==$userId)),
                'you'=>$this->rooms[$key][$userId],
                'state_json'=>$this->getState($channelId,$whiteboardId),'pending_ops'=>$this->opLog[$key]??[]
            ];
        }
        $i=count($this->rooms[$key])%count(self::PALETTE);[$c1,$c2]=self::PALETTE[$i];
        $this->rooms[$key][$userId]=['user_id'=>$userId,'username'=>$username,'color'=>$c1,'grad'=>"linear-gradient(135deg,{$c1},{$c2})",'initial'=>mb_strtoupper(mb_substr($username,0,1))];
        $peers=[];foreach($this->rooms[$key] as $uid=>$p)if((int)$uid!==$userId)$peers[]=$p;
        return ['type'=>'wb_joined','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId,'peers'=>array_values($peers),'you'=>$this->rooms[$key][$userId],'state_json'=>$this->getState($channelId,$whiteboardId),'pending_ops'=>$this->opLog[$key]??[]];
    }

    public function leave(int $channelId,int $userId,?int $whiteboardId=null):array
    {
        $key=$this->roomKey($channelId,$whiteboardId);if(!isset($this->rooms[$key]))return [];
        unset($this->rooms[$key][$userId]);
        if($whiteboardId!==null)unset($this->accessCache[$whiteboardId][$userId]);
        if(empty($this->rooms[$key])){unset($this->rooms[$key],$this->opLog[$key]);}
        return $this->getRoomUserIds($channelId,0,$whiteboardId);
    }

    public function getRoomUserIds(int $channelId,int $excludeId=0,?int $whiteboardId=null):array
    {
        $key=$this->roomKey($channelId,$whiteboardId);
        return array_values(array_filter(array_keys($this->rooms[$key]??[]),fn($id)=>(int)$id!==$excludeId));
    }
    public function getUserMeta(int $channelId,int $userId,?int $whiteboardId=null):?array{return $this->rooms[$this->roomKey($channelId,$whiteboardId)][$userId]??null;}
    public function getMembers(int $channelId,?int $whiteboardId=null):array{return array_values($this->rooms[$this->roomKey($channelId,$whiteboardId)]??[]);}

    public function canEdit(int $channelId,int $userId,?int $whiteboardId=null):bool
    {
        if($whiteboardId!==null){
            $access=$this->accessCache[$whiteboardId][$userId]??$this->authorize($channelId,$userId,$whiteboardId);
            return is_array($access)&&($access['permission']??'view')==='edit';
        }
        try{$stmt=$this->db->prepare('SELECT created_by,locked FROM whiteboards WHERE channel_id=:cid ORDER BY updated_at DESC LIMIT 1');$stmt->execute([':cid'=>$channelId]);$board=$stmt->fetch();return !$board||!(bool)$board['locked']||(int)$board['created_by']===$userId;}catch(\Throwable){return false;}
    }

    public function canPerformOp(int $channelId,int $userId,string $opType,?int $whiteboardId):bool
    {
        if($whiteboardId===null)return $this->canEdit($channelId,$userId,null);
        $access=$this->accessCache[$whiteboardId][$userId]??null;
        if($access===null)return false;
        return match($access['permission']??'view'){
            'edit'=>true,
            'comment'=>in_array($opType,self::COMMENT_ALLOWED_OPS,true),
            default=>false,
        };
    }

    public function recordOp(int $channelId,int $userId,array $op,?int $whiteboardId=null):array
    {
        if (($op['op'] ?? '') === 'excalidraw_scene') {
            $scene = $op['scene'] ?? null;
            if (!is_array($scene) || !is_array($scene['elements'] ?? null)) {
                throw new \InvalidArgumentException('Invalid Excalidraw scene.');
            }
            $encoded = json_encode($scene, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($encoded === false || strlen($encoded) > self::MAX_EXCALIDRAW_SCENE_BYTES) {
                throw new \LengthException('Excalidraw scene is too large.');
            }
        }
        $key=$this->roomKey($channelId,$whiteboardId);$meta=$this->rooms[$key][$userId]??[];
        $stamped=array_merge($op,['user_id'=>$userId,'username'=>$meta['username']??'','color'=>$meta['color']??'#a855f7','grad'=>$meta['grad']??'','initial'=>$meta['initial']??'?','ts'=>round(microtime(true)*1000)]);
        if(($op['op']??'')==='cursor')return $stamped;
        $this->opLog[$key]??=[];$this->opLog[$key][]=$stamped;
        if($whiteboardId===null&&count($this->opLog[$key])%20===0)$this->persistSnapshot($channelId,$userId,'',null);
        return $stamped;
    }

    private function replayOpsToState(int $channelId,?int $whiteboardId=null):string
    {
        $key=$this->roomKey($channelId,$whiteboardId);$paths=[];$active=[];
        foreach($this->opLog[$key]??[] as $op){$type=$op['op']??'';$pathId=(string)($op['path_id']??'');
            if($type==='stroke_start'&&$pathId!=='')$active[$pathId]=['id'=>$op['path_id'],'tool'=>$op['tool']??'pen','color'=>$op['color']??'#000000','size'=>(float)($op['size']??2),'points'=>[['x'=>(float)($op['x']??0),'y'=>(float)($op['y']??0)]]];
            elseif($type==='stroke_point'&&$pathId!==''&&isset($active[$pathId]))$active[$pathId]['points'][]=['x'=>(float)($op['x']??0),'y'=>(float)($op['y']??0)];
            elseif($type==='stroke_end'&&$pathId!==''&&isset($active[$pathId])){$paths[]=$active[$pathId];unset($active[$pathId]);}
            elseif($type==='undo'&&isset($op['path_id'])){$id=(string)$op['path_id'];$paths=array_values(array_filter($paths,fn($p)=>(string)($p['id']??'')!==$id));unset($active[$id]);}
            elseif($type==='clear'){$paths=[];$active=[];}
        }
        foreach($active as $path)$paths[]=$path;
        return json_encode(['paths'=>array_values($paths)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    public function persistSnapshot(int $channelId,int $userId,string $stateJson='',?int $whiteboardId=null):void
    {
        if($whiteboardId!==null){
            if($stateJson==='')$stateJson=$this->getState($channelId,$whiteboardId)??'{}';
            try{$stmt=$this->db->prepare('UPDATE collab_whiteboards SET state_json=:state,updated_by=:uid,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$stmt->execute([':state'=>$stateJson,':uid'=>$userId,':id'=>$whiteboardId]);$this->stateCache[$this->roomKey($channelId,$whiteboardId)]=$stateJson;}catch(\Throwable $e){error_log('[WhiteboardHandler] modern state DB error: '.$e->getMessage());}
            return;
        }
        $payload=$stateJson?:$this->replayOpsToState($channelId,null);
        try{$exists=$this->db->prepare('SELECT id FROM whiteboards WHERE channel_id=:cid ORDER BY updated_at DESC LIMIT 1');$exists->execute([':cid'=>$channelId]);$row=$exists->fetch();if($row)$this->db->prepare('UPDATE whiteboards SET state_json=:s,updated_by=:uid,updated_at=NOW() WHERE id=:id')->execute([':s'=>$payload,':uid'=>$userId,':id'=>$row['id']]);else$this->db->prepare('INSERT INTO whiteboards(channel_id,state_json,created_by,created_at,updated_at) VALUES(:cid,:s,:uid,NOW(),NOW())')->execute([':cid'=>$channelId,':s'=>$payload,':uid'=>$userId]);$this->stateCache[$this->roomKey($channelId,null)]=$payload;}catch(\Throwable $e){error_log('[WhiteboardHandler] DB error: '.$e->getMessage());}
    }

    public function getState(int $channelId,?int $whiteboardId=null):?string
    {
        $key=$this->roomKey($channelId,$whiteboardId);if(isset($this->stateCache[$key]))return $this->stateCache[$key];
        try{if($whiteboardId!==null){$stmt=$this->db->prepare('SELECT state_json FROM collab_whiteboards WHERE id=:id LIMIT 1');$stmt->execute([':id'=>$whiteboardId]);}else{$stmt=$this->db->prepare('SELECT state_json FROM whiteboards WHERE channel_id=:cid ORDER BY updated_at DESC LIMIT 1');$stmt->execute([':cid'=>$channelId]);}$row=$stmt->fetch();if($row){$this->stateCache[$key]=$row['state_json'];return $row['state_json'];}}catch(\Throwable){}
        return null;
    }
}
