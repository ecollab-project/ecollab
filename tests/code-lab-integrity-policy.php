<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/CodeLabIntegrityPolicy.php';
function integrityCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Code Lab scope check failed');}
foreach(['code_lab','code-lab','codelab','code_lab_task','code-lab-assignment','code_lab_playground','code_lab_practice'] as $name)integrityCheck(CodeLabIntegrityPolicy::isCodeLab(['surface'=>$name]));
integrityCheck(CodeLabIntegrityPolicy::isCodeLab([], 'https://ecollab.tech/modules/code-lab/index.php?server_id=1'));
foreach(['chat','dm','document','whiteboard','facilitator_dashboard','code_lab_fake'] as $name)integrityCheck(!CodeLabIntegrityPolicy::isCodeLab(['surface'=>$name]));
integrityCheck(!CodeLabIntegrityPolicy::isCodeLab([], 'https://ecollab.tech/modules/chat/chat.php?next=/modules/code-lab/index.php'));
foreach(['API/ai/message.php','API/chat/ai-assist.php','API/dm/send-message.php'] as $file){$source=file_get_contents(dirname(__DIR__).'/'.$file);integrityCheck(strpos($source,'CodeLabIntegrityPolicy::isCodeLab')!==false);}
echo "PASS: Code Lab-only Jarred scope gate; chat, documents and dashboards unaffected\n";
