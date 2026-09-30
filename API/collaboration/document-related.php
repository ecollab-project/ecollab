<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/DocumentAccessService.php';
require_once ROOT_PATH . '/services/PeerSemanticClient.php';
AuthMiddleware::startSession(); $user = AuthMiddleware::requireAuth(true);
header('Content-Type: application/json'); header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required.',405);
    AuthMiddleware::verifyCsrf();
    if (env('DOCUMENT_ML_ENABLED','false') !== 'true') throw new RuntimeException('Related documents disabled.',503);
    $body = json_decode(file_get_contents('php://input'),true) ?: [];
    $db = Database::getInstance(); $uid = (int)$user['id'];
    $a = DocumentAccessService::get($db,(int)($body['id']??0),$uid); $d = $a['document'];
    $s = $db->prepare('SELECT id FROM collab_documents WHERE workspace_id=? AND id<>? ORDER BY updated_at DESC LIMIT 100');
    $s->execute([$d['workspace_id'],$d['id']]); $docs = [];
    foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { $docs[] = DocumentAccessService::get($db,(int)$id,$uid)['document']; }
        catch(RuntimeException $e) { if(!in_array($e->getCode(),[403,404],true)) throw $e; }
    }
    $profile = static fn(array $doc): array => ['subjects'=>[['name'=>$doc['title']]]];
    $scores = (new PeerSemanticClient())->scores($profile($d),array_map($profile,$docs)); $related=[];
    foreach($docs as $i=>$doc) {
        if($scores[$i]===null) continue;
        $related[]=['id'=>(int)$doc['id'],'title'=>$doc['title'],'score'=>$scores[$i], 'url'=>BASE_URL.'/modules/collaboration/document.php?id='.(int)$doc['id'].'&workspace_id='.(int)$doc['workspace_id']];
    }
    usort($related,static fn($a,$b)=>$b['score']<=>$a['score']);
    echo json_encode(['success'=>true,'engine'=>$related?'semantic-v1':null,'related'=>array_slice($related,0,5)]);
} catch(Throwable $e) { http_response_code(in_array($e->getCode(),[403,404,405,503],true)?$e->getCode():500);echo json_encode(['success'=>false,'error'=>'Related documents unavailable.']); }
