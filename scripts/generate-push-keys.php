<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);require dirname(__DIR__).'/tools/web-push/vendor/autoload.php';
echo json_encode(Minishlink\WebPush\VAPID::createVapidKeys(),JSON_THROW_ON_ERROR);
