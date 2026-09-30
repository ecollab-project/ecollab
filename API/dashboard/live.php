<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/UserService.php';

header('Content-Type: application/json; charset=utf-8');

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

function dashboardLiveJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        dashboardLiveJson([
            'success' => false,
            'error' => 'Method not allowed.',
        ], 405);
    }

    $userId = (int)$user['id'];
    $role = (string)($user['role'] ?? 'student');
    $service = new UserService();

    $data = match ($role) {
        'student' => $service->getStudentDashboardData($userId),
        'facilitator' => $service->getFacilitatorDashboardData($userId),
        'admin', 'super_admin', 'moderator' => $service->getAdminDashboardData($userId),
        default => throw new RuntimeException('Unsupported dashboard role.', 403),
    };

    dashboardLiveJson([
        'success' => true,
        'role' => $role,
        'generated_at' => gmdate('c'),
        'data' => $data,
    ]);
} catch (Throwable $e) {
    error_log('[dashboard/live] ' . $e->getMessage());

    $status = $e->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    dashboardLiveJson([
        'success' => false,
        'error' => APP_DEBUG ? $e->getMessage() : 'Unable to load live dashboard data.',
    ], $status);
}
