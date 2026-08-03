<?php
declare(strict_types=1);

/**
 * security/FieldEncryption.php
 *
 * AES-256-GCM field-level encryption using PHP's sodium extension.
 * Encrypts sensitive PII (email, full_name, phone, student_id) before
 * storing in user_encrypted_pii.  The plaintext columns in `users` are
 * kept for search/display; the encrypted table is for compliance.
 *
 * Key derivation:
 *   HKDF-SHA256(APP_KEY, salt="ecollab-pii-v1", length=32)
 *
 * Ciphertext format (base64-encoded):
 *   nonce(24 bytes) || ciphertext || tag(16 bytes appended by sodium)
 *
 * Usage:
 *   $enc  = FieldEncryption::encrypt("john@example.com");
 *   $plain = FieldEncryption::decrypt($enc);  // → "john@example.com"
 *
 *   // Store/retrieve the full PII row:
 *   FieldEncryption::storePii($userId, ['email' => '...', 'full_name' => '...']);
 *   $pii = FieldEncryption::retrievePii($userId);
 */

class FieldEncryption
{
    private const SALT       = 'ecollab-pii-v1';
    private const KEY_LEN    = SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES; // 32
    private const NONCE_LEN  = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES; // 12
    private const AAD        = 'ecollab-field-enc'; // additional authenticated data

    private static ?string $derivedKey = null;

    // ─────────────────────────────────────────────────────────────────────
    // Core encrypt / decrypt
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Encrypt a plaintext string.
     * Returns base64url-encoded nonce+ciphertext, or null on failure.
     */
    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') return null;

        if (!self::available()) {
            // sodium not available — log warning and return as-is (degraded mode)
            error_log('[Ecollab] FieldEncryption: sodium extension not available');
            return null;
        }

        try {
            $key   = self::getKey();
            $nonce = random_bytes(self::NONCE_LEN);
            $ct    = sodium_crypto_aead_aes256gcm_encrypt(
                $plaintext,
                self::AAD,
                $nonce,
                $key
            );
            // Wipe plaintext from memory
            sodium_memzero($plaintext);
            return base64_encode($nonce . $ct);
        } catch (\Throwable $e) {
            error_log('[Ecollab] FieldEncryption::encrypt error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Decrypt a previously encrypted value.
     * Returns plaintext string, or null if decryption fails.
     */
    public static function decrypt(?string $ciphertext): ?string
    {
        if ($ciphertext === null || $ciphertext === '') return null;

        if (!self::available()) return null;

        try {
            $raw   = base64_decode($ciphertext, strict: true);
            if ($raw === false || strlen($raw) < self::NONCE_LEN + 16) return null;

            $key   = self::getKey();
            $nonce = substr($raw, 0, self::NONCE_LEN);
            $ct    = substr($raw, self::NONCE_LEN);

            $plain = sodium_crypto_aead_aes256gcm_decrypt(
                $ct,
                self::AAD,
                $nonce,
                $key
            );
            return $plain === false ? null : $plain;
        } catch (\Throwable $e) {
            error_log('[Ecollab] FieldEncryption::decrypt error: ' . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PII store / retrieve (DB layer)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Encrypt and store PII fields for a user.
     *
     * $fields: ['email' => '...', 'full_name' => '...', 'phone' => '...', 'student_id' => '...']
     */
    public static function storePii(int $userId, array $fields): void
    {
        if (!self::available()) return;

        try {
            $db = Database::getInstance();
            $db->prepare("
                INSERT INTO user_encrypted_pii
                    (user_id, email_enc, full_name_enc, phone_enc, student_id_enc, key_version, updated_at)
                VALUES
                    (:uid, :email, :fname, :phone, :sid, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    email_enc      = VALUES(email_enc),
                    full_name_enc  = VALUES(full_name_enc),
                    phone_enc      = VALUES(phone_enc),
                    student_id_enc = VALUES(student_id_enc),
                    key_version    = 1,
                    updated_at     = NOW()
            ")->execute([
                ':uid'   => $userId,
                ':email' => self::encrypt($fields['email']      ?? null),
                ':fname' => self::encrypt($fields['full_name']  ?? null),
                ':phone' => self::encrypt($fields['phone']      ?? null),
                ':sid'   => self::encrypt($fields['student_id'] ?? null),
            ]);
        } catch (\Throwable $e) {
            error_log('[Ecollab] FieldEncryption::storePii error: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve and decrypt PII for a user.
     * Returns ['email' => '...', 'full_name' => '...', ...] or empty array.
     */
    public static function retrievePii(int $userId): array
    {
        if (!self::available()) return [];

        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM user_encrypted_pii WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $row  = $stmt->fetch();
            if (!$row) return [];

            return [
                'email'      => self::decrypt($row['email_enc']),
                'full_name'  => self::decrypt($row['full_name_enc']),
                'phone'      => self::decrypt($row['phone_enc']),
                'student_id' => self::decrypt($row['student_id_enc']),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Delete all encrypted PII for a user (GDPR right to erasure).
     */
    public static function deletePii(int $userId): void
    {
        try {
            Database::getInstance()
                ->prepare("DELETE FROM user_encrypted_pii WHERE user_id = :uid")
                ->execute([':uid' => $userId]);
        } catch (\Throwable) {}
    }

    // ─────────────────────────────────────────────────────────────────────
    // Key management
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Rotate encryption key: re-encrypt all PII rows with new key.
     * Should be called periodically (e.g. annually) by a CLI script.
     */
    public static function rotateKeys(string $newAppKey): int
    {
        if (!self::available()) return 0;
        $db       = Database::getInstance();
        $stmt     = $db->query("SELECT user_id FROM user_encrypted_pii");
        $count    = 0;
        $oldKey   = self::getKey();

        // Temporarily override key for re-encryption
        self::$derivedKey = self::deriveKey($newAppKey);

        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $userId) {
            // Decrypt with old key
            self::$derivedKey = $oldKey;
            $pii = self::retrievePii((int)$userId);

            // Re-encrypt with new key
            self::$derivedKey = self::deriveKey($newAppKey);
            self::storePii((int)$userId, array_filter($pii));
            $count++;
        }
        return $count;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    public static function available(): bool
    {
        return extension_loaded('sodium')
            && function_exists('sodium_crypto_aead_aes256gcm_encrypt')
            && sodium_crypto_aead_aes256gcm_is_available();
    }

    private static function getKey(): string
    {
        if (self::$derivedKey !== null) return self::$derivedKey;
        $appKey = defined('APP_KEY') ? APP_KEY : (getenv('APP_KEY') ?: '');
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is not set — cannot encrypt field data');
        }
        self::$derivedKey = self::deriveKey($appKey);
        return self::$derivedKey;
    }

    private static function deriveKey(string $appKey): string
    {
        // HKDF-SHA256: extract + expand
        $prk = hash_hmac('sha256', $appKey, self::SALT, true);
        return substr(hash_hmac('sha256', "\x01", $prk, true), 0, self::KEY_LEN);
    }
}
