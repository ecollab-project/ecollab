<?php
declare(strict_types=1);
// Destructive fixture creation ONLY in the dedicated CI/test database named collabora_test.
$dsn = getenv('COLLABORA_TEST_DSN');
if (!$dsn || !str_ends_with($dsn, ';dbname=collabora_test')) { fwrite(STDERR, "Set COLLABORA_TEST_DSN to a dedicated collabora_test database. Never use production.\n"); exit(2); }
$root = sys_get_temp_dir() . '/ecollab-wopi-' . bin2hex(random_bytes(6));
mkdir($root . '/uploads/collab-docs', 0700, true);
define('ROOT_PATH', $root); define('DB_HOST', 'test');
require_once __DIR__ . '/../services/CollaboraService.php';
$db = new PDO($dsn, 'root', getenv('COLLABORA_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); echo "PASS: $label\n"; }
function denied(callable $fn, int $code, string $label): void { try {$fn();} catch(RuntimeException $e) { check($e->getCode()===$code,$label);return;}throw new RuntimeException('FAIL: '.$label); }
try {
    foreach(['collab_wopi_tokens','collab_document_versions','collab_resource_permissions','collab_workspace_members','collab_documents','collab_workspaces','server_members','servers','users'] as $t) $db->exec('DROP TABLE IF EXISTS '.$t);
    $db->exec('CREATE TABLE users(id INT PRIMARY KEY,status VARCHAR(20)); INSERT INTO users VALUES(1,"active"),(2,"active"),(3,"active"),(4,"active"); CREATE TABLE servers(id INT PRIMARY KEY,status VARCHAR(20)); INSERT INTO servers VALUES(1,"active"); CREATE TABLE server_members(server_id INT,user_id INT,status VARCHAR(20)); INSERT INTO server_members VALUES(1,1,"active"),(1,2,"active"),(1,3,"active"),(1,4,"active")');
    $db->exec('CREATE TABLE collab_workspaces(id INT PRIMARY KEY,server_id INT,host_id INT,visibility VARCHAR(20),archived INT); INSERT INTO collab_workspaces VALUES(1,1,1,"public",0); CREATE TABLE collab_workspace_members(workspace_id INT,user_id INT,role VARCHAR(20)); CREATE TABLE collab_resource_permissions(resource_type VARCHAR(20),resource_id INT,workspace_id INT,user_id INT,permission VARCHAR(20)); INSERT INTO collab_resource_permissions VALUES("document",1,1,2,"view"),("document",1,1,3,"edit")');
    $db->exec('CREATE TABLE collab_documents(id BIGINT UNSIGNED PRIMARY KEY,workspace_id INT,created_by INT,updated_by INT,visibility VARCHAR(20),public_permission VARCHAR(20),storage_path VARCHAR(500),file_type VARCHAR(20),version INT,updated_at DATETIME) ENGINE=InnoDB');
    $db->exec('INSERT INTO collab_documents VALUES(1,1,1,1,"public","edit","uploads/collab-docs/original.docx","docx",1,UTC_TIMESTAMP()),(2,1,1,1,"private","view","uploads/collab-docs/original.docx","docx",1,UTC_TIMESTAMP())');
    $db->exec(file_get_contents(__DIR__.'/../database/migrations/050_collabora_wopi.sql'));
    file_put_contents($root.'/uploads/collab-docs/original.docx','old contents');
    check(DocumentAccessService::get($db,1,2)['permission']==='view','explicit viewer overrides public edit');
    denied(fn()=>DocumentAccessService::get($db,2,2),403,'private document excluded');
    $viewer=CollaboraService::issue($db,1,2);$editor=CollaboraService::issue($db,1,3);
    check(!CollaboraService::authorize($db,1,$viewer['token'])['write'],'viewer token cannot write');
    denied(fn()=>CollaboraService::authorize($db,2,$editor['token']),401,'token bound to document');
    $d=DocumentAccessService::get($db,1,3)['document'];$stamp=CollaboraService::timestamp($d);
    $temp=$root.'/uploads/collab-docs/new';file_put_contents($temp,'new contents');
    denied(fn()=>CollaboraService::save($db,1,$viewer['token'],$temp,$stamp),403,'viewer save rejected');
    denied(fn()=>CollaboraService::save($db,1,$editor['token'],$temp,'old-stamp'),409,'stale save rejected');
    $saved=CollaboraService::save($db,1,$editor['token'],$temp,$stamp);
    $current=DocumentAccessService::get($db,1,3)['document'];
    check((int)$current['version']===2 && file_get_contents(DocumentAccessService::path($current))==='new contents','new version published');
    check(file_get_contents($root.'/uploads/collab-docs/original.docx')==='old contents' && (int)$db->query('SELECT COUNT(*) FROM collab_document_versions')->fetchColumn()===1,'previous version retained');
    check($saved['LastModifiedTime']===CollaboraService::timestamp($current) && $saved['LastModifiedTime']!==$stamp,'save timestamp matches metadata');
    $db->exec('UPDATE collab_resource_permissions SET permission="view" WHERE user_id=3');
    check(!CollaboraService::authorize($db,1,$editor['token'])['write'],'live downgrade honored');
    $db->exec('UPDATE collab_resource_permissions SET permission="edit" WHERE user_id=2');
    check(!CollaboraService::authorize($db,1,$viewer['token'])['write'],'old viewer token cannot upgrade');
    $db->exec('UPDATE server_members SET status="banned" WHERE user_id=3');
    denied(fn()=>CollaboraService::authorize($db,1,$editor['token']),403,'removed server access honored');
    $db->exec('UPDATE collab_wopi_tokens SET expires_at="2000-01-01"');
    denied(fn()=>CollaboraService::authorize($db,1,$viewer['token']),401,'expired token rejected');
    denied(fn()=>DocumentAccessService::path(['storage_path'=>'../outside.docx']),404,'file path confined to storage');
    echo "Collabora regression checks passed\n";
} finally {
    foreach(glob($root.'/uploads/collab-docs/*') as $file) unlink($file);
    rmdir($root.'/uploads/collab-docs');rmdir($root.'/uploads');rmdir($root);
}
