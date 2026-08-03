<?php
declare(strict_types=1);

/**
 * security/SchemaVersion.php
 *
 * Runtime database capability detection for enterprise-grade
 * old/new version coexistence.
 *
 * Application code that depends on tables/columns introduced by
 * recent migrations (e.g. `users.plan_id`, `pm_compatibility`,
 * `security_audit_log`) should check SchemaVersion::has*() before
 * relying on them, so the SAME codebase runs correctly whether the
 * database has applied 0, some, or all migrations.
 *
 * Results are cached for the lifetime of the request (static cache)
 * AND across requests via a short-lived APCu/file cache when
 * available, since information_schema queries are relatively
 * expensive to run on every page load.
 *
 * Usage:
 *   if (SchemaVersion::hasColumn('users', 'plan_id')) {
 *       $sql .= ', u.plan_id';
 *   }
 *
 *   if (SchemaVersion::hasTable('pm_compatibility')) {
 *       // peer matching feature is available
 *   }
 *
 *   $report = SchemaVersion::diagnostics(); // for health-check endpoint
 */

class SchemaVersion
{
    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_KEY = 'ecollab_schema_version_v1';

    /** @var array<string,bool> */
    private static array $tableCache = [];

    /** @var array<string,bool> */
    private static array $columnCache = [];

    /** @var array<string,array<int,string>>|null */
    private static ?array $allColumns = null;

    /** @var array<int,string>|null */
    private static ?array $allTables = null;

    /** @var array<string,array{applied_at:string,success:bool}>|null */
    private static ?array $migrations = null;

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Does the given table exist?
     */
    public static function hasTable(string $table): bool
    {
        if (isset(self::$tableCache[$table])) return self::$tableCache[$table];

        self::loadTables();
        $result = in_array($table, self::$allTables ?? [], true);
        self::$tableCache[$table] = $result;
        return $result;
    }

    /**
     * Does the given column exist on the given table?
     * Returns false if the table itself doesn't exist.
     */
    public static function hasColumn(string $table, string $column): bool
    {
        $key = "$table.$column";
        if (isset(self::$columnCache[$key])) return self::$columnCache[$key];

        if (!self::hasTable($table)) {
            self::$columnCache[$key] = false;
            return false;
        }

        self::loadColumns();
        $result = in_array($column, self::$allColumns[$table] ?? [], true);
        self::$columnCache[$key] = $result;
        return $result;
    }

    /**
     * Returns true only if ALL given columns exist on the table.
     * Convenience for guarding SELECT clauses with multiple new columns.
     */
    public static function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $col) {
            if (!self::hasColumn($table, $col)) return false;
        }
        return true;
    }

    /**
     * Has a specific migration been successfully applied?
     */
    public static function hasMigration(string $filename): bool
    {
        self::loadMigrations();
        return self::$migrations[$filename]['success'] ?? false;
    }

    /**
     * Build a SELECT column list, including optional columns only if
     * they exist in the schema. Always includes the required columns.
     *
     * Example:
     *   SchemaVersion::selectColumns('users', ['id','email'], [
     *       'plan_id'               => 'u.plan_id',
     *       'avatar_color_gradient' => 'u.avatar_color_gradient',
     *   ])
     *   // => "id, email, u.plan_id, u.avatar_color_gradient"  (if both exist)
     *   // => "id, email"                                       (if neither exist)
     *
     * @param string $table     Table to check optional columns against
     * @param array  $required  Column expressions always included (raw SQL fragments)
     * @param array  $optional  Map of column_name => SQL fragment to include if column exists
     */
    public static function selectColumns(string $table, array $required, array $optional): string
    {
        $parts = $required;
        foreach ($optional as $colName => $expr) {
            if (self::hasColumn($table, $colName)) $parts[] = $expr;
        }
        return implode(', ', $parts);
    }

    /**
     * Returns the value of $session[$key] if the column exists in the
     * schema and the key is present, otherwise $default.
     * Useful for $_SESSION population in writeSession().
     */
    public static function optionalValue(array $row, string $table, string $column, mixed $default = null): mixed
    {
        if (!self::hasColumn($table, $column)) return $default;
        return $row[$column] ?? $default;
    }

    /**
     * Full diagnostics report for the health-check endpoint.
     * Reports which optional features are available based on schema state.
     */
    public static function diagnostics(): array
    {
        self::loadTables();
        self::loadMigrations();

        $features = [
            'subscriptions' => [
                'available' => self::hasTable('subscription_plans') && self::hasColumn('users', 'plan_id'),
                'tables'    => ['subscription_plans'],
                'migration' => '017_user_plan_id.sql',
            ],
            'collaboration_tools' => [
                'available' => self::hasTable('collab_notes') && self::hasTable('collab_boards'),
                'tables'    => ['collab_notes', 'collab_boards', 'collab_tasks', 'collab_quizzes'],
                'migration' => '011_collab_tools.sql',
            ],
            'collaboration_extra' => [
                'available' => self::hasTable('collab_decks') && self::hasTable('collab_resources'),
                'tables'    => ['collab_decks', 'collab_flashcards', 'collab_mindmaps', 'collab_resources'],
                'migration' => '012_collab_extra.sql',
            ],
            'peer_matching' => [
                'available' => self::hasTable('pm_compatibility') && self::hasTable('pm_subjects'),
                'tables'    => ['pm_subjects', 'pm_compatibility', 'pm_match_requests'],
                'migration' => '013_peer_matching.sql',
            ],
            'security_audit' => [
                'available' => self::hasTable('security_audit_log'),
                'tables'    => ['security_audit_log', 'account_lockouts', 'ip_blocks'],
                'migration' => '014_security.sql',
            ],
            'field_encryption' => [
                'available' => self::hasTable('user_encrypted_pii') && self::sodiumAvailable(),
                'tables'    => ['user_encrypted_pii', 'encryption_key_versions'],
                'migration' => '014_security.sql',
                'note'      => self::sodiumAvailable() ? null : 'sodium PHP extension not loaded',
            ],
            'websocket_relay' => [
                'available' => self::hasTable('ws_relay') && self::hasTable('ws_tokens'),
                'tables'    => ['ws_relay', 'ws_tokens'],
                'migration' => 'created dynamically by ChatServer/ws-token.php',
            ],
            'oauth' => [
                'available' => self::hasColumn('users', 'sso_provider') && self::hasColumn('users', 'sso_uid'),
                'tables'    => ['users (sso_provider, sso_uid)'],
                'migration' => '005_oauth_columns.sql',
            ],
            'direct_messages' => [
                'available' => self::hasTable('dm_conversations') || self::hasTable('direct_messages'),
                'tables'    => ['dm_conversations', 'direct_messages'],
                'migration' => '008_dm_migration.sql',
            ],
        ];

        $appliedCount  = count(array_filter(self::$migrations ?? [], fn($m) => $m['success']));
        $totalKnown    = self::countKnownMigrations();

        return [
            'schema_migrations_table' => self::hasTable('schema_migrations'),
            'migrations_applied'      => $appliedCount,
            'migrations_total_known'  => $totalKnown,
            'table_count'             => count(self::$allTables ?? []),
            'features'                => $features,
            'php_extensions'          => [
                'sodium' => self::sodiumAvailable(),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'curl'   => extension_loaded('curl'),
            ],
        ];
    }

    /**
     * Clear all cached data. Call after running migrations.
     */
    public static function clearCache(): void
    {
        self::$tableCache  = [];
        self::$columnCache = [];
        self::$allColumns  = null;
        self::$allTables   = null;
        self::$migrations  = null;
        if (function_exists('apcu_delete')) {
            @apcu_delete(self::CACHE_KEY . '_tables');
            @apcu_delete(self::CACHE_KEY . '_columns');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    private static function loadTables(): void
    {
        if (self::$allTables !== null) return;

        // Try APCu cache first
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch(self::CACHE_KEY . '_tables', $ok);
            if ($ok && is_array($cached)) {
                self::$allTables = $cached;
                return;
            }
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->query("
                SELECT TABLE_NAME FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
            ");
            self::$allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            self::$allTables = [];
        }

        if (function_exists('apcu_store')) {
            @apcu_store(self::CACHE_KEY . '_tables', self::$allTables, self::CACHE_TTL);
        }
    }

    private static function loadColumns(): void
    {
        if (self::$allColumns !== null) return;

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch(self::CACHE_KEY . '_columns', $ok);
            if ($ok && is_array($cached)) {
                self::$allColumns = $cached;
                return;
            }
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->query("
                SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
            ");
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
            }
            self::$allColumns = $map;
        } catch (\Throwable) {
            self::$allColumns = [];
        }

        if (function_exists('apcu_store')) {
            @apcu_store(self::CACHE_KEY . '_columns', self::$allColumns, self::CACHE_TTL);
        }
    }

    private static function loadMigrations(): void
    {
        if (self::$migrations !== null) return;

        self::$migrations = [];
        if (!self::hasTable('schema_migrations')) return;

        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT filename, applied_at, success FROM schema_migrations");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                self::$migrations[$row['filename']] = [
                    'applied_at' => $row['applied_at'],
                    'success'    => (bool)$row['success'],
                ];
            }
        } catch (\Throwable) {
            self::$migrations = [];
        }
    }

    private static function countKnownMigrations(): int
    {
        $dir = dirname(__DIR__) . '/database/migrations';
        if (!is_dir($dir)) return 0;
        return count(glob($dir . '/*.sql'));
    }

    public static function sodiumAvailable(): bool
    {
        return extension_loaded('sodium')
            && function_exists('sodium_crypto_aead_aes256gcm_encrypt')
            && @sodium_crypto_aead_aes256gcm_is_available();
    }
}
