<?php
declare(strict_types=1);
// Only the matching internal Nginx FastCGI location may set this non-HTTP parameter.
header('Cache-Control: no-store');
if (($_SERVER['ECOLLAB_INTERNAL_MEDIA_ADMISSION']??'') !== '1') { http_response_code(404); exit; }
try {
    require_once dirname(__DIR__,2).'/database/config/db.php';
    require_once dirname(__DIR__,2).'/services/LiveKitAdmissionService.php';
    $token=LiveKitAdmissionService::requestToken((string)($_SERVER['HTTP_AUTHORIZATION']??''), (string)($_SERVER['ECOLLAB_MEDIA_QUERY']??''));
    (new LiveKitAdmissionService(Database::getInstance(), (string)getenv('LIVEKIT_API_KEY'), (string)getenv('LIVEKIT_API_SECRET')))->authorize($token);
    http_response_code(204);
} catch (Throwable $e) {
    // No token, query string, student identity or database exception in the response/log.
    http_response_code($e instanceof RuntimeException && $e->getCode()===403 ? 403 : 503);
}
