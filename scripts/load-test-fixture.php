<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
require_once dirname(__DIR__).'/config.php';require_once ROOT_PATH.'/database/config/db.php';require_once ROOT_PATH.'/services/LoadTestFixture.php';
$directory=dirname(ROOT_PATH).'/ecollab-load-tests';$mode=$argv[1]??'';
try{
    $fixture=new LoadTestFixture(Database::getInstance());
    if($mode==='create'){
        $count=filter_var($argv[2]??50,FILTER_VALIDATE_INT);if($count===false)throw new RuntimeException('Invalid student count');
        $base=$argv[3]??BASE_URL;$ws=$argv[4]??WS_URL;
        if(!filter_var($base,FILTER_VALIDATE_URL)||!in_array(parse_url($base,PHP_URL_SCHEME),['https','http'],true))throw new RuntimeException('Invalid website URL');
        if(!preg_match('#^wss?://[a-zA-Z0-9.:-]+(?:/[a-zA-Z0-9_./-]*)?$#',$ws))throw new RuntimeException('Invalid WebSocket URL');
        $path=$fixture->create($count,$base,$ws,$directory);echo "Created $count synthetic students in a private server.\nPrivate manifest: $path\nRun python3 scripts/package-load-tests.py to prepare the PC kit.\n";
    }elseif($mode==='cleanup'){
        $path=isset($argv[2])?realpath($argv[2]):trim((string)@file_get_contents($directory.'/LATEST'));
        if(!$path||!is_file($path))throw new RuntimeException('Specify an existing private fixture manifest');
        $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$fixture->cleanup($data);
        $data['cleaned_at']=gmdate('c');foreach($data['users'] as &$user)unset($user['password']);unset($user);file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        if(is_file($directory.'/ecollab-k6-kit.zip'))unlink($directory.'/ecollab-k6-kit.zip');echo "Private test server/messages removed; synthetic accounts deactivated. Delete the kit/accounts.json copies from your PC.\n";
    }else{fwrite(STDERR,"Use create [count] [base URL] [Ratchet URL], or cleanup [manifest path]\n");exit(1);}
}catch(Throwable $e){fwrite(STDERR,'Fixture operation failed: '.$e->getMessage()."\n");exit(1);}
