<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/config.php';require ROOT_PATH.'/services/CodeLabRunner.php';
try {
 if(in_array('--workers',$argv,true)){
  require_once ROOT_PATH.'/database/config/db.php';require_once ROOT_PATH.'/services/CodeLabWorkerPool.php';
  $size=CodeLabWorkerPool::size(getenv('CODE_LAB_EXECUTION_SLOTS')?:'2');$db=Database::getInstance();
  for($attempt=0;$attempt<30;$attempt++){
   $slots=[];for($slot=1;$slot<=$size;$slot++){$q=$db->prepare('SELECT IS_USED_LOCK(?)');$q->execute([CodeLabWorkerPool::slotName($db,$slot)]);$slots[$slot]=$q->fetchColumn()!==null;}
   if(!in_array(false,$slots,true)){echo json_encode(['worker_slots'=>$slots])."\n";exit;}
   usleep(100000);
  }
  throw new RuntimeException('Worker slots did not become ready.');
 }
 if(in_array('--queue-idle',$argv,true)){
  require_once ROOT_PATH.'/database/config/db.php';
  $counts=['queued'=>0,'running'=>0];
  foreach(Database::getInstance()->query("SELECT state,COUNT(*) AS total FROM code_lab_jobs WHERE state IN ('queued','running') GROUP BY state")->fetchAll(PDO::FETCH_ASSOC)as$row)$counts[$row['state']]=(int)$row['total'];
  echo json_encode($counts)."\n";
  if(array_sum($counts)){fwrite(STDERR,"Code Lab queue is active. Let current jobs finish before restarting the adapter.\n");exit(2);}exit;
 }
 $runner=new CodeLabRunner();if(!$runner->enabled())throw new RuntimeException('not configured');
 if(in_array('--runtime-map',$argv,true)){echo json_encode(array_combine(['c','cpp','python','java'],array_map([CodeLabRunner::class,'runtime'],['c','cpp','python','java'])))."\n";exit;}
 if(in_array('--languages',$argv,true)){foreach($runner->request('GET','/languages')as$l)echo (int)$l['id']."\t".$l['name']."\n";exit;}
 if(in_array('--grading',$argv,true)){
  $capabilities=[];foreach($runner->request('GET','/languages')as$profile)if(($profile['ecollab_grade_v1']??false)===true)$capabilities[]=$profile['id'];
  $sources=[
   'c'=>"#include <stdio.h>\nint main(){int n;scanf(\"%d\",&n);printf(\"%d\\n\",n+1);}",
   'cpp'=>"#include <iostream>\nint main(){int n;std::cin>>n;std::cout<<n+1<<std::endl;}",
   'python'=>'print(int(input())+1)',
   'java'=>'public class Main { public static void main(String[] a) { System.out.println(new java.util.Scanner(System.in).nextInt()+1); } }'];
  foreach($sources as$language=>$source){
   $id=CodeLabRunner::runtime($language);if(!$id||!in_array($id,$capabilities,true))throw new RuntimeException('Batch runtime unavailable');
   $results=$runner->executeCases($id,$source,['41','5']);
   foreach($results as$i=>$result)if($result['status']['id']!==3||trim($result['stdout'])!==['42','6'][$i])throw new RuntimeException('Grading smoke failed');
   echo "$language: compile-once protocol and two isolated cases passed\n";
  }exit;
 }
 $sources=['c'=>'#include <stdio.h>\nint main(){puts("ECOLLAB_OK");return 0;}','cpp'=>'#include <iostream>\nint main(){std::cout<<"ECOLLAB_OK"<<std::endl;}','python'=>'print("ECOLLAB_OK")','java'=>'public class Main { public static void main(String[] args) { System.out.println("ECOLLAB_OK"); } }','javascript'=>'console.log("ECOLLAB_OK");'];
 foreach($sources as$language=>$source){$id=CodeLabRunner::runtime($language);if(!$id)throw new RuntimeException('missing runtime');$result=$runner->execute($id,str_replace('\\n',"\n",$source),'');if($result['status']['id']!==3||trim($result['stdout'])!=='ECOLLAB_OK')throw new RuntimeException('smoke failed');echo "$language: compilation/execution passed\n";}
} catch(Throwable $e){fwrite(STDERR,"Runner check failed. Verify URL, token, language IDs and worker sandbox/cgroup configuration. No secrets are printed.\n");exit(1);}

