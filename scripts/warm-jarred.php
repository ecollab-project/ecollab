<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/services/OllamaService.php';
try { echo json_encode(['scope'=>'Local model warm-up, no user data','warmup'=>(new OllamaService())->warmUp()],JSON_PRETTY_PRINT)."\n"; }
catch(Throwable $e){fwrite(STDERR,"Jarred warm-up unavailable or busy. Check the local model service.\n");exit(1);}
