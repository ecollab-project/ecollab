<?php
declare(strict_types=1);
require_once __DIR__.'/RealtimeRevocationService.php';

/** Online check for a trusted signaling ingress. Does not evict existing media sessions. */
final class LiveKitAdmissionService
{
    public function __construct(private PDO $db, private string $key, private string $secret)
    {
        if ($key === '' || $secret === '') throw new RuntimeException('Media admission unavailable');
    }

    /** Use the same header-first selection as LiveKit. Reject ambiguous duplicate tokens. */
    public static function requestToken(string $authorization, string $query): string
    {
        if ($authorization !== '') {
            if (!str_starts_with($authorization, 'Bearer ')) throw new RuntimeException('Admission denied', 403);
            return substr($authorization, 7);
        }
        $tokens=[];
        foreach (explode('&', $query) as $pair) {
            $parts=explode('=', $pair, 2);
            if (urldecode($parts[0]) === 'access_token') $tokens[]=urldecode($parts[1] ?? '');
        }
        if (count($tokens) !== 1) throw new RuntimeException('Admission denied', 403);
        return $tokens[0];
    }

    public function authorize(string $token, ?int $now=null): void
    {
        $deny=static fn()=>throw new RuntimeException('Admission denied', 403);
        if (strlen($token)>16384 || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/D', $token, $m)) $deny();
        $decode=static function(string $s)use($deny): string {
            $raw=base64_decode(strtr($s, '-_', '+/'), true);
            if ($raw === false || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $s) $deny();
            return $raw;
        };
        try {
            $header=json_decode($decode($m[1]), true, 16, JSON_THROW_ON_ERROR);
            $claims=json_decode($decode($m[2]), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) { $deny(); }
        if (!is_array($header) || ($header['alg']??null)!=='HS256' || isset($header['crit'])
            || !hash_equals(hash_hmac('sha256', $m[1].'.'.$m[2], $this->secret, true), $decode($m[3]))) $deny();
        $now ??= time();
        if (!is_array($claims) || ($claims['iss']??null)!==$this->key
            || !is_int($claims['exp']??null) || $claims['exp']<=$now
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf']>$now))
            || !is_string($claims['sub']??null) || !preg_match('/^user-([1-9][0-9]*)$/D', $claims['sub'], $identity)
            || ($claims['video']['roomJoin']??null)!==true || !is_string($claims['video']['room']??null)) $deny();
        $uid=filter_var($identity[1], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($uid === false) $deny();
        $room=$claims['video']['room'];
        // Capacity fixtures retain their separate synthetic namespace and current private-server policy.
        if (preg_match('/^ecollab-capacity-\d{8}-\d{6}-[a-f0-9]{8}-[0-9]+$/D', $room)) {
            $meta=json_decode(is_string($claims['metadata']??null)?$claims['metadata']:'', true);
            $fixture=$meta['capacity_fixture']??null;
            if (!is_string($fixture) || !preg_match('/^k6-\d{8}-\d{6}-([a-f0-9]{8})$/D', $fixture, $suffix)) $deny();
            $email=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? 'u.username||?' : 'CONCAT(u.username,?)';
            $q=$this->db->prepare("SELECT s.id FROM servers s JOIN server_members sm ON sm.server_id=s.id JOIN users u ON u.id=sm.user_id WHERE s.slug=? AND s.type=? AND sm.user_id=? AND u.role=? AND substr(u.username,1,12)=? AND u.email=$email");
            $prefix='k6_'.$suffix[1].'_';
            $q->execute([$fixture,'private',$uid,'student',$prefix,'@example.invalid']);
            $sid=$q->fetchColumn();
            if (!$sid || !(new AccessPolicy($this->db))->server($uid,(int)$sid,'voice')) $deny();
            return;
        }
        $policy=new RealtimeRevocationService($this->db, static fn()=>[], static fn()=>null);
        if (!$policy->roomAllowed($room, $uid)) $deny();
    }
}
