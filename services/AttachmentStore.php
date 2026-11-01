<?php
declare(strict_types=1);
require_once __DIR__.'/AttachmentAccess.php';
/** Private bytes and immutable uploader/destination receipts; no schema or legacy-file rewrite. */
final class AttachmentStore
{
    private string $root;
    public function __construct(string $root,string $webRoot){
        if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('Attachment storage unavailable',503);
        $real=realpath($root);$web=realpath($webRoot);
        if(!$real||!$web||str_starts_with($real.'/',$web.'/'))throw new RuntimeException('Attachment storage must be outside the web root',503);$this->root=$real;
    }
    public static function configured():self{return new self((string)env('CHAT_ATTACHMENT_STORAGE',dirname(ROOT_PATH).'/ecollab-attachments'),ROOT_PATH);}
    public function put(string $source,string $path,int $uid,string $kind,int $target,string $name,string $mime):array{
        if(!AttachmentAccess::validPath($path)||$uid<1||!in_array($kind,['channel','dm','group','thread','thread-public','thread-server','thread-channel'],true)||($target<1&&$kind!=='thread-public'))throw new RuntimeException('Invalid attachment destination',400);
        $key=hash('sha256',$path);$data=$this->root.'/'.$key.'.data';$receipt=$this->root.'/'.$key.'.json';
        if(file_exists($receipt)||file_exists($data))throw new RuntimeException('Attachment already exists',409);
        $in=fopen($source,'rb');$out=fopen($data,'xb');if(!$in||!$out)throw new RuntimeException('Attachment storage unavailable',503);
        try{if(stream_copy_to_stream($in,$out)===false||!fflush($out))throw new RuntimeException('Attachment write failed',503);}finally{fclose($in);fclose($out);}chmod($data,0600);
        $m=['path'=>$path,'uid'=>$uid,'kind'=>$kind,'target'=>$target,'file_name'=>$name,'mime_type'=>$mime,'file_size'=>filesize($data)];$tmp=$receipt.'.tmp';
        if(file_put_contents($tmp,json_encode($m,JSON_THROW_ON_ERROR),LOCK_EX)===false||!chmod($tmp,0600)||!rename($tmp,$receipt))throw new RuntimeException('Attachment receipt failed',503);return $m;
    }
    public function receipt(string $path):?array{
        if(!AttachmentAccess::validPath($path))return null;$p=$this->root.'/'.hash('sha256',$path).'.json';if(!is_file($p)||is_link($p))return null;$m=json_decode((string)file_get_contents($p),true);return is_array($m)&&($m['path']??null)===$path?$m:null;
    }
    public function assertOwned(string $path,int $uid,string $kind,int $target):array{
        $m=$this->receipt($path);if(!$m||$m['uid']!==$uid||$m['kind']!==$kind||$m['target']!==$target||!$this->file($path))throw new RuntimeException('Attachment does not belong to this uploader and destination',403);return $m;
    }
    public function file(string $path):?string{if(!$this->receipt($path))return null;$p=$this->root.'/'.hash('sha256',$path).'.data';return is_file($p)&&!is_link($p)?$p:null;}
}
