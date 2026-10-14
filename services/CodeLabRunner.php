<?php
declare(strict_types=1);
/** Only a server-configured Judge0 endpoint receives source. No local shell/eval. */
final class CodeLabRunner
{
    private string $url;
    private string $token;
    private ?Closure $transport;
    public function __construct(?string $url=null,?string $token=null,?callable $transport=null)
    {
        $this->url=rtrim($url??(string)(getenv('CODE_LAB_JUDGE0_URL')?:''),'/');
        $this->token=$token??(string)(getenv('CODE_LAB_JUDGE0_TOKEN')?:'');
        $this->transport=$transport?Closure::fromCallable($transport):null;
        if ($this->url!=='') {
            $p=parse_url($this->url);$host=$p['host']??'';
            $private=$host==='localhost'||$host==='127.0.0.1'||filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) && !filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE);
            if (!$p||!in_array($p['scheme']??'',['https','http'],true)||($p['scheme']==='http'&&!$private)||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||preg_match('/[\r\n]/',$this->token)) throw new RuntimeException('Invalid Code Lab runner configuration.');
        }
    }
    public function enabled(): bool {return $this->url!=='';}
    public static function runtime(string $language): ?int
    {
        $map=json_decode((string)(getenv('CODE_LAB_LANGUAGE_IDS')?:'{}'),true);
        $id=is_array($map)?($map[$language]??null):null;
        return is_int($id)&&$id>0?$id:null;
    }
    public function request(string $method,string $path,?array $body=null): array
    {
        if (!$this->enabled()) throw new RuntimeException('Code runner is not configured.');
        if ($this->transport) return ($this->transport)($method,$path,$body);
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for Code Lab execution.');
        $ch=curl_init($this->url.$path);$raw='';
        try {
            curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Auth-Token: '.$this->token],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>1048576)return 0;$raw.=$chunk;return strlen($chunk);}]);
            if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
            $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            if ($ok===false||$status<200||$status>=300) throw new RuntimeException('Code runner request failed.');
            $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Invalid runner response.');return $data;
        } finally {curl_close($ch);}
    }
    public function execute(int $runtime,string $source,string $stdin): array
    {
        $created=$this->request('POST','/submissions?base64_encoded=true&wait=false',[
            'language_id'=>$runtime,'source_code'=>base64_encode($source),'stdin'=>base64_encode($stdin),
            'cpu_time_limit'=>2,'cpu_extra_time'=>0.5,'wall_time_limit'=>8,'memory_limit'=>262144,
            'stack_limit'=>1048576,'max_processes_and_or_threads'=>64,'max_file_size'=>64,
            'enable_network'=>false,'enable_per_process_and_thread_time_limit'=>false,'enable_per_process_and_thread_memory_limit'=>false,'number_of_runs'=>1,'redirect_stderr_to_stdout'=>false
        ]);
        $token=$created['token']??'';if(!is_string($token)||!preg_match('/^[a-zA-Z0-9-]{8,100}$/D',$token))throw new RuntimeException('Invalid runner submission token.');
        $limit=microtime(true)+45;
        do {
            $result=$this->request('GET','/submissions/'.rawurlencode($token).'?base64_encoded=true&fields=status,stdout,stderr,compile_output,time,memory,exit_code');
            $status=(int)($result['status']['id']??0);
            if($status>2)break;
            if(!$this->transport)usleep(250000);
        } while(microtime(true)<$limit);
        if(!in_array($status,range(3,12),true))throw new RuntimeException('Runner did not finish reliably.');
        foreach(['stdout','stderr','compile_output']as$key){$value=$result[$key]??null;$decoded=$value===null?'':base64_decode((string)$value,true);if($decoded===false)throw new RuntimeException('Invalid runner output encoding.');$result[$key]=json_decode(json_encode(substr($decoded,0,16384),JSON_INVALID_UTF8_SUBSTITUTE),true);}
        // Use a local status vocabulary, never runner-supplied markup or internals.
        $result['status']=['id'=>$status,'description'=>match($status){3=>'Finished',4=>'Wrong answer',5=>'Time limit exceeded',6=>'Compilation error',default=>'Runtime error'}];
        return $result;
    }
}
