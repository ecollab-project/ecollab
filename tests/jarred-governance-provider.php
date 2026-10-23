<?php
declare(strict_types=1);
namespace JarredProviderFixture;
// Stub only the HTTP boundary. Load the exact production client into this namespace.
function curl_init($url){$GLOBALS['url']=$url;return new \stdClass();}
function curl_setopt_array($handle,$options){$GLOBALS['options']=$options;return true;}
function curl_exec($handle){return json_encode($GLOBALS['response']);}
function curl_getinfo($handle,$option){return $GLOBALS['status'];}
function curl_error($handle){return '';}
function curl_close($handle){}
function ensure(bool $ok,string $reason):void{if(!$ok)throw new \RuntimeException($reason);}
$source=file_get_contents(dirname(__DIR__).'/services/GeminiService.php');
$source=str_replace('declare(strict_types=1);','declare(strict_types=1); namespace JarredProviderFixture; use RuntimeException;',$source);
$file=tempnam(sys_get_temp_dir(),'jarred-provider-');
try{
 file_put_contents($file,$source);require $file;
 $client=new GeminiService('fixture-not-a-real-key');
 $GLOBALS['status']=200;$GLOBALS['response']=['candidates'=>[['content'=>['parts'=>[['text'=>'Concept explanation']]]]]];
 ensure($client->generate([['role'=>'user','content'=>'Synthetic question']],'Study support',300)['text']==='Concept explanation','Valid reply changed');
 ensure($GLOBALS['options'][CURLOPT_SSL_VERIFYPEER]===true&&$GLOBALS['options'][CURLOPT_SSL_VERIFYHOST]===2,'TLS verification changed');
 foreach([400,429,503] as $status){
  $GLOBALS['status']=$status;$GLOBALS['response']=['error'=>['message'=>'PRIVATE_PROMPT_PRIVATE_KEY_PRIVATE_DOCUMENT']];
  try{$client->generate([]);throw new \LogicException('Expected provider rejection');}
  catch(\RuntimeException $e){ensure($e->getCode()===502&&!str_contains($e->getMessage(),'PRIVATE_'),'Provider payload escaped safe error boundary');}
 }
 echo "PASS: successful Gemini reply and TLS verification preserved; provider errors exclude echoed private content (stubbed HTTP, no external inference)\n";
}finally{unlink($file);}
