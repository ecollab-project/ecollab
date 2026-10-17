<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
AuthMiddleware::requireAuth();RoleMiddleware::requireRole(['facilitator','admin','super_admin','moderator']);
$aliases=['dashboard'=>'overview','servermonitoring'=>'spaces','mychannel'=>'spaces','members'=>'members','useractivity'=>'members','announcements'=>'announcements','reports'=>'reports','banned'=>'banned','chlogs'=>'monitor','sessions'=>'sessions','engagement'=>'monitor','leaderboards'=>'monitor','resources'=>'resources','roles'=>'permissions','files'=>'workspace','messages'=>'tools','whiteboard'=>'workspace','polls'=>'tools','chsettings'=>'spacesettings'];
$page=$aliases[(string)($_GET['page']??'')]??'tools';$sid=max(0,(int)($_GET['server_id']??0));
header('Location: '.BASE_URL.'/modules/facilitator/dashboard.php?page='.$page.($sid?'&server_id='.$sid:''));exit;
