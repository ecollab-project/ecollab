<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mode=$argv[1]??'';if(!in_array($mode,['enable','disable'],true)){fwrite(STDERR,"Use enable or disable\n");exit(1);}
$root=dirname(__DIR__);$path=$root.'/.env';$text=file_get_contents($path);
if($text===false)throw new RuntimeException('Cannot read application environment');
if($mode==='enable')foreach(['assets/js/chat/rich-composer.js','assets/css/chat-composer.css'] as $file){if(!is_file($root.'/'.$file) || filesize($root.'/'.$file)<100)throw new RuntimeException('Composer assets missing');}
$backupDir=dirname($root).'/ecollab-backups';if(!is_dir($backupDir) && !mkdir($backupDir,0700,true))throw new RuntimeException('Cannot create backup directory');
$backup=$backupDir.'/environment-before-composer-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
if(file_put_contents($backup,$text)===false || !chmod($backup,0600))throw new RuntimeException('Cannot create private environment backup');
$line='CHAT_RICH_COMPOSER='.($mode==='enable'?'true':'false');
$text=preg_match('/^CHAT_RICH_COMPOSER\s*=/m',$text)?preg_replace('/^CHAT_RICH_COMPOSER\s*=.*$/m',$line,$text):rtrim($text)."\n".$line."\n";
if(file_put_contents($path,$text,LOCK_EX)===false)throw new RuntimeException('Cannot update application environment');
echo "Chat composer: $mode complete. Private environment backup: $backup\n";
