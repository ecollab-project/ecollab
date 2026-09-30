<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/services/DocumentAccessService.php';
AuthMiddleware::startSession(); $user=AuthMiddleware::requireAuth(true);
header('Content-Type: application/json'); header('Cache-Control: no-store');
$path=null;$committed=false;
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('POST required.',405);
    AuthMiddleware::csrfToken(); AuthMiddleware::verifyCsrf(); $db=Database::getInstance(); $uid=(int)$user['id'];
    $data=$_POST ?: (json_decode(file_get_contents('php://input'),true) ?: []);
    $title=trim((string)($data['title']??''));
    $title=trim(preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~u',' ',$title)??'');
    if($title==='' || strlen($title)>200) throw new RuntimeException('Use a title between 1 and 200 bytes.',400);
    if(($data['action']??'')==='rename') {
        $a=DocumentAccessService::get($db,(int)($data['id']??0),$uid);
        if(!$a['owner']) throw new RuntimeException('Only owners can rename documents.',403);
        $db->prepare('UPDATE collab_documents SET title=?,file_name=?,updated_by=? WHERE id=?')->execute([$title,$title.'.'.$a['document']['file_type'],$uid,$a['document']['id']]);
        echo json_encode(['success'=>true]);exit;
    }
    if(($data['action']??'')!=='upload') throw new RuntimeException('Unknown action.',400);
    $w=CoworkspaceService::get($db,(int)($data['workspace_id']??0),$uid);
    $s=$db->prepare('SELECT 1 FROM server_members sm JOIN users u ON u.id=sm.user_id WHERE sm.server_id=? AND sm.user_id=? AND sm.status="active" AND u.status IN ("active","offline","idle")');$s->execute([$w['server_id'],$uid]);
    if(!$s->fetchColumn() || ((int)$w['allow_create_documents']!==1 && !in_array($w['member_role'],['host','editor'],true))) throw new RuntimeException('Upload not allowed.',403);
    $file=$_FILES['file']??[];
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size']??0)>25*1024*1024 || !is_uploaded_file($file['tmp_name']??'')) throw new RuntimeException('Upload a DOCX, XLSX or PPTX file up to 25 MB.',400);
    $type=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
    $part=['docx'=>'word/document.xml','xlsx'=>'xl/workbook.xml','pptx'=>'ppt/presentation.xml'][$type]??null;
    if(!$part) throw new RuntimeException('Unsupported file format.',400);
    $zip=new ZipArchive();if($zip->open($file['tmp_name'])!==true) throw new RuntimeException('Invalid Office file.',400);
    $valid=$zip->locateName($part)!==false && $zip->locateName('[Content_Types].xml')!==false;$expanded=0;
    for($i=0;$i<$zip->numFiles;$i++){ $stat=$zip->statIndex($i);$expanded+=(int)$stat['size'];if(str_ends_with(strtolower($stat['name']),'vbaproject.bin'))$valid=false; }
    $zip->close();if(!$valid || $expanded>100*1024*1024) throw new RuntimeException('Unsupported Office archive.',400);
    $dir=ROOT_PATH.'/uploads/collab-docs';if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('Storage unavailable.',500);
    $key=bin2hex(random_bytes(24));$relative='uploads/collab-docs/'.$key.'.'.$type;$path=ROOT_PATH.'/'.$relative;
    if(!move_uploaded_file($file['tmp_name'],$path))throw new RuntimeException('Upload failed.',500);chmod($path,0640);
    $db->beginTransaction();
    // Imports start private. Sharing is an explicit follow-up action.
    $db->prepare('INSERT INTO collab_documents(channel_id,workspace_id,title,file_name,file_type,storage_path,document_key,visibility,public_permission,created_by,updated_by) VALUES(?,?,?,?,?,?,?,"private","view",?,?)')->execute([$w['channel_id'],$w['id'],$title,$title.'.'.$type,$type,$relative,$key,$uid,$uid]);
    $id=(int)$db->lastInsertId();$db->commit();$committed=true;
    echo json_encode(['success'=>true,'id'=>$id,'url'=>BASE_URL.'/modules/collaboration/document.php?id='.$id.'&workspace_id='.(int)$w['id']]);
} catch(Throwable $e) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    $code=in_array($e->getCode(),[400,403,404,405],true)?$e->getCode():500;http_response_code($code);
    echo json_encode(['success'=>false,'error'=>$code===500?'Document operation failed.':$e->getMessage()]);
} finally { if(!$committed && $path!==null && is_file($path))unlink($path); }
