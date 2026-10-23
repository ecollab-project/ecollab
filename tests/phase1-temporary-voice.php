<?php
declare(strict_types=1);
require_once __DIR__.'/../services/TemporaryVoiceService.php';
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
// Construction must work without any tables: no request-time DDL or schema probe.
$service = new TemporaryVoiceService($db);
check((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn() === 0, 'Constructor did DDL');
$db->exec('CREATE TABLE channels(id INTEGER PRIMARY KEY); CREATE TABLE users(id INTEGER PRIMARY KEY,voice_channel_id INTEGER); CREATE TABLE channel_members(channel_id INTEGER,user_id INTEGER); CREATE TABLE temporary_voice_rooms(channel_id INTEGER PRIMARY KEY,empty_since TEXT,expires_at TEXT)');
$db->exec("INSERT INTO channels VALUES(1),(2),(3); INSERT INTO users VALUES(10,NULL),(11,NULL); INSERT INTO temporary_voice_rooms VALUES(1,'old','old'),(2,NULL,NULL); INSERT INTO channel_members VALUES(1,10),(2,10)");
$allowed = fn()=>null;
$service->join(10,1,$allowed);
check($db->query('SELECT expires_at FROM temporary_voice_rooms WHERE channel_id=1')->fetchColumn() === null, 'Join clears expiry');
$service->join(11,1,$allowed);
$service->leave(10,1);
check((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=1')->fetchColumn() === 1, 'Occupied room retained');
$service->join(10,2,$allowed);
$service->leave(10,1);
check((int)$db->query('SELECT voice_channel_id FROM users WHERE id=10')->fetchColumn() === 2, 'Stale leave cleared new room');
$service->leave(11,1);
check((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=1')->fetchColumn() === 0, 'Last leave deletes temporary room');
try { $service->join(11,1,$allowed); throw new LogicException('Deleted room joined'); } catch (RuntimeException $e) { check($e->getCode()===403,'Deleted join denied'); }
try { $service->join(11,2,fn()=>throw new RuntimeException('deny',403)); throw new LogicException('Authorization ignored'); } catch (RuntimeException $e) { check($e->getCode()===403,'Denial propagated'); }
check(!$db->inTransaction(), 'Denied join left transaction open');
$service->join(11,3,$allowed);$service->leave(11,3);
check((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=3')->fetchColumn() === 1, 'Permanent room deleted');
echo "PASS temporary voice: DDL-free constructor, occupancy, expiry reset, stale leave, denial rollback, permanent rooms\n";
