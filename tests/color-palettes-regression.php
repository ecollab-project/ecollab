<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/services/ColorPaletteService.php';
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
check(count(ColorPaletteService::all())===20,'All reference palettes');
check(ColorPaletteService::DEFAULT==='forest-mint','Forest & Mint default');
foreach(ColorPaletteService::all() as $palette)check(ColorPaletteService::valid($palette['id']),'Allow named palette');
foreach(['','unknown','FOREST-MINT',"forest-mint'; DROP TABLE users",'<script>alert(1)</script>'] as $id)check(!ColorPaletteService::valid($id),'Reject unlisted or unsafe palette');
if($dsn=getenv('PALETTE_TEST_DSN')){
 $db=new PDO($dsn,'root','test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('CREATE TABLE user_settings(user_id INT PRIMARY KEY,theme VARCHAR(10) DEFAULT \'dark\')');$db->exec("INSERT INTO user_settings VALUES(1,'light')");
 $sql=file_get_contents(dirname(__DIR__).'/database/migrations/059_color_palettes.sql');
 for($n=0;$n<2;$n++)foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);
 $row=$db->query('SELECT * FROM user_settings WHERE user_id=1')->fetch(PDO::FETCH_ASSOC);check($row['color_palette']==='forest-mint'&&$row['theme']==='light','Migration defaults and existing mode retained');
 $db->exec("UPDATE user_settings SET color_palette='purple-pink' WHERE user_id=1");check($db->query('SELECT color_palette FROM user_settings')->fetchColumn()==='purple-pink','Palette persists independently');
}
echo "PASS: palette whitelist, default, unsafe input rejection".($dsn?', repeatable MariaDB migration and persistence':'')."\n";
