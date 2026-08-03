<?php
declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/AuthService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

CSRF::verify();

$body           = json_decode(file_get_contents('php://input'), true) ?? [];
$resetToken     = trim((string)($body['reset_token']      ?? ''));
$newPassword    = (string)($body['new_password']          ?? '');
$confirmPassword = (string)($body['confirm_password']     ?? '');

if ($resetToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid reset session. Please start again.']);
    exit;
}

try {
    $service = new AuthService();
    $outcome = $service->resetPassword($resetToken, $newPassword, $confirmPassword);
    http_response_code($outcome['success'] ? 200 : 422);
    echo json_encode($outcome);
} catch (Throwable $e) {
    error_log('[API/auth/reset-password] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Reset failed. Please try again.']);
}
