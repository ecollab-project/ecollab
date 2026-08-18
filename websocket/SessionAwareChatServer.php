<?php

declare(strict_types=1);

/**
 * SessionAwareChatServer
 *
 * WebSocket authentication fallback for local/browser sessions.
 */

require_once __DIR__ . '/ChatServer.php';
require_once dirname(__DIR__) . '/security/middleware/AuthMiddleware.php';

use Ratchet\ConnectionInterface;

class SessionAwareChatServer extends ChatServer
{
    public function onMessage(ConnectionInterface $from, $rawMsg): void
    {
        $data = json_decode($rawMsg, true);

        if (is_array($data) && ($data['type'] ?? '') === 'auth') {
            if ($this->authenticateFromSession($from)) {
                return;
            }
        }

        parent::onMessage($from, $rawMsg);
    }

    private function authenticateFromSession(ConnectionInterface $conn): bool
    {
        try {
            $request = $conn->httpRequest ?? null;
            if (!$request || !method_exists($request, 'getHeader')) {
                return false;
            }

            $cookieHeader = (string)$request->getHeader('Cookie');
            if ($cookieHeader === '') {
                return false;
            }

            $sessionCookieName = session_name();
            if (!preg_match('/(?:^|;\s*)' . preg_quote($sessionCookieName, '/') . '=([^;]+)/', $cookieHeader, $match)) {
                return false;
            }

            $sessionId = trim($match[1]);
            if ($sessionId === '') {
                return false;
            }

            if (session_status() !== PHP_SESSION_NONE) {
                session_write_close();
            }

            session_id($sessionId);
            AuthMiddleware::startSession();

            $userId = (int)($_SESSION['user_id'] ?? 0);
            $username = (string)($_SESSION['username'] ?? '');
            $role = (string)($_SESSION['role'] ?? 'student');
            $fullName = (string)($_SESSION['full_name'] ?? $username);
            $gradient = (string)($_SESSION['avatar_gradient'] ?? $_SESSION['avatar_color_gradient'] ?? '');

            session_write_close();

            if ($userId <= 0 || $username === '') {
                return false;
            }

            $reflection = new ReflectionClass(ChatServer::class);
            $dbProperty = $reflection->getProperty('db');
            $dbProperty->setAccessible(true);
            /** @var PDO $db */
            $db = $dbProperty->getValue($this);

            $stmt = $db->prepare('SELECT id, username, status, full_name, avatar_color_gradient FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            $stmt->closeCursor();

            if (!$user || ($user['status'] ?? '') !== 'active') {
                return false;
            }

            $metaProperty = $reflection->getProperty('connMeta');
            $metaProperty->setAccessible(true);
            $connMeta = $metaProperty->getValue($this);

            // Ratchet's ConnectionInterface does not declare resourceId, although
            // Ratchet's concrete connection supplies it at runtime. Read the
            // dynamic property through get_object_vars so PHPStan can analyze the
            // interface safely while preserving the existing ChatServer key.
            $connectionVars = get_object_vars($conn);
            $ridValue = $connectionVars['resourceId'] ?? null;
            if (!is_int($ridValue) && !is_string($ridValue)) {
                return false;
            }
            $rid = (string)$ridValue;

            if (!isset($connMeta[$rid])) {
                return false;
            }

            $connMeta[$rid]['user_id'] = (int)$user['id'];
            $connMeta[$rid]['username'] = (string)$user['username'];
            $connMeta[$rid]['full_name'] = $user['full_name'] ?? $fullName;
            $connMeta[$rid]['gradient'] = $user['avatar_color_gradient'] ?? $gradient;
            $connMeta[$rid]['role'] = $role;
            $connMeta[$rid]['authed'] = true;
            $metaProperty->setValue($this, $connMeta);

            $connsProperty = $reflection->getProperty('userConns');
            $connsProperty->setAccessible(true);
            $userConns = $connsProperty->getValue($this);
            $userConns[(int)$user['id']][] = $conn;
            $connsProperty->setValue($this, $userConns);

            $presenceMethods = [
                'setUserOnline' => [(int)$user['id'], true],
                'broadcastPresence' => [(int)$user['id'], true, (string)$user['username']],
            ];
            foreach ($presenceMethods as $method => $args) {
                try {
                    $m = $reflection->getMethod($method);
                    $m->setAccessible(true);
                    $m->invokeArgs($this, $args);
                } catch (Throwable) {
                    // Presence is supplementary; successful WS auth must not fail because of it.
                }
            }

            $conn->send(json_encode([
                'type' => 'auth_ok',
                'user_id' => (int)$user['id'],
                'auth_method' => 'session',
            ]));
            echo "[WS] User {$user['username']} ({$user['id']}) authenticated via session on {$rid}\n";
            return true;
        } catch (Throwable $e) {
            echo "[WS] Session auth fallback error: {$e->getMessage()}\n";
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            return false;
        }
    }
}
