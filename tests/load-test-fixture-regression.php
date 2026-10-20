<?php
declare(strict_types=1);
define('BCRYPT_COST',4);
require_once dirname(__DIR__).'/services/LoadTestFixture.php';
function ensure($value,$message){if(!$value)throw new RuntimeException($message);}
$dsn=getenv('TEST_LOAD_DSN');if(!$dsn)throw new RuntimeException('Set TEST_LOAD_DSN to an empty disposable test database; never use production');
$db=new PDO($dsn,getenv('TEST_LOAD_USER')?:'root',getenv('TEST_LOAD_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$sqls=[
"CREATE TABLE users(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(50) UNIQUE,email VARCHAR(120) UNIQUE,full_name VARCHAR(120),password_hash VARCHAR(255),role VARCHAR(20),status VARCHAR(20),email_verified INT,is_online INT,deleted_at DATETIME NULL,remember_token VARCHAR(100) NULL)",
"CREATE TABLE servers(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,owner_id BIGINT UNSIGNED,name VARCHAR(80),slug VARCHAR(80),description TEXT,type VARCHAR(20),visibility VARCHAR(20),status VARCHAR(20),member_count INT,FOREIGN KEY(owner_id) REFERENCES users(id))",
"CREATE TABLE channels(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,server_id INT UNSIGNED,name VARCHAR(60),slug VARCHAR(60),type VARCHAR(20),is_private INT,is_locked INT,created_by BIGINT UNSIGNED,owner_id BIGINT UNSIGNED,visibility VARCHAR(20),FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE CASCADE)",
"CREATE TABLE server_members(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,server_id INT UNSIGNED,user_id BIGINT UNSIGNED,server_role VARCHAR(20),FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE CASCADE)",
"CREATE TABLE channel_members(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,channel_id INT UNSIGNED,user_id BIGINT UNSIGNED,FOREIGN KEY(channel_id) REFERENCES channels(id) ON DELETE CASCADE)",
"CREATE TABLE messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,channel_id INT UNSIGNED,sender_id BIGINT UNSIGNED,content TEXT,content_type VARCHAR(20),FOREIGN KEY(channel_id) REFERENCES channels(id) ON DELETE SET NULL,FOREIGN KEY(sender_id) REFERENCES users(id))",
"CREATE TABLE realtime_outbox(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(10),target_id INT)",
"CREATE TABLE ws_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED)"
];foreach($sqls as $sql)$db->exec($sql);
$directory=sys_get_temp_dir().'/ecollab-fixture-test-'.bin2hex(random_bytes(4));$tool=new LoadTestFixture($db);
try{$tool->create(1001,'https://ecollab.tech','wss://ws.ecollab.tech',$directory);throw new LogicException('Invalid count accepted');}catch(InvalidArgumentException $e){}
$path=$tool->create(2,'https://ecollab.tech','wss://ws.ecollab.tech',$directory);$data=json_decode(file_get_contents($path),true);ensure(count($data['users'])===2,'Distinct users');ensure((fileperms($path)&0777)===0600,'Private manifest');ensure((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===50,'Discussion fixture');
ensure(password_verify($data['users'][0]['password'],$db->query('SELECT password_hash FROM users LIMIT 1')->fetchColumn()),'Real password hashes');ensure($db->query('SELECT visibility FROM servers')->fetchColumn()==='private','Private server');
$db->exec("INSERT INTO users(username,email,full_name,role,status) VALUES ('real_person','real@example.com','Real Person','student','offline')");$real=(int)$db->lastInsertId();$sid=(int)$data['server_id'];$cid=(int)$data['channel_id'];
$db->exec("INSERT INTO server_members(server_id,user_id,server_role) VALUES($sid,$real,'member')");
try{$tool->cleanup($data);throw new LogicException('Real-member cleanup accepted');}catch(RuntimeException $e){}
ensure((int)$db->query('SELECT COUNT(*) FROM servers')->fetchColumn()===1,'Unsafe cleanup rolled back');$db->exec("DELETE FROM server_members WHERE user_id=$real");
$db->exec("INSERT INTO messages(channel_id,sender_id,content,content_type) VALUES($cid,$real,'Real message','text')");
try{$tool->cleanup($data);throw new LogicException('Real-message cleanup accepted');}catch(RuntimeException $e){}
ensure((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===51,'Real messages retained');$db->exec("DELETE FROM messages WHERE sender_id=$real");
$tool->cleanup($data);ensure((int)$db->query('SELECT COUNT(*) FROM servers')->fetchColumn()===0,'Test server removed');ensure((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===0,'Test messages removed');ensure((int)$db->query("SELECT COUNT(*) FROM users WHERE status='deactivated' AND password_hash IS NULL AND deleted_at IS NOT NULL")->fetchColumn()===2,'Synthetic accounts revoked');ensure((int)$db->query("SELECT COUNT(*) FROM users WHERE email='real@example.com' AND deleted_at IS NULL")->fetchColumn()===1,'Real account retained');
$db->exec('DROP TABLE channel_members');try{$tool->create(2,'https://ecollab.tech','wss://ws.ecollab.tech',$directory);throw new LogicException('Expected insert failure');}catch(PDOException $e){}
ensure((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===3,'Failed fixture creation rolled back');
foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);
echo "Fixture checks passed: creation, private credentials, real-data guards, cleanup and rollback\n";
