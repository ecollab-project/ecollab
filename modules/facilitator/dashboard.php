<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
$user=AuthMiddleware::requireAuth();
RoleMiddleware::requireRole(['facilitator','admin','super_admin','moderator']);
$csrfToken=AuthMiddleware::csrfToken();
$learningMode='facilitator';
require ROOT_PATH.'/includes/layout/learning-workspace.php';
