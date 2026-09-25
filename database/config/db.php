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
class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$instance = self::connect();
        }
        return self::$instance;
    }

    /**
     * Return a live PDO connection.
     * Long-running workers (WebSocket/queue processes) can outlive MySQL's
     * idle timeout, so validate the singleton before reusing it.
     */
    public static function getLiveInstance(): PDO {
        if (self::$instance !== null) {
            try {
                self::$instance->query('SELECT 1')->fetchColumn();
                return self::$instance;
            } catch (PDOException $e) {
                // 2006/2013 = server gone away / lost connection.
                if (!in_array((int)($e->errorInfo[1] ?? 0), [2006, 2013], true)
                    && !str_contains($e->getMessage(), 'server has gone away')) {
                    throw $e;
                }
                self::$instance = null;
            }
        }
        self::$instance = self::connect();
        return self::$instance;
    }

    public static function reconnect(): PDO {
        self::$instance = null;
        self::$instance = self::connect();
        return self::$instance;
    }

    private static function connect(): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    }

    private function __construct() {}
    private function __clone() {}
}
