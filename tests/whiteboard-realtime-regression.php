<?php
declare(strict_types=1);
$dsn=getenv('COLLABORA_TEST_DSN');
if(!$dsn||!str_ends_with($dsn,';dbname=collabora_test')){fwrite(STDERR,"Dedicated collabora_test database required; never run against production.\n");exit(2);}
define('DB_HOST','test');
require_once __DIR__.'/../websocket/handlers/WhiteboardHandler.php';
$db=new PDO($dsn,'root',getenv('COLLABORA_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
foreach(['collab_whiteboards','collab_resource_permissions','collab_workspace_members','collab_workspaces','server_members','servers','users'] as $table)$db->exec('DROP TABLE IF EXISTS '.$table);
$db->exec('CREATE TABLE users(id INT PRIMARY KEY,status VARCHAR(20),deleted_at DATETIME NULL); INSERT INTO users VALUES(1,"active",NULL),(2,"offline",NULL),(3,"active",NULL);
CREATE TABLE servers(id INT PRIMARY KEY,status VARCHAR(20)); INSERT INTO servers VALUES(5,"active");
CREATE TABLE server_members(server_id INT,user_id INT); INSERT INTO server_members VALUES(5,1),(5,2);
CREATE TABLE collab_workspaces(id INT PRIMARY KEY,server_id INT,channel_id INT,host_id INT,visibility VARCHAR(20),archived INT); INSERT INTO collab_workspaces VALUES(2,5,20,1,"public",0);
CREATE TABLE collab_workspace_members(workspace_id INT,user_id INT,role VARCHAR(20));
CREATE TABLE collab_resource_permissions(resource_type VARCHAR(20),resource_id INT,workspace_id INT,user_id INT,permission VARCHAR(20));
CREATE TABLE collab_whiteboards(id INT PRIMARY KEY,workspace_id INT,visibility VARCHAR(20),public_permission VARCHAR(20),created_by INT); INSERT INTO collab_whiteboards VALUES(10,2,"public","edit",1)');
$handler=new WhiteboardHandler();
check($handler->authorize(20,2,10)['permission']==='edit','workspace board accessible without chat membership');
check($handler->authorize(21,2,10)===null,'wrong channel cannot reuse a board grant');
check($handler->authorize(20,3,10)===null,'non-server-member rejected');
$db->exec('INSERT INTO collab_resource_permissions VALUES("whiteboard",10,2,2,"view")');
check(!$handler->canPerformOp(20,2,'excalidraw_scene',10),'live viewer downgrade prevents scene edits');
$db->exec('UPDATE collab_resource_permissions SET permission="comment"');
check(!$handler->canEdit(20,2,10)&&!$handler->canPerformOp(20,2,'excalidraw_scene',10),'comment permission cannot replace canvas');
$db->exec('DELETE FROM collab_resource_permissions; UPDATE collab_whiteboards SET visibility="private"');
check($handler->authorize(20,2,10)===null,'private board requires grant');
$db->exec('INSERT INTO collab_resource_permissions VALUES("whiteboard",10,2,2,"edit")');
check($handler->canEdit(20,2,10),'explicit private board editor accepted');
$db->exec('UPDATE collab_workspaces SET visibility="private"');
check($handler->authorize(20,2,10)===null,'board grant cannot bypass private workspace');
$db->exec('INSERT INTO collab_workspace_members VALUES(2,2,"member")');
check($handler->canEdit(20,2,10),'workspace member with board grant accepted');
$db->exec('DELETE FROM server_members WHERE user_id=2');
check($handler->authorize(20,2,10)===null,'revoked server membership takes effect');
$db->exec('INSERT INTO server_members VALUES(5,2); UPDATE users SET status="suspended" WHERE id=2');
check($handler->authorize(20,2,10)===null,'suspended account rejected');
echo "Whiteboard realtime regression checks passed\n";
