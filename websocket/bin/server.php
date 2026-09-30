#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Run this from the CLI only.\n"); exit(1); }
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/vendor/autoload.php';
require_once ROOT . '/config.php';
require_once ROOT . '/database/config/db.php';
require_once ROOT . '/websocket/ChatServer.php';
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
$options=getopt('', ['port::','host::']);$port=(int)($options['port']??getenv('WS_PORT')?:8080);$host=$options['host']??getenv('WS_HOST')?:'0.0.0.0';
echo "╔══════════════════════════════════════╗\n║     Ecollab WebSocket Server         ║\n╚══════════════════════════════════════╝\n";echo " Host : {$host}\n Port : {$port}\n PID  : ".getmypid()."\n Time : ".date('Y-m-d H:i:s')."\n──────────────────────────────────────\n";
$loop=Loop::get();$chat=new ChatServer();$server=IoServer::factory(new HttpServer(new WsServer($chat)),$port,$host);
$loop->addPeriodicTimer(0.2, static function() use($chat):void{$chat->drainRelayTable();$chat->persistDirtyCodeSessions();});
echo " Server running. Press Ctrl+C to stop.\n──────────────────────────────────────\n";$server->run();
