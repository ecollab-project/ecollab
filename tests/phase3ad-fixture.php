<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$ids=array_map('intval',$in['users']);
if(count($ids)!==4||count(array_unique($ids))!==4)throw new RuntimeException('Four actors required');
foreach($ids as$id){$s=$db->prepare('SELECT email FROM users WHERE id=?');$s->execute([$id]);if(!preg_match('/^phase3y[a-z0-9_-]+@example\.test$/D',(string)$s->fetchColumn()))throw new RuntimeException('Owned actor required');}
[$student,$peer,$fac,$outsider]=$ids;$db->prepare("UPDATE users SET role='facilitator' WHERE id=?")->execute([$fac]);
$db->prepare("INSERT INTO servers(owner_id,name,slug,type,visibility,member_count) VALUES(?,'Phase3AD Owned Classroom',?,'private','private',3)")->execute([$fac,'phase3ad-'.$fac]);$sid=(int)$db->lastInsertId();
foreach([$student,$peer,$fac]as$id)$db->prepare('INSERT INTO server_members(server_id,user_id,server_role) VALUES(?,?,?)')->execute([$sid,$id,$id===$fac?'owner':'member']);
$channels=[];foreach(['general'=>0,'private'=>1,'announcements'=>0]as$name=>$private){$db->prepare("INSERT INTO channels(server_id,name,slug,type,position,is_private,created_by,member_count) VALUES(?,?,?,?,?,?,?,3)")->execute([$sid,'Phase3AD '.$name,$name,$name==='announcements'?'announcement':'text',count($channels),$private,$fac]);$channels[$name]=(int)$db->lastInsertId();}
foreach([$student,$fac]as$id)$db->prepare('INSERT INTO channel_members(channel_id,user_id) VALUES(?,?)')->execute([$channels['private'],$id]);
$db->prepare("INSERT INTO messages(channel_id,sender_id,content) VALUES(?,?,'Owned seeded history')")->execute([$channels['general'],$peer]);
$db->prepare("INSERT INTO notifications(recipient_id,actor_id,type,title,body) VALUES(?,?,'system','Phase3AD notification','Owned fixture')")->execute([$student,$fac]);$nid=(int)$db->lastInsertId();
$subject=(int)$db->query('SELECT id FROM pm_subjects ORDER BY id LIMIT 1')->fetchColumn();if(!$subject){$db->exec("INSERT INTO pm_subjects(name,slug) VALUES('Phase3AD Subject','phase3ad-subject')");$subject=(int)$db->lastInsertId();}
foreach([$student,$peer]as$id)$db->prepare("INSERT IGNORE INTO pm_user_subjects(user_id,subject_id,role,proficiency) VALUES(?,?,'studying','intermediate')")->execute([$id,$subject]);
$db->prepare("INSERT INTO threads(title,body,scope,server_id,channel_id,created_by) VALUES('Phase3AD seeded thread','Owned body','channel',?,?,?)")->execute([$sid,$channels['general'],$peer]);$tid=(int)$db->lastInsertId();
$db->prepare("INSERT INTO thread_attachments(thread_id,file_url,file_name,mime_type,file_size,created_by) VALUES(?,'/owned-not-a-download.txt','phase3ad.txt','text/plain',12,?)")->execute([$tid,$peer]);
echo json_encode(['server'=>$sid,'channels'=>$channels,'thread'=>$tid,'notification'=>$nid,'users'=>$ids,'roles'=>['student','student','facilitator','student'],'seeded_attachment'=>'metadata only;actual upload/download covered separately,not certified here','session_seeded'=>false],JSON_THROW_ON_ERROR);
