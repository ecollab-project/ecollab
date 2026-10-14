<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/config.php';require ROOT_PATH.'/services/CodeLabRunner.php';
try {
 $runner=new CodeLabRunner();if(!$runner->enabled())throw new RuntimeException('not configured');
 if(in_array('--languages',$argv,true)){foreach($runner->request('GET','/languages')as$l)echo (int)$l['id']."\t".$l['name']."\n";exit;}
 $sources=['c'=>'#include <stdio.h>\nint main(){puts("ECOLLAB_OK");return 0;}','cpp'=>'#include <iostream>\nint main(){std::cout<<"ECOLLAB_OK"<<std::endl;}','python'=>'print("ECOLLAB_OK")','java'=>'public class Main { public static void main(String[] args) { System.out.println("ECOLLAB_OK"); } }','javascript'=>'console.log("ECOLLAB_OK");'];
 foreach($sources as$language=>$source){$id=CodeLabRunner::runtime($language);if(!$id)throw new RuntimeException('missing runtime');$result=$runner->execute($id,str_replace('\\n',"\n",$source),'');if($result['status']['id']!==3||trim($result['stdout'])!=='ECOLLAB_OK')throw new RuntimeException('smoke failed');echo "$language: compilation/execution passed\n";}
} catch(Throwable $e){fwrite(STDERR,"Runner check failed. Verify URL, token, language IDs and worker sandbox/cgroup configuration. No secrets are printed.\n");exit(1);}
