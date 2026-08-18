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

// Keep the long-running CLI process from marking PHP headers as sent before
// the session-backed WebSocket authentication has a chance to resume the
// browser's PHP session. Ratchet handles the actual WebSocket handshake;
// this buffer is only for the server's console output and deprecation notices.
if (ob_get_level() === 0) {
    ob_start();
}

define('ROOT', dirname(__DIR__, 2));

require_once ROOT . '/vendor/autoload.php';
require_once ROOT . '/config.php';
require_once ROOT . '/database/config/db.php';
require_once ROOT . '/websocket/SessionAwareChatServer.php';

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
$chat = new SessionAwareChatServer();

$server = IoServer::factory(
    new HttpServer(new WsServer($chat)),
    $port,
    $host
);

// ── Drain ws_relay table every 200 ms ───────────────────────────────────────
// Collab-tool PHP APIs write events here; we push them to WebSocket subscribers.
$loop->addPeriodicTimer(0.2, fn() => $chat->drainRelayTable());

echo " Server running. Press Ctrl+C to stop.\n";
echo "──────────────────────────────────────\n";

$server->run();
