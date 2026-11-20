<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/services/ColorPaletteService.php';
require dirname(__DIR__).'/services/WorkspaceCustomization.php';
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
check(count(ColorPaletteService::all())===21,'Original default plus existing palettes');
check(ColorPaletteService::DEFAULT==='ecollab-original','Original pink/violet default');
check(ColorPaletteService::valid('fcms-burgundy-coral'),'FCMS palette remains selectable');
foreach(ColorPaletteService::all() as $palette)check(ColorPaletteService::valid($palette['id']),'Allow named palette');
foreach(['','unknown','FOREST-MINT',"forest-mint'; DROP TABLE users",'<script>alert(1)</script>'] as $id)check(!ColorPaletteService::valid($id),'Reject unlisted or unsafe palette');
$colors = '#FFFFFF,#110011,#CC4477,#55AADD,#EEF2F6';
check(WorkspaceCustomization::normalize('custom_palette',$colors) === strtolower($colors),'Five picker values normalize to lowercase');
check(WorkspaceCustomization::normalize('custom_palette','#ffffff,#000000') === null,'Reject incomplete custom colors');
check(WorkspaceCustomization::normalize('custom_palette',$colors . ';color:red') === null,'Reject injected CSS');
$widgets = [];
foreach (WorkspaceCustomization::WIDGETS as $id => $widget) $widgets[] = [$id,$widget['side'],true];
$widgets[0][1] = 'right';
$widgets[0][2] = false;
$layout = json_encode($widgets, JSON_THROW_ON_ERROR);
check(WorkspaceCustomization::normalize('workspace_layout',$layout) === $layout,'Valid workspace choices round-trip');
check(strlen($layout) <= 300,'The saved layout fits its database column');
$widgets[1][0] = $widgets[0][0];
check(WorkspaceCustomization::normalize('workspace_layout',json_encode($widgets)) === null,'Duplicate widget IDs are rejected');
check(WorkspaceCustomization::normalize('workspace_layout','') === '','Layout reset is valid');
if($dsn=getenv('PALETTE_TEST_DSN')){
 $db=new PDO($dsn,'root','test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('CREATE TABLE user_settings(user_id INT PRIMARY KEY,theme VARCHAR(10) DEFAULT \'dark\')');$db->exec("INSERT INTO user_settings VALUES(1,'light')");
 $sql=file_get_contents(dirname(__DIR__).'/database/migrations/059_color_palettes.sql');
 for($n=0;$n<2;$n++)foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);
 $row=$db->query('SELECT * FROM user_settings WHERE user_id=1')->fetch(PDO::FETCH_ASSOC);check($row['color_palette']==='forest-mint'&&$row['theme']==='light','Migration defaults and existing mode retained');
 $db->exec("INSERT INTO user_settings(user_id,theme,color_palette) VALUES(2,'dark','purple-pink'),(3,'light','fcms-burgundy-coral')");
 $restore=file_get_contents(dirname(__DIR__).'/database/migrations/069_original_color_palette.sql');
 for($n=0;$n<2;$n++)foreach(explode(';',$restore) as $statement)if(trim($statement)!=='')$db->exec($statement);
 $row=$db->query('SELECT * FROM user_settings WHERE user_id=1')->fetch(PDO::FETCH_ASSOC);
 check($row['color_palette']==='ecollab-original'&&$row['theme']==='light','Old default restored without changing mode');
 check($db->query('SELECT color_palette FROM user_settings WHERE user_id=2')->fetchColumn()==='purple-pink','Other saved palette preserved');
 check($db->query('SELECT color_palette FROM user_settings WHERE user_id=3')->fetchColumn()==='fcms-burgundy-coral','Explicit FCMS choice preserved on repeat');
 $db->exec('INSERT INTO user_settings(user_id) VALUES(4)');
 check($db->query('SELECT color_palette FROM user_settings WHERE user_id=4')->fetchColumn()==='ecollab-original','New rows receive original default');
 $customization = file_get_contents(dirname(__DIR__).'/database/migrations/070_workspace_customization.sql');
 for ($n=0;$n<2;$n++) foreach (explode(';',$customization) as $statement) if (trim($statement)!=='') $db->exec($statement);
 $db->prepare('UPDATE user_settings SET custom_palette=?,workspace_layout=? WHERE user_id=1')->execute([strtolower($colors),$layout]);
 $row = $db->query('SELECT * FROM user_settings WHERE user_id=1')->fetch(PDO::FETCH_ASSOC);
 check($row['custom_palette'] === strtolower($colors) && $row['workspace_layout'] === $layout && $row['theme'] === 'light','Customization migration retained existing preferences');
 check($db->query('SELECT workspace_layout FROM user_settings WHERE user_id=2')->fetchColumn() === '','A second account keeps its default layout');

}
echo "PASS: palette whitelist, default, unsafe input rejection".($dsn?', repeatable MariaDB migration and persistence':'')."\n";
