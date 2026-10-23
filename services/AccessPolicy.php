<?php
declare(strict_types=1);

/** Shared current-state checks. Presence states remain valid; server suspension is view-only. */
final class AccessPolicy
{
    public function __construct(private PDO $db) {}

    public static function accountSql(string $user = 'u'): string
    {
        return "$user.deleted_at IS NULL AND $user.status IN ('active','offline','idle') AND NOT EXISTS(SELECT 1 FROM moderation_actions account_ma WHERE account_ma.target_user_id=$user.id AND account_ma.server_id IS NULL AND account_ma.is_active=1 AND account_ma.action_type='ban' AND (account_ma.expires_at IS NULL OR account_ma.expires_at>CURRENT_TIMESTAMP))";
    }

    /** Only fixed aliases supplied by application code may be interpolated here. */
    public static function moderationSql(string $user, string $server, string $mode = 'read'): string
    {
        $blocked = match ($mode) {
            'read' => "'ban'",
            'voice', 'edit' => "'ban','suspend'",
            'write' => "'ban','suspend','mute'",
            default => throw new InvalidArgumentException('Unknown access mode'),
        };
        return "NOT EXISTS(SELECT 1 FROM moderation_actions ma WHERE ma.target_user_id=$user.id
            AND (ma.server_id=$server.id OR ma.server_id IS NULL) AND ma.is_active=1
            AND ma.action_type IN ($blocked) AND (ma.expires_at IS NULL OR ma.expires_at>CURRENT_TIMESTAMP))";
    }

    public function account(int $uid): bool
    {
        $q=$this->db->prepare('SELECT 1 FROM users u WHERE u.id=? AND '.self::accountSql());
        $q->execute([$uid]);
        return (bool)$q->fetchColumn();
    }

    /** Platform bypass is explicit: resource membership semantics are not broadened. */
    public function server(int $uid, int $sid, string $mode = 'read', bool $platformBypass = false): bool
    {
        $membership=$platformBypass ? "(sm.user_id IS NOT NULL OR u.role IN ('admin','super_admin'))" : 'sm.user_id IS NOT NULL';
        $q=$this->db->prepare("SELECT 1 FROM servers s JOIN users u ON u.id=?
            LEFT JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=u.id
            WHERE s.id=? AND s.status='active' AND $membership AND ".self::accountSql().' AND '.self::moderationSql('u','s',$mode));
        $q->execute([$uid,$sid]);
        return (bool)$q->fetchColumn();
    }

    /** Bounded set reads for fanout and periodic connection reconciliation. */
    public function accounts(array $ids): array
    {
        $out=[];
        foreach(array_chunk(array_values(array_unique(array_map('intval',$ids))),100) as $batch){
            if(!$batch)continue;
            $in=implode(',',array_fill(0,count($batch),'?'));
            $q=$this->db->prepare("SELECT u.id FROM users u WHERE u.id IN ($in) AND ".self::accountSql());
            $q->execute($batch);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$out[(int)$id]=true;
        }
        return $out;
    }

    public function assertAccount(int $uid): void
    {
        if (!$this->account($uid)) throw new RuntimeException('Account access denied',403);
    }
}
