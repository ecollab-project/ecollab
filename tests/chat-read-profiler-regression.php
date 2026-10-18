<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ChatReadProfiler.php';
function ensure($value,$message){if(!$value)throw new RuntimeException($message);}
$dsn=getenv('TEST_DELIVERY_DSN');if(!$dsn)throw new RuntimeException('Use the disposable CI database only');
$db=new PDO($dsn,getenv('TEST_DELIVERY_USER')?:'root',getenv('TEST_DELIVERY_PASSWORD')?:'root',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
ensure($db->query('SELECT DATABASE()')->fetchColumn()==='delivery_test','Refuse non-test database');
$db->exec('CREATE DATABASE read_profile_test');$db->exec('USE read_profile_test');
try {
    $schema=[
        "CREATE TABLE users(id INT PRIMARY KEY,username VARCHAR(60),full_name VARCHAR(80),avatar_url VARCHAR(200),avatar_color_gradient VARCHAR(40),status VARCHAR(20),role VARCHAR(20),deleted_at DATETIME NULL,last_active_at DATETIME,is_verified INT)",
        "CREATE TABLE channels(id INT PRIMARY KEY,server_id INT,is_private INT,created_by INT,name VARCHAR(60),type VARCHAR(20),position INT,created_at DATETIME)",
        "CREATE TABLE server_members(server_id INT,user_id INT,server_role VARCHAR(20),nickname VARCHAR(60),PRIMARY KEY(server_id,user_id))",
        "CREATE TABLE channel_members(channel_id INT,user_id INT,PRIMARY KEY(channel_id,user_id))",
        "CREATE TABLE channel_seen(channel_id INT,user_id INT,PRIMARY KEY(channel_id,user_id))",
        "CREATE TABLE user_settings(user_id INT PRIMARY KEY,activity_status INT)",
        "CREATE TABLE message_reads(user_id INT,channel_id INT,last_read_at DATETIME,PRIMARY KEY(user_id,channel_id))",
        "CREATE TABLE messages(id INT PRIMARY KEY,channel_id INT,sender_id INT,content TEXT,content_type VARCHAR(20),parent_id INT NULL,is_edited INT,is_pinned INT,reaction_count INT,created_at DATETIME,updated_at DATETIME,is_deleted INT,KEY page(channel_id,is_deleted,id))",
        "CREATE TABLE message_reactions(message_id INT,user_id INT,emoji VARCHAR(20),PRIMARY KEY(message_id,user_id,emoji))",
        "CREATE TABLE message_attachments(message_id INT,file_name VARCHAR(100),file_path VARCHAR(200),file_size INT,mime_type VARCHAR(100),KEY(message_id))",
    ];
    foreach($schema as $sql)$db->exec($sql);
    $users=[];$members=[];
    for($i=1;$i<=1000;$i++){
        $users[]="($i,'k6_1234abcd_$i','PRIVATE_NAME_SENTINEL','','','active','student',NULL,NOW(),1)";
        $members[]="(19,$i,'member',NULL)";
    }
    $db->exec('INSERT INTO users VALUES'.implode(',',$users));
    $db->exec('INSERT INTO server_members VALUES'.implode(',',$members));
    $db->exec("INSERT INTO channels VALUES(80,19,1,1,'fixture','text',1,NOW());INSERT INTO channel_members VALUES(80,1);INSERT INTO message_reads VALUES(1,80,'2020-01-01')");
    $messages=[];for($i=1;$i<=65;$i++)$messages[]="($i,80,1,'PRIVATE_MESSAGE_SENTINEL','text',NULL,0,0,0,NOW(),NOW(),0)";
    $db->exec('INSERT INTO messages VALUES'.implode(',',$messages));
    $db->exec("INSERT INTO message_reactions VALUES(65,1,'thumb');INSERT INTO message_attachments VALUES(65,'PRIVATE_FILE_SENTINEL','secret/path',3,'text/plain')");
    $report=ChatReadProfiler::inspect($db,80,null,2);
    ensure($report['viewer_id']===1,'Default selects fixture member');
    ensure($report['queries']['message_page']['rows_returned']===50,'Full query returns a bounded page');
    ensure($report['queries']['member_roster']['rows_returned']===1000,'Roster measurement includes entire large server');
    ensure($report['queries']['reactions']['rows_returned']===1 && $report['queries']['attachments']['rows_returned']===1,'Dependent query measurements');
    foreach($report['queries'] as $query)ensure(count($query['milliseconds'])===2 && $query['plan'],'Samples and real EXPLAIN plan');
    ensure(!str_contains(json_encode($report),'PRIVATE_'),'No message, user or file contents in report');
    ensure(!$db->inTransaction(),'Read-only transaction released');
    ensure($db->query('SELECT last_read_at FROM message_reads')->fetchColumn()==='2020-01-01 00:00:00','Read marker not changed');
    try{ChatReadProfiler::inspect($db,80,2);throw new LogicException('Private outsider accepted');}catch(RuntimeException $e){ensure($e->getMessage()==='Viewer is not authorized for this channel','Private access denied');}
    // Exercise actual channel listing in a read-only transaction: request-time DDL would end it.
    $db->exec('ALTER TABLE channels ADD slug VARCHAR(60), ADD description TEXT, ADD is_locked INT DEFAULT 0, ADD member_count INT DEFAULT 0');
    require_once dirname(__DIR__).'/services/ChannelService.php';
    $instance=new ReflectionProperty(Database::class,'instance');$instance->setValue(null,$db);
    if (!defined('BASE_URL')) define('BASE_URL', 'https://example.invalid');
    $service = new ChannelService();
    $preview = $service->getOnlineMembers(19, 1, 20);
    $directory = $service->getOnlineMembers(19, 1);
    ensure(count($preview) === 20 && count($directory) === 1000, 'Sidebar bounded, directory complete');
    ensure($service->countVisibleMembers(19) === 1000, 'Accurate directory count');
    ensure(array_column($preview, 'id') === array_slice(array_column($directory, 'id'), 0, 20), 'Stable preview ordering');
    ensure(strlen(json_encode($preview)) < strlen(json_encode($directory)) / 20, 'Large roster payload reduced by over 95 percent');
    $db->exec("UPDATE users SET status='banned' WHERE id=998; UPDATE users SET role='admin' WHERE id=999; UPDATE users SET deleted_at=NOW() WHERE id=1000");
    ensure($service->countVisibleMembers(19) === 997 && count($service->getOnlineMembers(19, 1)) === 997, 'Count and roster share privacy filters');
    ensure($service->countVisibleMembers(999) === 0, 'Empty server count');
    $db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
    $channels=(new ChannelService())->getChannelsForUser(19,1);
    ensure(count($channels)===1 && (int)$channels[0]['unread_count']===65,'Authorized channel and legacy unread count retained');
    ensure(count((new ChannelService())->getChannelsForUser(19,2))===0,'Private channel remains hidden');
    ensure($db->inTransaction(),'Channel list must not execute implicit-commit schema DDL');$db->rollBack();
    $db->exec('UPDATE channels SET created_by=1000; DELETE FROM channel_members WHERE user_id=1');
    try{ChatReadProfiler::inspect($db,80,1);throw new LogicException('Revoked viewer accepted');}catch(RuntimeException $e){ensure($e->getMessage()==='Viewer is not authorized for this channel','Revocation checked');}
    echo "Read profiler: large roster, full message page, plans, privacy and access checks passed\n";
} finally { if($db->inTransaction())$db->rollBack();$db->exec('DROP DATABASE read_profile_test'); }
