<?php
declare(strict_types=1);
final class CentrifugoService
{
    private string $secret;
    private string $key;
    private string $api;
    public function __construct(?string $secret=null, ?string $key=null, ?string $api=null)
    {
        $this->secret=$secret ?? (string)env('CENTRIFUGO_TOKEN_SECRET','');
        $this->key=$key ?? (string)env('CENTRIFUGO_API_KEY','');
        $this->api=rtrim($api ?? (string)env('CENTRIFUGO_API_URL','http://127.0.0.1:8001/api'),'/');
        if(strlen($this->secret)<32 || strlen($this->key)<32) throw new RuntimeException('Centrifugo secrets are not configured');
        $parts=parse_url($this->api);
        if(($parts['scheme']??'')!=='http' || !in_array($parts['host']??'', ['127.0.0.1','localhost'],true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException('Centrifugo API must use a local HTTP endpoint');
    }
    public static function inbox(int $uid): string { if($uid<1)throw new InvalidArgumentException('Invalid user');return 'inbox:user'.$uid; }
    private static function b64(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
    public function token(int $uid, ?string $channel=null, ?int $now=null): string
    {
        $inbox=self::inbox($uid);
        if($channel!==null && $channel!==$inbox)throw new RuntimeException('Subscription denied',403);
        $now=$now ?? time();$claims=['sub'=>(string)$uid,'iat'=>$now,'exp'=>$now+300];
        if($channel!==null)$claims['channel']=$channel;
        $value=self::b64('{"alg":"HS256","typ":"JWT"}').'.'.self::b64(json_encode($claims,JSON_THROW_ON_ERROR));
        return $value.'.'.self::b64(hash_hmac('sha256',$value,$this->secret,true));
    }
    public function command(string $method, array $body): array
    {
        if(!in_array($method,['broadcast','info'],true))throw new InvalidArgumentException('Unsupported broker command');
        $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>1.0,'ignore_errors'=>true,'follow_location'=>0,
            'header'=>"Content-Type: application/json\r\nX-API-Key: ".$this->key."\r\n",
            'content'=>json_encode((object)$body,JSON_THROW_ON_ERROR)]]);
        $raw=@file_get_contents($this->api.'/'.$method,false,$context);
        $status=$http_response_header[0]??'';
        $data=$raw!==false?json_decode($raw,true):null;
        if(!preg_match('/^HTTP\/\S+ 200\b/',$status) || !is_array($data) || isset($data['error']) || !isset($data['result']))throw new RuntimeException('Broker unavailable');
        return $data['result'];
    }
    public function broadcast(array $users, array $event): void
    {
        $channels=array_map(fn($uid)=>self::inbox((int)$uid),array_values(array_unique($users)));
        // Keep large-server fanout requests bounded. A failed batch is retried; clients deduplicate IDs.
        foreach(array_chunk($channels,100) as $batch){
            $result=$this->command('broadcast',['channels'=>$batch,'data'=>$event]);
            $responses=$result['responses']??null;
            if(!is_array($responses) || count($responses)!==count($batch))throw new RuntimeException('Incomplete broker response');
            foreach($responses as $response)if(isset($response['error']))throw new RuntimeException('Broker rejected publication');
        }
    }
}
