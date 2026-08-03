<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthMiddleware.php';

/**
 * RoleMiddleware — Role-based access control gate.
 * Always call after AuthMiddleware::requireAuth().
 */
class RoleMiddleware {

    private const HIERARCHY = [
        'student'     => 1,
        'facilitator' => 2,
        'moderator'   => 3,
        'admin'       => 4,
        'super_admin' => 5,
    ];

    /**
     * Require the user to have one of the given roles.
     * Accepts a single role string or an array of allowed roles.
     */
    public static function requireRole(array|string $roles, bool $apiMode = false): void {
        $user  = AuthMiddleware::requireAuth($apiMode);
        $roles = (array)$roles;

        if (!in_array($user['role'], $roles, true)) {
            if ($apiMode) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Insufficient permissions.']);
                exit;
            }
            http_response_code(403);
            // Show a minimal 403 page
            include dirname(__DIR__, 2) . '/includes/layout/403.php';
            exit;
        }
    }

    /**
     * Require the user's role level to be >= the given minimum role.
     */
    public static function requireMinRole(string $minRole, bool $apiMode = false): void {
        $user     = AuthMiddleware::requireAuth($apiMode);
        $userLevel = self::HIERARCHY[$user['role']] ?? 0;
        $minLevel  = self::HIERARCHY[$minRole]       ?? 99;

        if ($userLevel < $minLevel) {
            if ($apiMode) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Insufficient permissions.']);
                exit;
            }
            http_response_code(403);
            include dirname(__DIR__, 2) . '/includes/layout/403.php';
            exit;
        }
    }

    /**
     * Check (without redirecting) whether the current user has a role.
     */
    public static function hasRole(array|string $roles): bool {
        AuthMiddleware::startSession();
        $userRole = $_SESSION['role'] ?? '';
        return in_array($userRole, (array)$roles, true);
    }

    /**
     * Check whether the current user's level is at least $minRole.
     */
    public static function atLeast(string $minRole): bool {
        AuthMiddleware::startSession();
        $userLevel = self::HIERARCHY[$_SESSION['role'] ?? ''] ?? 0;
        $minLevel  = self::HIERARCHY[$minRole] ?? 99;
        return $userLevel >= $minLevel;
    }
}
