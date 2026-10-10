<?php
declare(strict_types=1);
require_once __DIR__.'/AuthMiddleware.php';
final class RolePolicy {
    public static function isSysAdmin(array|string|null $user=null): bool {
        $role=is_array($user)?($user['role']??''):(is_string($user)?$user:($_SESSION['role']??''));
        return $role==='super_admin';
    }
    public static function canMonitor(array $user): bool { return in_array($user['role']??'', ['facilitator','admin','super_admin'],true); }
    public static function canMonitorSystem(array $user): bool { return in_array($user['role']??'', ['admin','super_admin'],true); }
    public static function canModerateAcademic(array $user): bool { return in_array($user['role']??'', ['facilitator','admin','super_admin'],true); }
    public static function isInvisibleSystemMember(array $user): bool { return self::isSysAdmin($user); }
    public static function visibleMemberSql(string $alias='u'): string { return $alias.".role <> 'super_admin'"; }
    public static function assertMonitor(array $user,bool $api=true): void { if(!self::canMonitor($user))self::deny($api); }
    public static function assertSystemMonitor(array $user,bool $api=true): void { if(!self::canMonitorSystem($user))self::deny($api); }
    private static function deny(bool $api): never { http_response_code(403);if($api){header('Content-Type: application/json');echo json_encode(['success'=>false,'error'=>'Insufficient monitoring permissions.']);}exit; }
}
