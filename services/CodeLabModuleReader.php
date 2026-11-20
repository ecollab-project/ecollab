<?php
declare(strict_types=1);

/** Read bounded text only. No archive extraction, macros, scripts, URLs or OCR. */
final class CodeLabModuleReader
{
    public const MAX_BYTES=5242880;
    private static function fail(string $message,int $code=400): never {throw new RuntimeException($message,$code);}
    public static function upload(array $file): array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)self::fail('Choose a module file up to 5 MB.');
        $path=(string)($file['tmp_name']??'');
        if(!is_uploaded_file($path))self::fail('Invalid module upload.');
        return self::read($path,(string)($file['name']??''));
    }
    public static function read(string $path,string $name): array
    {
        $size=filesize($path);if($size===false||$size<1||$size>self::MAX_BYTES)self::fail('Module must be between 1 byte and 5 MB.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($ext,['pdf','docx','txt','md'],true))self::fail('Upload a PDF, DOCX, TXT or Markdown module.');
        if($ext==='pdf'){
            if($mime!=='application/pdf')self::fail('The file is not a valid PDF.');
            $text=self::process(['/usr/bin/pdftotext','-f','1','-l','40','-enc','UTF-8','-nopgbrk',$path,'-'],180000);
        }elseif($ext==='docx'){
            if(!in_array($mime,['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],true))self::fail('The file is not a valid DOCX document.');
            $xml=self::process(['/usr/bin/unzip','-p',$path,'word/document.xml'],1500000);
            if(stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false)self::fail('Unsupported document XML.');
            $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
            try{$valid=$doc->loadXML($xml,LIBXML_NONET|LIBXML_COMPACT);if(!$valid)self::fail('The DOCX document cannot be read.');}
            finally{libxml_clear_errors();libxml_use_internal_errors($old);}
            $xpath=new DOMXPath($doc);$xpath->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');$text='';
            foreach($xpath->query('//w:p')?:[] as $paragraph){$text.=$paragraph->textContent."\n";if(strlen($text)>180000)self::fail('Module text is too large. Upload a shorter chapter.');}
        }else{
            if(!in_array($mime,['text/plain','text/markdown','application/octet-stream'],true))self::fail('Upload a plain-text module.');
            if($size>180000)self::fail('Module text is too large. Upload a shorter chapter.');
            $text=(string)file_get_contents($path);
        }
        if(!mb_check_encoding($text,'UTF-8')||str_contains($text,"\0"))self::fail('The module must contain readable UTF-8 text.');
        $text=trim(preg_replace('/[\x01-\x08\x0b\x0c\x0e-\x1f]/',' ',$text)??'');
        if(mb_strlen($text)<80)self::fail('Not enough readable text. For scanned PDFs, upload a text-based PDF, DOCX or TXT version.');
        // Keep the complete extracted source privately for a short time; bound the model context.
        return ['text'=>$text,'source_chars'=>mb_strlen($text),'used_chars'=>min(8000,mb_strlen($text)),
            'file_name'=>mb_substr(preg_replace('~[\\\\/\x00-\x1f]~','_',basename($name))??'module',0,200),
            'coverage_note'=>$ext==='pdf'?'Reads text from the first 40 PDF pages; generation uses up to 8,000 characters.':'Generation uses up to 8,000 characters of readable module text.'];
    }
    private static function process(array $command,int $limit): string
    {
        if(!is_executable($command[0])||!is_executable('/usr/bin/prlimit')||!function_exists('proc_open'))self::fail('Module reader unavailable. Install poppler-utils and unzip on the server, or upload a TXT module.',503);
        $proc=proc_open(array_merge(['/usr/bin/prlimit','--as=268435456','--cpu=8','--'],$command),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))self::fail('Module reader unavailable.',503);
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$error='';$start=microtime(true);$exit=-1;
        try{do{$out.=(string)stream_get_contents($pipes[1],65536);$error.=(string)stream_get_contents($pipes[2],16385);if(strlen($out)>$limit||strlen($error)>16384)self::fail('Module is too complex or too large. Upload a shorter chapter.');
            $state=proc_get_status($proc);if(!$state['running']){$exit=$state['exitcode'];break;}
            if(microtime(true)-$start>10)self::fail('Module could not be read in time. Upload a shorter chapter.');usleep(10000);
        }while(true);
        }finally{$state=proc_get_status($proc);if($state['running'])proc_terminate($proc,9);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($proc);if($exit<0)$exit=$closed;}
        if($exit!==0)self::fail('Module cannot be read. Use an unlocked, text-based file.');return $out;
    }
}
