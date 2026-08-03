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
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        }
        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}
}
