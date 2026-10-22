<?php
declare(strict_types=1);
final class CentrifugoService
{
    private string $key;
    private string $api;
    private ?CurlHandle $http = null;
    public function __construct(?string $secret=null, ?string $key=null, ?string $api=null)
    {
        // Legacy positional argument retained for internal callers, never used to sign or verify JWTs.
        if($secret!==null && strlen($secret)<32)throw new InvalidArgumentException('Invalid legacy credential argument');
        $this->key=$key ?? (string)env('CENTRIFUGO_API_KEY','');
        $this->api=rtrim($api ?? (string)env('CENTRIFUGO_API_URL','http://127.0.0.1:8001/api'),'/');
        if(strlen($this->key)<32) throw new RuntimeException('Centrifugo secrets are not configured');
        $parts=parse_url($this->api);
        if(($parts['scheme']??'')!=='http' || !in_array($parts['host']??'', ['127.0.0.1','localhost'],true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException('Centrifugo API must use a local HTTP endpoint');
    }
    public static function inbox(int $uid): string { if($uid<1)throw new InvalidArgumentException('Invalid user');return 'inbox:user'.$uid; }
    /** Retired: old browser credentials must never be used for broker admission. */
    public function token(int $uid, ?string $channel=null, ?int $now=null): string
    {
        throw new RuntimeException('Realtime JWT authentication retired; reload the client',410);
    }
    public function command(string $method, array $body): array
    {
        if(!in_array($method,['broadcast','info','disconnect'],true))throw new InvalidArgumentException('Unsupported broker command');
        if (function_exists('curl_init')) {
            // Retain the handle so sequential batches reuse the local HTTP connection.
            $this->http ??= curl_init();
            curl_setopt_array($this->http, [
                CURLOPT_URL => $this->api.'/'.$method,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode((object)$body,JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json','X-API-Key: '.$this->key],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_TIMEOUT_MS => 1000,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
            ]);
            $raw = curl_exec($this->http);
            $status = (int)curl_getinfo($this->http,CURLINFO_RESPONSE_CODE);
        } else {
        $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>1.0,'ignore_errors'=>true,'follow_location'=>0,
            'header'=>"Content-Type: application/json\r\nX-API-Key: ".$this->key."\r\n",
            'content'=>json_encode((object)$body,JSON_THROW_ON_ERROR)]]);
        $raw=@file_get_contents($this->api.'/'.$method,false,$context);
        $status=preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0]??'', $match) ? (int)$match[1] : 0;
        }
        $data=$raw!==false?json_decode($raw,true):null;
        if($status!==200 || !is_array($data) || isset($data['error']) || !isset($data['result']))throw new RuntimeException('Broker unavailable');
        return $data['result'];
    }
    public function disconnect(int $uid): void
    {
        if($uid<1)throw new InvalidArgumentException('Invalid user');
        $this->command('disconnect',['user'=>(string)$uid,'disconnect'=>['code'=>4503,'reason'=>'Account access revoked']]);
    }

    public function broadcast(array $users, array $event): void
    {
        $channels=array_map(fn($uid)=>self::inbox((int)$uid),array_values(array_unique($users)));
        // Keep large-server fanout requests bounded. A failed batch is retried; clients deduplicate IDs.
        foreach(array_chunk($channels,500) as $batch){
            $result=$this->command('broadcast',['channels'=>$batch,'data'=>$event]);
            $responses=$result['responses']??null;
            if(!is_array($responses) || count($responses)!==count($batch))throw new RuntimeException('Incomplete broker response');
            foreach($responses as $response)if(isset($response['error']))throw new RuntimeException('Broker rejected publication');
        }
    }
}
