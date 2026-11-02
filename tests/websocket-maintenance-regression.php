<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
ini_set('display_errors', '1');
error_reporting(E_ALL);
function verifyMaintenance(bool $ok, string $label): void {if (!$ok) throw new RuntimeException($label);}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE coding_sessions(id INTEGER PRIMARY KEY, code_state TEXT, version INTEGER); INSERT INTO coding_sessions VALUES(4,'old',1),(5,'fresh',1)");
$server = (new ReflectionClass(ChatServer::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ChatServer::class, 'db'))->setValue($server, $db);
(new ReflectionProperty(ChatServer::class, 'codeSessions'))->setValue($server, [
 4 => ['content'=>'saved','version'=>2,'dirty_changes'=>1,'last_persist_at'=>microtime(true)-20],
 5 => ['content'=>'pending','version'=>2,'dirty_changes'=>1,'last_persist_at'=>microtime(true)],
]);
// The event-loop entry points call this public maintenance boundary directly.
$server->persistDirtyCodeSessions();
verifyMaintenance($db->query('SELECT code_state FROM coding_sessions WHERE id=4')->fetchColumn()==='saved', 'Due dirty state was not persisted');
verifyMaintenance($db->query('SELECT code_state FROM coding_sessions WHERE id=5')->fetchColumn()==='fresh', 'Fresh state was flushed too early');
$state=(new ReflectionProperty(ChatServer::class,'codeSessions'))->getValue($server);
verifyMaintenance($state[4]['dirty_changes']===0 && $state[5]['dirty_changes']===1,'Dirty counters changed incorrectly');
$server->persistDirtyCodeSessions();
verifyMaintenance((int)$db->query('SELECT version FROM coding_sessions WHERE id=4')->fetchColumn()===2,'Second maintenance changed persisted version');
echo "PASS: public WebSocket timer flush, due/early saves, dirty counters and repeat maintenance\n";
// IoServer's integer ID must survive Ratchet's magic-property decorators.
(new ReflectionProperty(ChatServer::class,'clients'))->setValue($server,new SplObjectStorage());
$connection = new class implements Ratchet\ConnectionInterface {
    public int $resourceId = 73;
    public function send($data) { return $this; }
    public function close() {}
};
$decorator = new class($connection) extends Ratchet\AbstractConnectionDecorator {
    public function send($data) { $this->getConnection()->send($data); return $this; }
    public function close() { $this->getConnection()->close(); }
};
ob_start();$server->onOpen($decorator);ob_end_clean();
$meta=(new ReflectionProperty(ChatServer::class,'connMeta'))->getValue($server);
verifyMaintenance(isset($meta[73]) && $meta[73]['authed']===false,'Decorated connection ID was not retained');
echo "PASS: Ratchet decorated resource ID registration\n";
