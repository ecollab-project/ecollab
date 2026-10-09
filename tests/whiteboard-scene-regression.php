<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/WhiteboardSceneService.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$a=['id'=>'a','version'=>1,'versionNonce'=>8];
$b=['id'=>'b','version'=>1,'versionNonce'=>3];
$deleted=['id'=>'a','version'=>2,'versionNonce'=>5,'isDeleted'=>true];
$scene=WhiteboardSceneService::merge(['elements'=>[$a,$b],'files'=>['image'=>['id'=>'image']]], ['elements'=>[$deleted]]);
check(count($scene['elements'])===2, 'Concurrent additions must survive an incomplete incoming scene');
check($scene['elements'][0]['isDeleted']===true, 'Deletion tombstones must survive');
$scene=WhiteboardSceneService::merge($scene,['elements'=>[$a]]);
check($scene['elements'][0]['version']===2, 'Stale snapshots must not resurrect deleted elements');
check(isset($scene['files']['image']), 'Other users’ images must survive');
$left=WhiteboardSceneService::merge(['elements'=>[$a]],['elements'=>[array_replace($a,['versionNonce'=>2])]]);
$right=WhiteboardSceneService::merge(['elements'=>[array_replace($a,['versionNonce'=>2])]],['elements'=>[$a]]);
check($left===$right, 'Equal-version conflicts must converge independently of arrival order');
echo "Whiteboard scene regression checks passed\n";
