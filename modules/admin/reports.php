<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
AuthMiddleware::requireAuth();RoleMiddleware::requireRole(['admin','super_admin']);
$server=max(0,(int)($_GET['server_id']??0));
header('Location: '.BASE_URL.'/modules/admin/dashboard.php?page=reports'.($server?'&server_id='.$server:''));exit;
