<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
AuthMiddleware::requireAuth();RoleMiddleware::requireRole(['student','facilitator','admin','super_admin','moderator']);
$aliases=['dashboard'=>'overview','courses'=>'spaces','rooms'=>'sessions','messages'=>'tools','notifpage'=>'notifications','calendar'=>'calendar','whiteboard'=>'workspace','files'=>'resources','notes'=>'notes','activity'=>'activity','insights'=>'profile','achievements'=>'activity','help'=>'help'];
$page=$aliases[(string)($_GET['page']??'')]??'tools';$sid=max(0,(int)($_GET['server_id']??0));
header('Location: '.BASE_URL.'/modules/student/dashboard.php?page='.$page.($sid?'&server_id='.$sid:''));exit;
