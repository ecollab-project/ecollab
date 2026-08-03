#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/server.php — Starts the Ratchet WebSocket server
 *
 * Usage:
 *   php websocket/bin/server.php
 *   php websocket/bin/server.php --port=8080 --host=0.0.0.0
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the CLI only.\n");
    exit(1);
}

define('ROOT', dirname(__DIR__, 2));

require ROOT . '/vendor/autoload.php';
require ROOT . '/config.php';
require ROOT . '/database/config/db.php';
require ROOT . '/websocket/ChatServer.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;

$options = getopt('', ['port::', 'host::']);
$port    = (int)($options['port'] ?? getenv('WS_PORT') ?: 8080);
$host    = $options['host'] ?? getenv('WS_HOST') ?: '0.0.0.0';

echo "╔══════════════════════════════════════╗\n";
echo "║     Ecollab WebSocket Server         ║\n";
echo "╚══════════════════════════════════════╝\n";
echo " Host : {$host}\n";
echo " Port : {$port}\n";
echo " PID  : " . getmypid() . "\n";
echo " Time : " . date('Y-m-d H:i:s') . "\n";
echo "──────────────────────────────────────\n";

$loop = Loop::get();
$chat = new ChatServer();

$server = IoServer::factory(
    new HttpServer(new WsServer($chat)),
    $port,
    $host,
    $loop
);

// ── Drain ws_relay table every 200 ms ───────────────────────────────────────
// Collab-tool PHP APIs write events here; we push them to WebSocket subscribers.
$loop->addPeriodicTimer(0.2, fn() => $chat->drainRelayTable());

echo " Server running. Press Ctrl+C to stop.\n";
echo "──────────────────────────────────────\n";

$server->run();
