<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/services/OllamaService.php';
// Fixed synthetic conversation only; no account, message or saved-context data.
try{
 $model=new OllamaService();$messages=[['role'=>'user','content'=>'For this synthetic test, the project database is MariaDB. Reply with only the database name.']];$results=[];
 for($i=0;$i<2;$i++){$started=microtime(true);$answer=$model->generate($messages,'Answer only from the synthetic conversation. Do not use external facts.',80);$results[]=['sample'=>$i+1,'elapsed_ms'=>round((microtime(true)-$started)*1000),'timing_ms'=>$answer['timing_ms']??null,'input_tokens'=>$answer['input_tokens'],'output_tokens'=>$answer['output_tokens'],'expected_fact_present'=>stripos($answer['text'],'MariaDB')!==false];$messages[]=['role'=>'assistant','content'=>$answer['text']];$messages[]=['role'=>'user','content'=>'Which database did I specify? Reply with only its name.'];}
 echo json_encode(['scope'=>'Two synthetic local-model requests, not a capacity or general accuracy benchmark','samples'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,"Jarred benchmark unavailable. Check the model service and run as the PHP-FPM user.\n");exit(1);}
