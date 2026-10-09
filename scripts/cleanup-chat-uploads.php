<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config.php';
require_once ROOT_PATH.'/services/ChatTusStorage.php';
$store = new ChatTusStorage((string)env('CHAT_TUS_STORAGE', dirname(ROOT_PATH).'/ecollab-upload-parts'), UPLOAD_DIR, BASE_URL);
echo 'Expired upload records removed: ', $store->cleanup(), PHP_EOL;
