<?php

declare(strict_types=1);


require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/AuthService.php';

AuthMiddleware::startSession();

$service = new AuthService();
$service->logout();
AuthMiddleware::destroySession();

header('Location: ' . BASE_URL . '/modules/auth/login.php');
exit;
