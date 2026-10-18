<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
require_once dirname(__DIR__).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/services/FeatureCapacityFixture.php';
try{
    $path=realpath($argv[1]??'');if(!$path||!is_file($path))throw new RuntimeException('Specify the private generated fixture manifest path.');
    $fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$tool=new FeatureCapacityFixture(Database::getInstance());$mode=$argv[2]??'';
    if($mode==='cleanup'){
        $target=json_decode(file_get_contents($argv[3]??''),true,512,JSON_THROW_ON_ERROR);$tool->cleanup($fixture,$target);echo "Synthetic grading tasks removed. Base fixture remains available.\n";exit;
    }
    $target=$tool->prepare($fixture,$mode,(int)($argv[3]??10));
    $folder=dirname($path).'/'.$target['run_id'];if(!mkdir($folder,0700))throw new RuntimeException('Cannot create private kit folder.');
    foreach(glob(ROOT_PATH.'/tools/feature-capacity/*')as$file)if(is_file($file))copy($file,$folder.'/'.basename($file));
    $targetJson=json_encode($target,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $accountJson=json_encode($fixture['users'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    foreach([$folder.'/feature-target.json'=>$targetJson,$folder.'/accounts.json'=>$accountJson,dirname($path).'/feature-target.json'=>$targetJson,dirname($path).'/feature-accounts.json'=>$accountJson]as$file=>$json){
        if(file_put_contents($file.'.part',$json)===false||!rename($file.'.part',$file))throw new RuntimeException('Cannot save private target files.');
        chmod($file,0600);
    }
    echo "Private kit directory: $folder\nMode: $mode; accounts: ".count($fixture['users'])."\nCopy this directory to your PC; run one workload and user count at a time.\n";
    if($mode==='media')echo "Media tokens expire in 10 minutes. Prepare again if the runner reports expiry. No server API secrets are exported.\n";
}catch(Throwable $e){fwrite(STDERR,'Capacity preparation failed: '.$e->getMessage()."\n");exit(1);}
