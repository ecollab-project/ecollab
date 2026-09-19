<?php
declare(strict_types=1);

// Ensure config is always loaded first, regardless of which module includes this
if (!defined('DB_HOST')) {
    require_once dirname(__DIR__, 2) . '/config.php';
}

/**
 * Database — Single PDO singleton used by BOTH auth and chat modules.
 * All require_once calls across the project resolve to this one class.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    private static function createConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);
    }

    /**
     * Return a working connection.
     *
     * Reconnects automatically when MySQL has closed
     * the persistent connection.
     */
    public static function reconnect(): PDO
    {
        self::$instance = self::createConnection();

        return self::$instance;
    }

    /**
     * Return a healthy PDO connection.
     *
     * This is intended for long-running processes such as
     * the WebSocket server, where MySQL may close idle connections.
     */
    public static function getHealthyConnection(): PDO
    {
        $db = self::getInstance();

        try {
            $db->query('SELECT 1');
            return $db;
        } catch (PDOException $e) {
            $message = strtolower($e->getMessage());

            if (
                str_contains($message, 'server has gone away') ||
                str_contains($message, 'no connection to the server') ||
                str_contains($message, 'lost connection')
            ) {
                return self::reconnect();
            }

            throw $e;
        }
    }

    /**
     * Execute a database callback and retry once if the
     * MySQL connection has gone away.
     */
    public static function withReconnect(callable $callback): mixed
    {
        try {
            return $callback(self::getInstance());
        } catch (PDOException $e) {
            $message = strtolower($e->getMessage());

            $lostConnection =
                str_contains($message, 'server has gone away') ||
                str_contains($message, 'no connection to the server') ||
                str_contains($message, 'lost connection');

            if (!$lostConnection) {
                throw $e;
            }

            return $callback(self::reconnect());
        }
    }

    private function __construct() {}
    private function __clone() {}
}
