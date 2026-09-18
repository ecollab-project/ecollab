<?php

declare(strict_types=1);

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';
require_once dirname(__DIR__, 1) . '/database/config/db.php';
require_once __DIR__ . '/handlers/WhiteboardHandler.php';
require_once __DIR__ . '/handlers/DmHandler.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, array> */
    protected \SplObjectStorage $clients;
    protected array $channelSubs = [];
    protected array $connMeta = [];
    protected array $voiceRooms = [];
    protected array $userConns = [];
    protected array $codeSessions = [];

    private PDO $db;
    private WhiteboardHandler $wbHandler;
    private int $codePersistEveryChanges = 10;
    private int $codePersistEverySeconds = 10;
    private int $codeMaxStateBytes = 1048576;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->db = Database::getInstance();
        $this->wbHandler = new WhiteboardHandler();
        $this->db->exec("CREATE TABLE IF NOT EXISTS ws_relay (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, channel_id INT UNSIGNED NOT NULL, payload TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_channel_id (channel_id), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[ChatServer] Started\n";
    }

    public function drainRelayTable(): void
    {
        try {
            $stmt = $this->db->query("SELECT id, channel_id, payload FROM ws_relay ORDER BY id ASC LIMIT 100");
            $rows = $stmt->fetchAll();
            if (!$rows) return;
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int)$row['id'];
                foreach ($this->channelSubs[(int)$row['channel_id']] ?? [] as $conn) try { $conn->send($row['payload']); } catch (\Exception) {}
            }
            $this->db->exec('DELETE FROM ws_relay WHERE id IN (' . implode(',', $ids) . ')');
        } catch (\Throwable $e) { echo "[WS] relay drain error: {$e->getMessage()}\n"; }
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $rid = $conn->resourceId;
        $this->connMeta[$rid] = [
            'user_id' => null,
            'username' => null,
            'channel_id' => null,
            'wb_channel_id' => null,
            'wb_whiteboard_id' => null,
            'authed' => false,
            'code_sessions' => [],
        ];
        echo "[WS] Connection {$rid} opened\n";
    }

    public function onMessage(ConnectionInterface $from, $rawMsg): void
    {
        $rid = $from->resourceId;
        $meta = &$this->connMeta[$rid];
        $data = json_decode($rawMsg, true);
        if (!is_array($data) || empty($data['type'])) return;
        $type = $data['type'];

        if ($type === 'auth') { $this->handleAuth($from, $data, $meta); return; }
        if (!$meta['authed']) { $from->send(json_encode(['type' => 'error', 'message' => 'Unauthenticated'])); return; }
        if (is_string($type) && str_starts_with($type, 'code_')) { $this->handleCodeMessage($from, $data, $meta); return; }

        $channelScopedTypes = [
            'join_channel','message','message_edited','message_deleted','message_pinned',
            'collab_note_cursor','collab_note_presence','typing','presence','channel_seen',
            'draft_save','thread_reply','mention','join_voice','whiteboard_sync','wb_join',
            'wb_op','wb_cursor','wb_state_save','wb_request_state','screen_share_notify',
            'webrtc_offer','webrtc_answer','webrtc_candidate',
        ];
        if (in_array($type, $channelScopedTypes, true) && !$this->authorizeChannelMessage($from, $data, $meta)) return;

        match ($type) {
            'ping' => $from->send(json_encode(['type' => 'pong'])),
            'join_channel' => $this->handleJoinChannel($from, $data, $meta),
            'leave_channel' => $this->handleLeaveChannel($from, $data, $meta),
            'message' => $this->handleMessage($from, $data, $meta),
            'message_edited' => $this->handleEditedBroadcast($from, $data, $meta),
            'message_deleted' => $this->handleDeletedBroadcast($from, $data, $meta),
            'message_pinned' => $this->handlePinnedBroadcast($from, $data, $meta),
            'collab_note_cursor' => $this->handleNoteRelay($from, $data, $meta),
            'collab_note_presence' => $this->handleNoteRelay($from, $data, $meta),
            'typing' => $this->handleTyping($from, $data, $meta),
            'presence' => $this->handlePresence($from, $data, $meta),
            'channel_seen' => $this->handleChannelSeen($from, $data, $meta),
            'draft_save' => $this->handleDraftSave($from, $data, $meta),
            'thread_reply' => $this->handleThreadReply($from, $data, $meta),
            'mention' => $this->handleMentionRelay($from, $data, $meta),
            'join_voice' => $this->handleJoinVoice($from, $data, $meta),
            'leave_voice' => $this->handleLeaveVoice($from, $data, $meta),
            'whiteboard_sync' => $this->handleWhiteboardSync($from, $data, $meta),
            'wb_join' => $this->handleWbJoin($from, $data, $meta),
            'wb_leave' => $this->handleWbLeave($from, $data, $meta),
            'wb_op' => $this->handleWbOp($from, $data, $meta),
            'wb_cursor' => $this->handleWbCursor($from, $data, $meta),
            'wb_state_save' => $this->handleWbStateSave($from, $data, $meta),
            'wb_request_state' => $this->handleWbRequestState($from, $data, $meta),
            'webrtc_offer' => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_offer'),
            'webrtc_answer' => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_answer'),
            'webrtc_candidate' => $this->handleWebRtcSignal($from, $data, $meta, 'webrtc_candidate'),
            'screen_share_notify' => $this->handleScreenShareNotify($from, $data, $meta),
            'connection_request' => $this->handleConnectionRequest($from, $data, $meta),
            'dm_message' => $this->handleDmMessage($from, $data, $meta),
            'dm_typing' => $this->handleDmTyping($from, $data, $meta),
            'dm_group_message' => $this->handleDmGroupMessage($from, $data, $meta),
            'dm_group_typing' => $this->handleDmGroupTyping($from, $data, $meta),
            'notify_conn_req' => $this->handleNotifyConnReq($from, $data, $meta),
            'notify_conn_accepted' => $this->handleNotifyConnAccepted($from, $data, $meta),
            'voice_invite' => $this->handleVoiceInvite($from, $data, $meta),
            'dm_call_offer' => $this->handleDmCallSignal($from, $data, $meta, 'dm_call_offer'),
            'dm_call_answer' => $this->handleDmCallSignal($from, $data, $meta, 'dm_call_answer'),
            'dm_call_candidate' => $this->handleDmCallSignal($from, $data, $meta, 'dm_call_candidate'),
            'dm_call_end' => $this->handleDmCallSignal($from, $data, $meta, 'dm_call_end'),
            'dm_call_decline' => $this->handleDmCallSignal($from, $data, $meta, 'dm_call_decline'),
            default => null,
        };
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $rid = $conn->resourceId;
        $meta = $this->connMeta[$rid] ?? [];
        if (!empty($meta['channel_id'])) $this->removeFromChannel($conn, (int)$meta['channel_id']);
        $this->cleanupCodeConnection($conn, $meta);
        if (!empty($meta['user_id'])) {
            $uid = (int)$meta['user_id']; $username = $meta['username'] ?? '';
            if (isset($this->userConns[$uid])) {
                $this->userConns[$uid] = array_values(array_filter($this->userConns[$uid], fn($c) => $c !== $conn));
                if (empty($this->userConns[$uid])) unset($this->userConns[$uid]);
            }
            if (!isset($this->userConns[$uid])) {
                if (!empty($meta['wb_channel_id'])) {
                    $wbChanId = (int)$meta['wb_channel_id'];
                    $wbWhiteboardId = $meta['wb_whiteboard_id'] ?? null;
                    if ($wbWhiteboardId !== null) $wbWhiteboardId = (int)$wbWhiteboardId;
                    $remainingIds = $this->wbHandler->leave($wbChanId, $uid, $wbWhiteboardId);
                    $leaveNotify = json_encode(['type'=>'wb_peer_left','channel_id'=>$wbChanId,'whiteboard_id'=>$wbWhiteboardId,'user_id'=>$uid,'username'=>$username]);
                    foreach ($remainingIds as $peerId) foreach ($this->userConns[$peerId] ?? [] as $peerConn) try { $peerConn->send($leaveNotify); } catch (\Exception) {}
                }
                foreach ($this->voiceRooms as $vcId => &$participants) {
                    $wasInRoom = array_filter($participants, fn($p) => $p['user_id'] === $uid);
                    if (!empty($wasInRoom)) {
                        $participants = array_values(array_filter($participants, fn($p) => $p['user_id'] !== $uid));
                        $leavePayload = json_encode(['type'=>'voice_leave','user_id'=>$uid,'username'=>$username,'channel_id'=>$vcId]);
                        foreach ($participants as $p) foreach ($this->userConns[(int)$p['user_id']] ?? [] as $peerConn) try { $peerConn->send($leavePayload); } catch (\Exception) {}
                        // Voice leave is already sent only to remaining participants in that room.
                        if (empty($participants)) unset($this->voiceRooms[$vcId]);
                    }
                }
                unset($participants);
                try { $this->db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")->execute([':id'=>$uid]); } catch (\Exception) {}
                $this->setUserOnline($uid, false);
                $this->broadcastPresence($uid, false, $username);
            }
        }
        $this->clients->detach($conn); unset($this->connMeta[$rid]);
        echo "[WS] Connection {$rid} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[WS] Error on {$conn->resourceId}: {$e->getMessage()}\n"; $conn->close();
    }

    private function handleAuth(ConnectionInterface $conn, array $data, array &$meta): void
    {
        $wsToken = trim($data['ws_token'] ?? '');
        if ($wsToken === '') { $conn->send(json_encode(['type'=>'error','message'=>'Invalid auth: ws_token required'])); return; }
        $hash = hash('sha256',$wsToken);
        $stmt = $this->db->prepare("SELECT u.id,u.username,u.status,u.full_name,u.avatar_color_gradient FROM ws_tokens wt JOIN users u ON u.id=wt.user_id WHERE wt.token_hash=:hash AND wt.expires_at>NOW() AND u.deleted_at IS NULL LIMIT 1");
        $stmt->execute([':hash'=>$hash]); $user=$stmt->fetch(); $stmt->closeCursor();
        if (!$user) { $conn->send(json_encode(['type'=>'error','message'=>'Invalid or expired auth token'])); return; }
        // `users.status` is also used by the application for presence (`offline`),
        // so an authenticated user is not limited to the literal `active` value.
        // Only account-blocking states must prevent WebSocket authentication.
        if (in_array($user['status'], ['banned', 'suspended', 'deactivated'], true)) {
            $conn->send(json_encode(['type'=>'error','message'=>'Account is not active']));
            return;
        }
        $userId=(int)$user['id']; $username=$user['username'];
        $meta['user_id']=$userId; $meta['username']=$username; $meta['full_name']=$user['full_name']??$username; $meta['gradient']=$user['avatar_color_gradient']??''; $meta['authed']=true;
        $this->userConns[$userId][]=$conn; $this->setUserOnline($userId,true); $this->broadcastPresence($userId,true,$username);
        $conn->send(json_encode(['type'=>'auth_ok','user_id'=>$userId]));
        echo "[WS] User {$username} ({$userId}) authenticated on {$conn->resourceId}\n";
    }

    private function handleCodeMessage(ConnectionInterface $from, array $data, array &$meta): void
    {
        $type=(string)($data['type']??'');
        try { match($type){
            'code_create'=>$this->handleCodeCreate($from,$data,$meta), 'code_invite_generate'=>$this->handleCodeInviteGenerate($from,$data,$meta), 'code_join'=>$this->handleCodeJoin($from,$data,$meta), 'code_join_request'=>$this->handleCodeJoinRequest($from,$data,$meta), 'code_join_response'=>$this->handleCodeJoinResponse($from,$data,$meta), 'code_role_update'=>$this->handleCodeRoleUpdate($from,$data,$meta), 'code_change'=>$this->handleCodeChange($from,$data,$meta), 'code_cursor'=>$this->handleCodeCursor($from,$data,$meta), 'code_selection'=>$this->handleCodeSelection($from,$data,$meta), 'code_execution'=>$this->handleCodeExecution($from,$data,$meta), 'code_output'=>$this->handleCodeOutput($from,$data,$meta), 'code_leave'=>$this->handleCodeLeave($from,$data,$meta), 'code_session_closed'=>$this->handleCodeSessionClosed($from,$data,$meta), default=>$this->codeError($from,'Unknown Coding Buddy event.'),}; }
        catch(\Throwable $e){ error_log('[WS] Coding Buddy error: '.$e->getMessage()); $this->codeError($from,'Coding Buddy request failed.'); }
    }

    private function handleCodeCreate(ConnectionInterface $from,array $data,array &$meta):void{$uid=(int)$meta['user_id'];$initial=(string)($data['content']??'');if(strlen($initial)>$this->codeMaxStateBytes){$this->codeError($from,'Initial code is too large.');return;}$stmt=$this->db->prepare('INSERT INTO coding_sessions (owner_id, code_state, version) VALUES (:owner,:state,0)');$stmt->execute([':owner'=>$uid,':state'=>$initial]);$sid=(int)$this->db->lastInsertId();$stmt=$this->db->prepare("INSERT INTO coding_session_participants (session_id,user_id,status,role,joined_at) VALUES (:sid,:uid,'approved','editor',NOW())");$stmt->execute([':sid'=>$sid,':uid'=>$uid]);$this->codeSessions[$sid]=['version'=>0,'content'=>$initial,'connections'=>[],'dirty_changes'=>0,'last_persist_at'=>microtime(true)];$this->codeJoinConnection($from,$meta,$sid,'editor',true);$from->send(json_encode(['type'=>'code_created','session_id'=>$sid,'version'=>0]));}
    private function handleCodeInviteGenerate(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);$uid=(int)$meta['user_id'];if(!$sid||!$this->isCodeOwner($sid,$uid)){$this->codeError($from,'Only the coding-session owner can generate invites.');return;}$token=bin2hex(random_bytes(32));$expiresIn=(int)($data['expires_in']??86400);if($expiresIn<0)$expiresIn=86400;if($expiresIn>604800)$expiresIn=604800;$expiresSql=$expiresIn===0?null:date('Y-m-d H:i:s',time()+$expiresIn);$stmt=$this->db->prepare('UPDATE coding_sessions SET invite_token=:token,invite_expires_at=:expires WHERE id=:sid AND owner_id=:uid');$stmt->execute([':token'=>$token,':expires'=>$expiresSql,':sid'=>$sid,':uid'=>$uid]);$from->send(json_encode(['type'=>'code_invite_generated','session_id'=>$sid,'invite_token'=>$token,'invite_expires_at'=>$expiresSql,'link'=>'/coding-sessions/'.$sid.'/join?token='.rawurlencode($token)]));}
    private function handleCodeJoin(ConnectionInterface $from,array $data,array &$meta):void{$sid=(int)($data['session_id']??0);$uid=(int)$meta['user_id'];if(!$sid){$this->codeError($from,'A coding session is required.');return;}$stmt=$this->db->prepare('SELECT cs.id,cs.owner_id,cs.code_state,cs.version,csp.status,csp.role FROM coding_sessions cs LEFT JOIN coding_session_participants csp ON csp.session_id=cs.id AND csp.user_id=:uid WHERE cs.id=:sid LIMIT 1');$stmt->execute([':uid'=>$uid,':sid'=>$sid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row){$this->codeError($from,'Coding session not found.');return;}$owner=(int)$row['owner_id']===$uid;if(!$owner&&$row['status']!=='approved'){$this->codeError($from,'Coding session access is not approved.');return;}$role=$owner?'editor':(string)$row['role'];$this->loadCodeSession($sid,(string)$row['code_state'],(int)$row['version']);$this->codeJoinConnection($from,$meta,$sid,$role,false);}
    private function handleCodeJoinRequest(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);$uid=(int)$meta['user_id'];if(!$sid){$this->codeError($from,'A coding session is required.');return;}$stmt=$this->db->prepare('SELECT id,owner_id FROM coding_sessions WHERE id=:sid LIMIT 1');$stmt->execute([':sid'=>$sid]);$session=$stmt->fetch(PDO::FETCH_ASSOC);if(!$session){$this->codeError($from,'Coding session not found.');return;}$ownerId=(int)$session['owner_id'];if($ownerId===$uid){$this->codeError($from,'The owner already has access.');return;}$stmt=$this->db->prepare('SELECT status,joined_at FROM coding_session_participants WHERE session_id=:sid AND user_id=:uid LIMIT 1');$stmt->execute([':sid'=>$sid,':uid'=>$uid]);$existing=$stmt->fetch(PDO::FETCH_ASSOC);if($existing&&$existing['status']==='approved'){$this->codeError($from,'You are already a coding-session participant.');return;}if($existing&&$existing['status']==='pending'){$this->codeError($from,'A join request is already pending.');return;}if($existing&&!empty($existing['joined_at'])&&strtotime((string)$existing['joined_at'])>time()-60){$this->codeError($from,'Please wait before requesting access again.');return;}$stmt=$this->db->prepare("INSERT INTO coding_session_participants (session_id,user_id,status,role,joined_at) VALUES (:sid,:uid,'pending','viewer',NOW()) ON DUPLICATE KEY UPDATE status='pending',role='viewer',joined_at=NOW()");$stmt->execute([':sid'=>$sid,':uid'=>$uid]);$payload=json_encode(['type'=>'code_join_requested','session_id'=>$sid,'user_id'=>$uid,'username'=>$meta['username']]);foreach($this->userConns[$ownerId]??[] as $ownerConn)try{$ownerConn->send($payload);}catch(\Exception){}$from->send(json_encode(['type'=>'code_join_request_sent','session_id'=>$sid]));}
    private function handleCodeJoinResponse(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);$target=(int)($data['user_id']??0);$approve=(bool)($data['approve']??false);$uid=(int)$meta['user_id'];if(!$sid||!$target||!$this->isCodeOwner($sid,$uid)){$this->codeError($from,'Only the coding-session owner can approve or reject requests.');return;}$status=$approve?'approved':'rejected';$stmt=$this->db->prepare("UPDATE coding_session_participants SET status=:status,role=IF(:status2='approved','viewer',role) WHERE session_id=:sid AND user_id=:uid AND status='pending'");$stmt->execute([':status'=>$status,':status2'=>$status,':sid'=>$sid,':uid'=>$target]);if($stmt->rowCount()<1){$this->codeError($from,'No pending join request was found.');return;}$payload=json_encode(['type'=>'code_join_response','session_id'=>$sid,'approved'=>$approve,'role'=>$approve?'viewer':null]);foreach($this->userConns[$target]??[] as $targetConn)try{$targetConn->send($payload);}catch(\Exception){}}
    private function handleCodeRoleUpdate(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);$target=(int)($data['user_id']??0);$role=strtolower(trim((string)($data['role']??'')));$uid=(int)$meta['user_id'];if(!in_array($role,['editor','viewer'],true)){$this->codeError($from,'Invalid coding-session role.');return;}if(!$sid||!$target||!$this->isCodeOwner($sid,$uid)){$this->codeError($from,'Only the coding-session owner can change roles.');return;}if($target===$uid){$this->codeError($from,'The owner role cannot be changed.');return;}$stmt=$this->db->prepare("UPDATE coding_session_participants SET role=:role WHERE session_id=:sid AND user_id=:uid AND status='approved'");$stmt->execute([':role'=>$role,':sid'=>$sid,':uid'=>$target]);if($stmt->rowCount()<1){$this->codeError($from,'Approved participant not found.');return;}$this->broadcastCodeSession($sid,json_encode(['type'=>'code_role_updated','session_id'=>$sid,'user_id'=>$target,'role'=>$role]));}
    private function handleCodeChange(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);$version=filter_var($data['version']??null,FILTER_VALIDATE_INT);$content=(string)($data['content']??'');$uid=(int)$meta['user_id'];if(!$sid||$version===false){$this->codeError($from,'session_id and integer version are required.');return;}if(strlen($content)>$this->codeMaxStateBytes){$this->codeError($from,'Code snapshot is too large.');return;}if(!$this->requireCodeEditor($from,$sid,$uid))return;$this->ensureCodeSessionLoaded($sid);$serverVersion=(int)$this->codeSessions[$sid]['version'];if((int)$version!==$serverVersion){$this->sendCodeResync($from,$sid);return;}$newVersion=$serverVersion+1;$this->codeSessions[$sid]['content']=$content;$this->codeSessions[$sid]['version']=$newVersion;$this->codeSessions[$sid]['dirty_changes']++;$this->broadcastCodeSession($sid,json_encode(['type'=>'code_change','session_id'=>$sid,'content'=>$content,'version'=>$newVersion,'user_id'=>$uid]),$from);$this->maybePersistCodeSession($sid);}
    private function handleCodeCursor(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);if(!$this->requireCodeParticipant($from,$sid,(int)$meta['user_id']))return;$this->broadcastCodeSession($sid,json_encode(['type'=>'code_cursor','session_id'=>$sid,'position'=>$data['position']??null,'user_id'=>(int)$meta['user_id']]),$from);}
    private function handleCodeSelection(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);if(!$this->requireCodeParticipant($from,$sid,(int)$meta['user_id']))return;$this->broadcastCodeSession($sid,json_encode(['type'=>'code_selection','session_id'=>$sid,'range'=>$data['range']??null,'user_id'=>(int)$meta['user_id']]),$from);}
    private function handleCodeExecution(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);$uid=(int)$meta['user_id'];if(!$this->requireCodeEditor($from,$sid,$uid))return;$payload=$data;$payload['type']='code_execution';$payload['session_id']=$sid;$payload['user_id']=$uid;$this->broadcastCodeSession($sid,json_encode($payload),$from);}
    private function handleCodeOutput(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);$uid=(int)$meta['user_id'];if(!$this->requireCodeEditor($from,$sid,$uid))return;$payload=['type'=>'code_output','session_id'=>$sid,'stdout'=>(string)($data['stdout']??''),'stderr'=>(string)($data['stderr']??''),'exit_code'=>isset($data['exit_code'])?(int)$data['exit_code']:null];$this->broadcastCodeSession($sid,json_encode($payload),$from);}
    private function handleCodeLeave(ConnectionInterface $from,array $data,array &$meta):void{$sid=(int)($data['session_id']??0);if($sid)$this->removeCodeConnection($from,$meta,$sid,true);}
    private function handleCodeSessionClosed(ConnectionInterface $from,array $data,array $meta):void{$sid=(int)($data['session_id']??0);$uid=(int)$meta['user_id'];if(!$sid||!$this->isCodeOwner($sid,$uid)){$this->codeError($from,'Only the coding-session owner can close the session.');return;}$this->ensureCodeSessionLoaded($sid);$this->persistCodeSession($sid);$this->broadcastCodeSession($sid,json_encode(['type'=>'code_session_closed','session_id'=>$sid]));foreach($this->codeSessions[$sid]['connections']??[] as $rid=>$entry)if(isset($this->connMeta[$rid]))$this->connMeta[$rid]['code_sessions']=array_values(array_filter($this->connMeta[$rid]['code_sessions']??[],fn($id)=>(int)$id!==$sid));unset($this->codeSessions[$sid]);}
    private function codeJoinConnection(ConnectionInterface $conn,array &$meta,int $sid,string $role,bool $announceCreate):void{$this->ensureCodeSessionLoaded($sid);$rid=$conn->resourceId;$uid=(int)$meta['user_id'];$already=isset($this->codeSessions[$sid]['connections'][$rid]);$this->codeSessions[$sid]['connections'][$rid]=['conn'=>$conn,'user_id'=>$uid,'username'=>$meta['username'],'role'=>$role];if(!in_array($sid,$meta['code_sessions']??[],true))$meta['code_sessions'][]=$sid;$conn->send(json_encode(['type'=>'code_joined','session_id'=>$sid,'version'=>(int)$this->codeSessions[$sid]['version'],'content'=>$this->codeSessions[$sid]['content'],'role'=>$role]));if(!$already&&!$announceCreate)$this->broadcastCodeSession($sid,json_encode(['type'=>'code_user_joined','session_id'=>$sid,'user_id'=>$uid,'username'=>$meta['username'],'role'=>$role]),$conn);}
    private function cleanupCodeConnection(ConnectionInterface $conn,array $meta):void{foreach(array_values($meta['code_sessions']??[]) as $sid)$this->removeCodeConnection($conn,$meta,(int)$sid,true);}
    private function removeCodeConnection(ConnectionInterface $conn,array &$meta,int $sid,bool $broadcast):void{if(!isset($this->codeSessions[$sid])){$meta['code_sessions']=array_values(array_filter($meta['code_sessions']??[],fn($id)=>(int)$id!==$sid));return;}$rid=$conn->resourceId;$entry=$this->codeSessions[$sid]['connections'][$rid]??null;unset($this->codeSessions[$sid]['connections'][$rid]);$meta['code_sessions']=array_values(array_filter($meta['code_sessions']??[],fn($id)=>(int)$id!==$sid));if($broadcast&&$entry!==null){$uid=(int)($entry['user_id']??$meta['user_id']??0);$still=false;foreach($this->codeSessions[$sid]['connections'] as $peer)if((int)$peer['user_id']===$uid){$still=true;break;}if(!$still)$this->broadcastCodeSession($sid,json_encode(['type'=>'code_user_left','session_id'=>$sid,'user_id'=>$uid,'username'=>$entry['username']??($meta['username']??'')]));}}
    private function requireCodeParticipant(ConnectionInterface $from,int $sid,int $uid):bool{if(!$sid||!$this->getCodeRole($sid,$uid)){$this->codeError($from,'Coding-session membership is required.');return false;}if(!$this->isCodeConnectionPresent($sid,$from)){$this->codeError($from,'Join the coding session first.');return false;}return true;}
    private function requireCodeEditor(ConnectionInterface $from,int $sid,int $uid):bool{if(!$this->requireCodeParticipant($from,$sid,$uid))return false;if($this->getCodeRole($sid,$uid)!=='editor'){$this->codeError($from,'Editor access is required.');return false;}return true;}
    private function getCodeRole(int $sid,int $uid):?string{$stmt=$this->db->prepare('SELECT owner_id FROM coding_sessions WHERE id=:sid LIMIT 1');$stmt->execute([':sid'=>$sid]);$owner=$stmt->fetchColumn();if($owner===false)return null;if((int)$owner===$uid)return'editor';$stmt=$this->db->prepare("SELECT role FROM coding_session_participants WHERE session_id=:sid AND user_id=:uid AND status='approved' LIMIT 1");$stmt->execute([':sid'=>$sid,':uid'=>$uid]);$role=$stmt->fetchColumn();return $role===false?null:(string)$role;}
    private function isCodeOwner(int $sid,int $uid):bool{$stmt=$this->db->prepare('SELECT 1 FROM coding_sessions WHERE id=:sid AND owner_id=:uid LIMIT 1');$stmt->execute([':sid'=>$sid,':uid'=>$uid]);return(bool)$stmt->fetchColumn();}
    private function isCodeConnectionPresent(int $sid,ConnectionInterface $conn):bool{return isset($this->codeSessions[$sid]['connections'][$conn->resourceId]);}
    private function loadCodeSession(int $sid,string $content,int $version):void{if(isset($this->codeSessions[$sid]))return;$this->codeSessions[$sid]=['version'=>max(0,$version),'content'=>$content,'connections'=>[],'dirty_changes'=>0,'last_persist_at'=>microtime(true)];}
    private function ensureCodeSessionLoaded(int $sid):void{if(isset($this->codeSessions[$sid]))return;$stmt=$this->db->prepare('SELECT code_state,version FROM coding_sessions WHERE id=:sid LIMIT 1');$stmt->execute([':sid'=>$sid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)throw new \RuntimeException('Coding session not found.');$this->loadCodeSession($sid,(string)$row['code_state'],(int)$row['version']);}
    private function sendCodeResync(ConnectionInterface $from,int $sid):void{$this->ensureCodeSessionLoaded($sid);$from->send(json_encode(['type'=>'code_resync','session_id'=>$sid,'content'=>$this->codeSessions[$sid]['content'],'version'=>(int)$this->codeSessions[$sid]['version']]));}
    private function maybePersistCodeSession(int $sid):void{if(isset($this->codeSessions[$sid])&&$this->codeSessions[$sid]['dirty_changes']>=$this->codePersistEveryChanges)$this->persistCodeSession($sid);}
    private function persistDirtyCodeSessions():void{$now=microtime(true);foreach(array_keys($this->codeSessions) as $sid){if(($this->codeSessions[$sid]['dirty_changes']??0)<1)continue;if($now-(float)$this->codeSessions[$sid]['last_persist_at']>=$this->codePersistEverySeconds)$this->persistCodeSession((int)$sid);}}
    private function persistCodeSession(int $sid):void{if(!isset($this->codeSessions[$sid]))return;$stmt=$this->db->prepare('UPDATE coding_sessions SET code_state=:state,version=:version WHERE id=:sid');$stmt->execute([':state'=>$this->codeSessions[$sid]['content'],':version'=>(int)$this->codeSessions[$sid]['version'],':sid'=>$sid]);$this->codeSessions[$sid]['dirty_changes']=0;$this->codeSessions[$sid]['last_persist_at']=microtime(true);}
    private function broadcastCodeSession(int $sid,string $payload,?ConnectionInterface $exclude=null):void{foreach($this->codeSessions[$sid]['connections']??[] as $entry){$conn=$entry['conn'];if($exclude&&$conn===$exclude)continue;try{$conn->send($payload);}catch(\Exception){}}}
    private function codeError(ConnectionInterface $conn,string $message):void{$conn->send(json_encode(['type'=>'code_error','message'=>$message]));}

    private function authorizeChannelMessage(ConnectionInterface $conn,array $data,array $meta):bool
    {
        $wbTypes=['wb_op','wb_cursor','wb_leave','wb_state_save','wb_request_state'];
        $isWb=in_array((string)($data['type']??''),$wbTypes,true);
        $channelId=$isWb?(int)($meta['wb_channel_id']??0):(int)($data['channel_id']??$meta['channel_id']??$meta['voice_channel_id']??$meta['wb_channel_id']??0);
        $userId=(int)($meta['user_id']??0);
        if($channelId<=0||$userId<=0){$conn->send(json_encode(['type'=>'error','message'=>'Channel access denied']));return false;}
        try{$stmt=$this->db->prepare("SELECT c.is_private,c.is_locked,u.role,sm.user_id AS server_member_id,cm.user_id AS channel_member_id FROM channels c JOIN users u ON u.id=:uid_user AND u.deleted_at IS NULL LEFT JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid_server LEFT JOIN channel_members cm ON cm.channel_id=c.id AND cm.user_id=:uid_channel WHERE c.id=:cid AND c.type IN ('text','announcement','voice','whiteboard','study_room') LIMIT 1");$stmt->execute([':uid_user'=>$userId,':uid_server'=>$userId,':uid_channel'=>$userId,':cid'=>$channelId]);$channel=$stmt->fetch();}catch(\Throwable $e){error_log('[WS] channel authorization failed: '.$e->getMessage());$conn->send(json_encode(['type'=>'error','message'=>'Channel access denied']));return false;}
        if(!$channel){$conn->send(json_encode(['type'=>'error','message'=>'Channel access denied']));return false;}
        $priv=in_array($channel['role'],['admin','super_admin','moderator'],true);$server=$channel['server_member_id']!==null;$private=!$channel['is_private']||$channel['channel_member_id']!==null;$usable=!$channel['is_locked']||$priv;
        if(!$priv&&(!$server||!$private||!$usable)){$conn->send(json_encode(['type'=>'error','message'=>'Channel access denied']));return false;}return true;
    }

    private function handleJoinChannel(ConnectionInterface $conn,array $data,array &$meta):void{$channelId=(int)($data['channel_id']??0);if($channelId<=0)return;if($meta['channel_id'])$this->removeFromChannel($conn,(int)$meta['channel_id']);$meta['channel_id']=$channelId;$this->channelSubs[$channelId][]=$conn;$conn->send(json_encode(['type'=>'joined_channel','channel_id'=>$channelId]));}
    private function handleLeaveChannel(ConnectionInterface $conn,array $data,array &$meta):void{$channelId=(int)($data['channel_id']??$meta['channel_id']??0);if($channelId){$this->removeFromChannel($conn,$channelId);$meta['channel_id']=null;}}
    private function handleMessage(ConnectionInterface $from,array $data,array $meta):void{$channelId=(int)($data['channel_id']??$meta['channel_id']??0);if(!$channelId||empty($data['message']))return;$this->broadcastToChannel($channelId,json_encode(['type'=>'message','message'=>$data['message']]),$from);}
    private function handleTyping(ConnectionInterface $from,array $data,array $meta):void{$channelId=(int)($data['channel_id']??$meta['channel_id']??0);if(!$channelId)return;$this->broadcastToChannel($channelId,json_encode(['type'=>'typing','channel_id'=>$channelId,'user_id'=>$meta['user_id'],'username'=>$meta['username'],'typing'=>(bool)($data['typing']??false)]),$from);}
    private function handlePresence(ConnectionInterface $from,array $data,array $meta):void{$channelId=(int)($meta['channel_id']??0);$payload=json_encode(['type'=>'presence','user_id'=>$meta['user_id'],'online'=>true,'muted'=>(bool)($data['muted']??false)]);if($channelId)$this->broadcastToChannel($channelId,$payload,$from);}
    const DM_GROUP_VOICE_ID_OFFSET=2000000000;
    private function handleJoinVoice(ConnectionInterface $from,array $data,array &$meta):void
    {
        $channelId=(int)($data['channel_id']??0);
        if(!$channelId)return;
        $uid=(int)$meta['user_id'];
        $isDm=$channelId>=self::DM_GROUP_VOICE_ID_OFFSET;

        if($isDm){
            $gid=$channelId-self::DM_GROUP_VOICE_ID_OFFSET;
            $mem=$this->db->prepare('SELECT 1 FROM dm_group_members WHERE group_id=:gid AND user_id=:uid');
            $mem->execute([':gid'=>$gid,':uid'=>$uid]);
            if(!$mem->fetchColumn())return;
        }else{
            $chk=$this->db->prepare("SELECT 1 FROM channels c JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid WHERE c.id=:cid AND c.type='voice'");
            $chk->execute([':uid'=>$uid,':cid'=>$channelId]);
            if(!$chk->fetchColumn())return;
        }

        $stmt=$this->db->prepare('SELECT id,username,full_name,avatar_color_gradient,role FROM users WHERE id=:id');
        $stmt->execute([':id'=>$uid]);
        $user=$stmt->fetch()?:[];

        // A connection can only belong to one voice room. Remove it from any
        // previous room, but notify only that room's participants.
        foreach($this->voiceRooms as $oldId=>&$participants){
            $wasIn=array_filter($participants,fn($p)=>$p['user_id']===$uid);
            if(!empty($wasIn)){
                $participants=array_values(array_filter($participants,fn($p)=>$p['user_id']!==$uid));
                $leave=json_encode(['type'=>'voice_leave','user_id'=>$uid,'username'=>$meta['username'],'channel_id'=>(int)$oldId]);
                foreach($participants as $p){
                    foreach($this->userConns[(int)$p['user_id']]??[] as $peerConn){
                        try{$peerConn->send($leave);}catch(\Exception){}
                    }
                }
            }
        }
        unset($participants);

        $meta['voice_channel_id']=$channelId;
        $existing=array_values($this->voiceRooms[$channelId]??[]);
        $this->voiceRooms[$channelId]??=[];
        $this->voiceRooms[$channelId][]=[
            'user_id'=>$uid,
            'username'=>$meta['username'],
            'full_name'=>$user['full_name']??$meta['username'],
            'avatar_color_gradient'=>$user['avatar_color_gradient']??'#3b82f6,#6366f1',
            'role'=>$user['role']??'student',
            'resourceId'=>$from->resourceId
        ];

        if(!$isDm){
            try{$this->db->prepare("UPDATE users SET voice_channel_id=:cid WHERE id=:id")->execute([':cid'=>$channelId,':id'=>$uid]);}catch(\Exception){}
        }

        $payload=json_encode(['type'=>'voice_join','user'=>$user,'channel_id'=>$channelId]);
        // CRITICAL: voice events are scoped strictly to this room.
        foreach($existing as $p){
            foreach($this->userConns[(int)$p['user_id']]??[] as $peerConn){
                try{$peerConn->send($payload);}catch(\Exception){}
            }
        }

        $peers=array_values(array_map(fn($p)=>[
            'user_id'=>$p['user_id'],
            'username'=>$p['username'],
            'full_name'=>$p['full_name']??$p['username'],
            'avatar_color_gradient'=>$p['avatar_color_gradient']??'#3b82f6,#6366f1',
            'role'=>$p['role']??'student',
            'muted'=>$p['muted']??false
        ],$existing));
        $from->send(json_encode(['type'=>'voice_peers','peers'=>$peers,'channel_id'=>$channelId]));
    }

    private function handleLeaveVoice(ConnectionInterface $from,array $data,array &$meta):void
    {
        $uid=(int)$meta['user_id'];
        $channelId=(int)($meta['voice_channel_id']??0);
        $remaining=[];
        $isDm=$channelId>=self::DM_GROUP_VOICE_ID_OFFSET;

        if($channelId&&isset($this->voiceRooms[$channelId])){
            $this->voiceRooms[$channelId]=array_values(array_filter($this->voiceRooms[$channelId],fn($p)=>$p['user_id']!==$uid));
            $remaining=$this->voiceRooms[$channelId];
            if(empty($this->voiceRooms[$channelId]))unset($this->voiceRooms[$channelId]);
        }

        $meta['voice_channel_id']=null;
        if(!$isDm){
            try{$this->db->prepare("UPDATE users SET voice_channel_id=NULL WHERE id=:id")->execute([':id'=>$uid]);}catch(\Exception){}
        }

        $payload=json_encode(['type'=>'voice_leave','user_id'=>$uid,'username'=>$meta['username'],'channel_id'=>$channelId]);
        foreach($remaining as $p){
            foreach($this->userConns[(int)$p['user_id']]??[] as $peerConn){
                try{$peerConn->send($payload);}catch(\Exception){}
            }
        }
    }

    private function handleWebRtcSignal(ConnectionInterface $from,array $data,array $meta,string $type):void{$target=(int)($data['target_user_id']??0);$vc=(int)($meta['voice_channel_id']??0);$peer=false;foreach($this->voiceRooms[$vc]??[] as $p)if((int)$p['user_id']===$target){$peer=true;break;}if(!$vc||!$target||!$peer||empty($this->userConns[$target])){$from->send(json_encode(['type'=>'error','message'=>'Peer not connected to this voice channel']));return;}$payload=json_encode(['type'=>$type,'from_user_id'=>$meta['user_id'],'from_username'=>$meta['username'],'channel_id'=>$vc,'sdp'=>$data['sdp']??null,'candidate'=>$data['candidate']??null,'is_screen_offer'=>$data['is_screen_offer']??false]);foreach($this->userConns[$target] as $peerConn)try{$peerConn->send($payload);}catch(\Exception $e){error_log('[WS] WebRTC relay error: '.$e->getMessage());}}
    private function handleScreenShareNotify(ConnectionInterface $from,array $data,array $meta):void{$uid=(int)$meta['user_id'];$cid=(int)($meta['voice_channel_id']??$meta['channel_id']??0);$payload=json_encode(['type'=>'screen_share_notify','user_id'=>$uid,'username'=>$meta['username'],'active'=>(bool)($data['active']??false),'channel_id'=>$cid]);foreach($this->voiceRooms[$cid]??[] as $p){$pid=(int)$p['user_id'];if($pid===$uid)continue;foreach($this->userConns[$pid]??[] as $conn)try{$conn->send($payload);}catch(\Exception){}}}
    private function handleDeletedBroadcast(ConnectionInterface $from,array $data,array $meta):void{$cid=(int)($meta['channel_id']??0);$mid=(int)($data['message_id']??0);if(!$cid||!$mid)return;$this->broadcastToChannel($cid,json_encode(['type'=>'message_deleted','message_id'=>$mid]),$from);}
    private function handleWhiteboardSync(ConnectionInterface $from,array $data,array $meta):void{$cid=(int)($data['channel_id']??$meta['channel_id']??0);if(!$cid)return;$this->broadcastToChannel($cid,json_encode(['type'=>'whiteboard_sync','channel_id'=>$cid,'user_id'=>$meta['user_id'],'state_json'=>$data['state_json']??'']),$from);}
    private function handleConnectionRequest(ConnectionInterface $from,array $data,array $meta):void{$id=(int)($data['addressee_id']??0);if(!$id)return;foreach($this->userConns[$id]??[] as $peerConn)try{$peerConn->send(json_encode(['type'=>'connection_request','request_id'=>$data['request_id']??null,'addressee_id'=>$id,'requester'=>$data['requester']??[]]));}catch(\Exception){}}
    private function handleDmMessage(ConnectionInterface $from,array $data,array $meta):void{DmHandler::handleDmMessage($from,$data,$meta,$this->userConns,$this->db);}
    private function handleDmTyping(ConnectionInterface $from,array $data,array $meta):void{DmHandler::handleDmTyping($from,$data,$meta,$this->userConns,$this->db);}
    private function handleDmGroupMessage(ConnectionInterface $from,array $data,array $meta):void{DmHandler::handleDmGroupMessage($from,$data,$meta,$this->userConns,$this->db);}
    private function handleDmGroupTyping(ConnectionInterface $from,array $data,array $meta):void{DmHandler::handleDmGroupTyping($from,$data,$meta,$this->userConns,$this->db);}
    private function handleNotifyConnReq(ConnectionInterface $from,array $data,array $meta):void{DmHandler::handleNotifyConnReq($from,$data,$meta,$this->userConns,$this->db);}
    private function handleNotifyConnAccepted(ConnectionInterface $from,array $data,array $meta):void{DmHandler::handleNotifyConnAccepted($from,$data,$meta,$this->userConns,$this->db);}
    private function handleVoiceInvite(ConnectionInterface $from,array $data,array $meta):void{DmHandler::handleVoiceInvite($from,$data,$meta,$this->userConns,$this->db);}
    private function handleDmCallSignal(ConnectionInterface $from,array $data,array $meta,string $type):void{DmHandler::handleDmCallSignal($from,$data,$meta,$this->userConns,$this->db,$type);}
    private function handleNoteRelay(ConnectionInterface $from,array $data,array $meta):void{$cid=(int)($data['channel_id']??$meta['channel_id']??0);if(!$cid)return;foreach($this->channelSubs[$cid]??[] as $conn){if($conn===$from)continue;try{$conn->send(json_encode($data));}catch(\Exception){}}}
    private function handleEditedBroadcast(ConnectionInterface $from,array $data,array $meta):void{$cid=(int)($data['channel_id']??$meta['channel_id']??0);if(!$cid||empty($data['message']))return;$this->broadcastToChannel($cid,json_encode(['type'=>'message_edited','message'=>$data['message']]),$from);}
    private function handlePinnedBroadcast(ConnectionInterface $from,array $data,array $meta):void{$cid=(int)($data['channel_id']??$meta['channel_id']??0);if(!$cid)return;$this->broadcastToChannel($cid,json_encode(['type'=>'message_pinned','channel_id'=>$cid,'message_id'=>(int)($data['message_id']??0),'pinned'=>(bool)($data['pinned']??true),'pinned_by'=>$meta['username']]),$from);}
    private function handleChannelSeen(ConnectionInterface $from,array $data,array $meta):void{$cid=(int)($data['channel_id']??0);if(!$cid)return;$uid=(int)$meta['user_id'];$payload=json_encode(['type'=>'channel_seen','channel_id'=>$cid,'user_id'=>$uid]);foreach($this->userConns[$uid]??[] as $conn){if($conn===$from)continue;try{$conn->send($payload);}catch(\Exception){}}}
    private function handleDraftSave(ConnectionInterface $from,array $data,array $meta):void{$cid=(int)($data['channel_id']??0);if(!$cid)return;$uid=(int)$meta['user_id'];$payload=json_encode(['type'=>'draft_saved','channel_id'=>$cid,'channel_name'=>$data['channel_name']??'','text'=>$data['text']??'']);foreach($this->userConns[$uid]??[] as $conn){if($conn===$from)continue;try{$conn->send($payload);}catch(\Exception){}}}
    private function handleThreadReply(ConnectionInterface $from,array $data,array $meta):void{$cid=(int)($data['channel_id']??$meta['channel_id']??0);$pid=(int)($data['parent_id']??0);if(!$cid||!$pid||empty($data['reply']))return;$this->broadcastToChannel($cid,json_encode(['type'=>'thread_reply','channel_id'=>$cid,'parent_id'=>$pid,'reply'=>$data['reply']]),$from);}
    private function handleMentionRelay(ConnectionInterface $from,array $data,array $meta):void{$target=(int)($data['target_user_id']??0);if(!$target)return;foreach($this->userConns[$target]??[] as $conn)try{$conn->send(json_encode(['type'=>'mention','entry'=>$data['entry']??[]]));}catch(\Exception){}}
    private function broadcastToChannel(int $cid,string $payload,?ConnectionInterface $exclude=null):void{foreach($this->channelSubs[$cid]??[] as $conn){if($exclude&&$conn===$exclude)continue;try{$conn->send($payload);}catch(\Exception $e){echo "[WS] Send error: {$e->getMessage()}\n";}}}
    private function broadcastToAll(string $payload,?ConnectionInterface $exclude=null):void{foreach($this->clients as $client){if($exclude&&$client===$exclude)continue;$rid=$client->resourceId;if(empty($this->connMeta[$rid]['authed']))continue;try{$client->send($payload);}catch(\Exception){}}}
    private function removeFromChannel(ConnectionInterface $conn,int $cid):void{if(!isset($this->channelSubs[$cid]))return;$this->channelSubs[$cid]=array_values(array_filter($this->channelSubs[$cid],fn($c)=>$c!==$conn));if(empty($this->channelSubs[$cid]))unset($this->channelSubs[$cid]);}
    private function setUserOnline(int $uid,bool $online):void{try{$stmt=$this->db->prepare("UPDATE users SET is_online=:o,last_active_at=NOW() WHERE id=:id");$stmt->execute([':o'=>(int)$online,':id'=>$uid]);}catch(\Exception $e){echo "[WS] DB error: {$e->getMessage()}\n";}}
    private function broadcastPresence(int $uid,bool $online,string $username):void{$payload=json_encode(['type'=>'presence','user_id'=>$uid,'username'=>$username,'online'=>$online]);foreach($this->clients as $client)try{$client->send($payload);}catch(\Exception){}}

    private function handleWbJoin(ConnectionInterface $from,array $data,array &$meta):void
    {
        $channelId=(int)($data['channel_id']??0); if($channelId<=0)return;
        $uid=(int)$meta['user_id']; $username=(string)$meta['username'];
        $whiteboardId=array_key_exists('whiteboard_id',$data)?(int)$data['whiteboard_id']:null;
        if($whiteboardId!==null&&$whiteboardId<1)$whiteboardId=null;
        $prevChannelId=$meta['wb_channel_id']??null; $prevWhiteboardId=$meta['wb_whiteboard_id']??null;
        $isSameRoom=$prevChannelId!==null&&(int)$prevChannelId===$channelId&&(($prevWhiteboardId===null&&$whiteboardId===null)||($prevWhiteboardId!==null&&$whiteboardId!==null&&(int)$prevWhiteboardId===$whiteboardId));
        if($prevChannelId!==null&&!$isSameRoom){
            $remaining=$this->wbHandler->leave((int)$prevChannelId,$uid,$prevWhiteboardId!==null?(int)$prevWhiteboardId:null);
            $leaveNotify=json_encode(['type'=>'wb_peer_left','channel_id'=>(int)$prevChannelId,'whiteboard_id'=>$prevWhiteboardId!==null?(int)$prevWhiteboardId:null,'user_id'=>$uid,'username'=>$username]);
            foreach($remaining as $peerId)foreach($this->userConns[(int)$peerId]??[] as $peerConn)try{$peerConn->send($leaveNotify);}catch(\Exception){}
            $meta['wb_channel_id']=null;$meta['wb_whiteboard_id']=null;
        }elseif($isSameRoom)return;
        $access=$this->wbHandler->authorize($channelId,$uid,$whiteboardId);
        if($access===null){$from->send(json_encode(['type'=>'error','message'=>'Whiteboard access denied','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId]));return;}
        $init=$this->wbHandler->join($channelId,$uid,$username,$whiteboardId);
        $meta['wb_channel_id']=$channelId; $meta['wb_whiteboard_id']=$whiteboardId;
        $from->send(json_encode($init));
        $notify=json_encode(['type'=>'wb_peer_joined','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId,'peer'=>$init['you']]);
        foreach($init['peers'] as $peer)foreach($this->userConns[(int)$peer['user_id']]??[] as $peerConn)try{$peerConn->send($notify);}catch(\Exception){}
    }

    private function handleWbLeave(ConnectionInterface $from,array $data,array &$meta):void
    {
        $channelId=(int)($meta['wb_channel_id']??0); if($channelId<=0)return;
        $whiteboardId=$meta['wb_whiteboard_id']??null; if($whiteboardId!==null)$whiteboardId=(int)$whiteboardId;
        $uid=(int)$meta['user_id'];
        if(!empty($data['state_json'])&&$this->wbHandler->canEdit($channelId,$uid,$whiteboardId))$this->wbHandler->persistSnapshot($channelId,$uid,(string)$data['state_json'],$whiteboardId);
        $remaining=$this->wbHandler->leave($channelId,$uid,$whiteboardId);$meta['wb_channel_id']=null;$meta['wb_whiteboard_id']=null;
        $notify=json_encode(['type'=>'wb_peer_left','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId,'user_id'=>$uid,'username'=>$meta['username']]);
        foreach($remaining as $peerId)foreach($this->userConns[(int)$peerId]??[] as $peerConn)try{$peerConn->send($notify);}catch(\Exception){}
    }

    private function handleWbOp(ConnectionInterface $from,array $data,array $meta):void
    {
        $channelId=(int)($meta['wb_channel_id']??0); if($channelId<=0||empty($data['op']))return;
        $whiteboardId=$meta['wb_whiteboard_id']??null; if($whiteboardId!==null)$whiteboardId=(int)$whiteboardId;
        $uid=(int)$meta['user_id']; $opType=(string)$data['op'];
        if(!$this->wbHandler->canPerformOp($channelId,$uid,$opType,$whiteboardId)){$from->send(json_encode(['type'=>'wb_locked','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId,'message'=>'Whiteboard edit permission denied.']));return;}
        $stamped=$this->wbHandler->recordOp($channelId,$uid,$data,$whiteboardId);
        $payload=json_encode(array_merge(['type'=>'wb_op','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId],$stamped));
        foreach($this->wbHandler->getRoomUserIds($channelId,$uid,$whiteboardId) as $peerId)foreach($this->userConns[(int)$peerId]??[] as $peerConn)try{$peerConn->send($payload);}catch(\Exception){}
    }

    private function handleWbCursor(ConnectionInterface $from,array $data,array $meta):void
    {
        $channelId=(int)($meta['wb_channel_id']??0);if($channelId<=0)return;$whiteboardId=$meta['wb_whiteboard_id']??null;if($whiteboardId!==null)$whiteboardId=(int)$whiteboardId;$uid=(int)$meta['user_id'];$peer=$this->wbHandler->getUserMeta($channelId,$uid,$whiteboardId);
        $payload=json_encode(['type'=>'wb_cursor','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId,'user_id'=>$uid,'username'=>$meta['username'],'color'=>$peer['color']??'#a855f7','initial'=>$peer['initial']??'?','x'=>$data['x']??0,'y'=>$data['y']??0]);
        foreach($this->wbHandler->getRoomUserIds($channelId,$uid,$whiteboardId) as $peerId)foreach($this->userConns[(int)$peerId]??[] as $peerConn)try{$peerConn->send($payload);}catch(\Exception){}
    }

    private function handleWbStateSave(ConnectionInterface $from,array $data,array $meta):void
    {
        $channelId=(int)($meta['wb_channel_id']??0);if($channelId<=0||empty($data['state_json']))return;$whiteboardId=$meta['wb_whiteboard_id']??null;if($whiteboardId!==null)$whiteboardId=(int)$whiteboardId;$uid=(int)$meta['user_id'];
        if(!$this->wbHandler->canEdit($channelId,$uid,$whiteboardId)){$from->send(json_encode(['type'=>'wb_locked','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId,'message'=>'Whiteboard edit permission denied.']));return;}
        $this->wbHandler->persistSnapshot($channelId,$uid,(string)$data['state_json'],$whiteboardId);$from->send(json_encode(['type'=>'wb_state_saved','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId]));
    }

    private function handleWbRequestState(ConnectionInterface $from,array $data,array $meta):void
    {
        $channelId=(int)($meta['wb_channel_id']??0);if($channelId<=0)return;$whiteboardId=$meta['wb_whiteboard_id']??null;if($whiteboardId!==null)$whiteboardId=(int)$whiteboardId;$state=$this->wbHandler->getState($channelId,$whiteboardId);
        $from->send(json_encode(['type'=>'wb_state','channel_id'=>$channelId,'whiteboard_id'=>$whiteboardId,'state_json'=>$state,'members'=>$this->wbHandler->getMembers($channelId,$whiteboardId)]));
    }
}

if(PHP_SAPI==='cli'&&isset($_SERVER['SCRIPT_FILENAME'])&&realpath($_SERVER['SCRIPT_FILENAME'])===realpath(__FILE__)){$options=getopt('',['port::','host::']);$port=(int)($options['port']??getenv('WS_PORT')?:8080);$host=$options['host']??getenv('WS_HOST')?:'0.0.0.0';$loop=\React\EventLoop\Loop::get();$chat=new ChatServer();$server=\Ratchet\Server\IoServer::factory(new \Ratchet\Http\HttpServer(new \Ratchet\WebSocket\WsServer($chat)),$port,$host);$loop->addPeriodicTimer(0.2,static function()use($chat):void{$chat->drainRelayTable();$chat->persistDirtyCodeSessions();});echo "Ecollab WebSocket server running on {$host}:{$port}\n";$server->run();}
