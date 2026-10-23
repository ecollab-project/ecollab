<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/AiSessionService.php';
require dirname(__DIR__).'/services/JarredConversation.php';
function retentionCheck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON;CREATE TABLE users(id INTEGER PRIMARY KEY,deleted_at TEXT);CREATE TABLE ai_sessions(id INTEGER PRIMARY KEY,user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,class_id INTEGER,session_title TEXT,message_count INTEGER DEFAULT 0,token_cost INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE ai_messages(id INTEGER PRIMARY KEY,session_id INTEGER REFERENCES ai_sessions(id) ON DELETE CASCADE,role TEXT,content TEXT,token_count INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP);INSERT INTO users VALUES(1,NULL),(2,NULL)");
$service=new AiSessionService($db);$own=$service->createSession(1,null,'Synthetic first');$other=$service->createSession(2,null,'Synthetic second');
$service->appendExchange(1,$own['id'],'Synthetic prompt','Synthetic reply',2,3);$service->appendExchange(2,$other['id'],'Other prompt','Other reply',2,3);
foreach(['getMessages','deleteSession'] as $method){try{$service->$method(2,$own['id']);throw new LogicException('Cross-owner access accepted');}catch(RuntimeException $e){retentionCheck($e->getCode()===404,'Wrong ownership error');}}
$prefs=UserPreferences::defaults();$prefs['jarred_memory']=0;$prefs['jarred_personalization']=0;$prefs['jarred_saved_context']='PRIVATE_SAVED_CONTEXT';
retentionCheck(!str_contains(JarredConversation::instructions($prefs),'PRIVATE_SAVED_CONTEXT'),'Disabled personalization sent saved context');
retentionCheck(count($service->getMessages(1,$own['id']))===2,'Preference opt-out deleted existing history');
$db->exec('UPDATE users SET deleted_at=CURRENT_TIMESTAMP WHERE id=1');retentionCheck((int)$db->query('SELECT COUNT(*) FROM ai_messages')->fetchColumn()===4,'Soft deletion unexpectedly purged records');
$service->deleteSession(1,$own['id']);retentionCheck((int)$db->query('SELECT COUNT(*) FROM ai_messages')->fetchColumn()===2,'Owned session delete failed to cascade');
retentionCheck(count($service->getMessages(2,$other['id']))===2,'Unrelated conversation was modified');
$db->exec('DELETE FROM users WHERE id=2');retentionCheck((int)$db->query('SELECT COUNT(*) FROM ai_messages')->fetchColumn()===0,'Hard-delete FK fixture failed');
echo "PASS: synthetic session ownership, explicit cascade deletion, unrelated records, personalization opt-out and soft-versus-hard deletion; no production deletion or automatic retention policy\n";
