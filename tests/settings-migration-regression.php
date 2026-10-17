<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/services/UserPreferences.php';
$dsn=getenv('SETTINGS_TEST_DSN');if(!$dsn)throw new RuntimeException('Set SETTINGS_TEST_DSN to an isolated test database.');
$db=new PDO($dsn,'root','test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY)');$db->exec('INSERT INTO users VALUES(1)');
function migrate(PDO $db,string $file):void{foreach(explode(';',preg_replace('/^\s*--.*$/m','',file_get_contents(dirname(__DIR__).'/database/migrations/'.$file))) as $sql)if(trim($sql)!=='')$db->exec($sql);}
migrate($db,'021_user_settings.sql');migrate($db,'059_color_palettes.sql');$db->exec("INSERT INTO user_settings(user_id,theme) VALUES(1,'light')");migrate($db,'060_settings_runtime.sql');
$db->exec("UPDATE user_settings SET jarred_enabled=0,jarred_memory=1,jarred_saved_context='Explain in Taglish',camera_device='cam-1',show_timestamps=0 WHERE user_id=1");migrate($db,'060_settings_runtime.sql');
$columns=$db->query('SHOW COLUMNS FROM user_settings')->fetchAll(PDO::FETCH_COLUMN);foreach(array_keys(UserPreferences::defaults()) as $key)if(!in_array($key,$columns,true))throw new RuntimeException('Missing settings column: '.$key);
$row=UserPreferences::get($db,1);if($row['theme']!=='light'||(int)$row['jarred_enabled']!==0||$row['jarred_saved_context']!=='Explain in Taglish'||$row['camera_device']!=='cam-1'||(int)$row['show_timestamps']!==0)throw new RuntimeException('Migration changed existing preferences.');
echo "PASS: complete settings schema from original baseline, 23 repeatable additions and preservation of saved preferences\n";
