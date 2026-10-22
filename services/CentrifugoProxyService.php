<?php
declare(strict_types=1);
require_once __DIR__.'/AccessPolicy.php';
require_once __DIR__.'/CentrifugoService.php';

/** Only used after authenticating the private broker callback and PHP session. */
final class CentrifugoProxyService
{
    public function __construct(private PDO $db, private string $origin, private int $refreshSeconds=60)
    {
        if ($refreshSeconds<5 || $refreshSeconds>300) throw new InvalidArgumentException('Invalid refresh interval');
    }

    public static function denied(string $event): array
    {
        return $event==='refresh' ? ['result'=>['expired'=>true]]
            : ['disconnect'=>['code'=>4503,'reason'=>'Realtime access denied']];
    }

    public function authorize(string $event, array $request, int $sessionUser, string $origin, ?int $now=null): array
    {
        if (!in_array($event,['connect','refresh'],true)) throw new InvalidArgumentException('Invalid proxy event');
        if ($this->origin==='' || !hash_equals($this->origin,$origin) || $sessionUser<1
            || ($event==='refresh' && ($request['user']??null)!==(string)$sessionUser)
            || !(new AccessPolicy($this->db))->account($sessionUser)) return self::denied($event);
        // An inbox is account-scoped. Resource permissions are enforced at outbox/read boundaries.
        // Never copy client-provided data, headers, user, channels or meta into this result.
        $result=['expire_at'=>($now??time())+$this->refreshSeconds];
        if ($event==='connect') {
            $result['user']=(string)$sessionUser;
            $result['channels']=[CentrifugoService::inbox($sessionUser)];
        }
        return ['result'=>$result];
    }
}
