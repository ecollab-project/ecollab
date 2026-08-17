<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

AuthMiddleware::verifyCsrf();

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $fullName = trim((string)($body['full_name'] ?? ''));
    $bio = trim((string)($body['bio'] ?? ''));
    $gradient = trim((string)($body['avatar_color_gradient'] ?? ''));

    if ($fullName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Display name is required']);
        exit;
    }
    if (mb_strlen($fullName) > 80) {
        http_response_code(400);
        echo json_encode(['error' => 'Display name must be 80 characters or fewer']);
        exit;
    }
    if (mb_strlen($bio) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'Bio must be 500 characters or fewer']);
        exit;
    }

    if ($gradient === '' || !preg_match('/^#[0-9a-fA-F]{6}(?:,#[0-9a-fA-F]{6})?$/', $gradient)) {
        $gradient = '#a855f7,#ec4899';
    }

    $db = Database::getInstance();
    $stmt = $db->prepare('UPDATE users SET full_name = :full_name, bio = :bio, avatar_color_gradient = :gradient WHERE id = :id LIMIT 1');
    $stmt->execute([
        ':full_name' => $fullName,
        ':bio' => $bio,
        ':gradient' => $gradient,
        ':id' => (int)$user['id'],
    ]);

    $_SESSION['full_name'] = $fullName;

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'profile' => [
            'full_name' => $fullName,
            'bio' => $bio,
            'avatar_color_gradient' => $gradient,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[update-profile] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error']);
}
