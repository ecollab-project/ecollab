<?php
declare(strict_types=1);
/** Known fixtures through the application's sandbox client; no local code execution. */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/services/CodeLabRunner.php';
$files=['c'=>'main.c','cpp'=>'main.cpp','python'=>'main.py','java'=>'Main.java'];
$options=getopt('',['language:']);
if(isset($options['language'])){
    if(!is_string($options['language'])||!isset($files[$options['language']])){
        fwrite(STDERR,"Use --language=c|cpp|python|java\n");exit(1);
    }
    $files=[$options['language']=>$files[$options['language']]];
}
$directory=ROOT_PATH.'/examples/code-lab/advanced/';
try {
    $cases=json_decode(file_get_contents($directory.'cases.json'),true,512,JSON_THROW_ON_ERROR);
    $runner=new CodeLabRunner();
    if(!$runner->enabled())throw new RuntimeException('Runner not configured.');
    $passed=0;$failed=0;
    foreach($files as $language=>$file){
        $runtime=CodeLabRunner::runtime($language);
        if(!$runtime)throw new RuntimeException('Missing runtime for '.$language.'.');
        $source=file_get_contents($directory.$file);
        foreach($cases as $case){
            $result=$runner->execute($runtime,$source,$case['input']);
            $normalize=static fn(string $s):string=>rtrim(str_replace(["\r\n","\r"],"\n",$s),"\n");
            $ok=$result['status']['id']===3 &&
                $normalize($result['stdout'])===$normalize($case['expected_output']);
            echo ($ok?'PASS':'FAIL')." ".$language." / ".$case['name']."\n";
            if($ok)$passed++;else{
                $failed++;
                echo "Expected: ".json_encode($case['expected_output'])."\n";
                echo "Received: ".json_encode($result['stdout'])."\n";
                echo "Status: ".$result['status']['description']."\n";
                if($result['compile_output']!=='')echo $result['compile_output']."\n";
            }
        }
    }
    echo "$passed passed; $failed failed. Functional language tests only; not a capacity benchmark.\n";
    exit($failed===0?0:1);
}catch(Throwable $error){
    fwrite(STDERR,"Language tests could not finish. Check the runner configuration and service logs.\n");
    exit(1);
}
