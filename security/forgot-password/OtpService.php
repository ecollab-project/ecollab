<?php
declare(strict_types=1);

if (!defined('APP_NAME')) require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';

/**
 * OtpService — Dedicated OTP generation, storage, verification, and delivery.
 *
 * Separated from AuthService so the forgot-password flow is independently
 * testable and can be re-used for email verification, 2FA, etc.
 */
class OtpService {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureTable();
    }

    // ═══════════════════════════════════════════════════════════════
    // GENERATE & STORE
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate a fresh OTP for $userId + $action, invalidate any existing
     * unused codes, persist a bcrypt hash, and return the plaintext code.
     *
     * @param  int    $userId
     * @param  string $action  e.g. 'reset_password', 'verify_email', '2fa'
     * @return string          Plaintext OTP — pass to deliver(), never store raw
     */
    public function generate(int $userId, string $action = 'reset_password'): string {
        // Invalidate prior unused codes for this user + action
        $this->db->prepare("
            UPDATE otp_codes
               SET used_at = NOW()
             WHERE user_id = :uid
               AND action  = :action
               AND used_at IS NULL
        ")->execute([':uid' => $userId, ':action' => $action]);

        // Generate cryptographically secure OTP
        $otp       = str_pad((string)random_int(0, (int)(10 ** OTP_LENGTH) - 1), OTP_LENGTH, '0', STR_PAD_LEFT);
        $otpHash   = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);
        $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY);

        $this->db->prepare("
            INSERT INTO otp_codes (user_id, action, code_hash, expires_at, created_at)
            VALUES (:uid, :action, :hash, :exp, NOW())
        ")->execute([
            ':uid'    => $userId,
            ':action' => $action,
            ':hash'   => $otpHash,
            ':exp'    => $expiresAt,
        ]);

        return $otp;
    }

    // ═══════════════════════════════════════════════════════════════
    // VERIFY
    // ═══════════════════════════════════════════════════════════════

    /**
     * Verify a submitted OTP against the stored hash.
     * Marks the code as used on success.
     *
     * @return array ['success' => bool, 'error'? => string]
     */
    public function verify(int $userId, string $submittedOtp, string $action = 'reset_password'): array {
        $stmt = $this->db->prepare("
            SELECT id, code_hash
              FROM otp_codes
             WHERE user_id   = :uid
               AND action    = :action
               AND expires_at > NOW()
               AND used_at  IS NULL
             ORDER BY created_at DESC
             LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':action' => $action]);
        $row = $stmt->fetch();

        if (!$row) {
            return [
                'success' => false,
                'error'   => 'Code expired or not found. Please request a new one.',
            ];
        }

        if (!password_verify(trim($submittedOtp), $row['code_hash'])) {
            return [
                'success' => false,
                'error'   => 'Incorrect code. Please try again.',
            ];
        }

        // Mark as used
        $this->db->prepare("UPDATE otp_codes SET used_at = NOW() WHERE id = :id")
            ->execute([':id' => $row['id']]);

        return ['success' => true];
    }

    // ═══════════════════════════════════════════════════════════════
    // DELIVER
    // ═══════════════════════════════════════════════════════════════

    /**
     * Send the plaintext OTP to the user's email.
     * In production: swap mail() for PHPMailer / Mailgun / SES.
     * In development: returns the OTP in the result array for inspection.
     *
     * @param  string $toEmail
     * @param  string $toName
     * @param  string $otp      Plaintext OTP from generate()
     * @param  string $action   Used to customise the email subject/body
     * @return array  ['success' => bool, 'otp_debug'? => string (APP_DEBUG only)]
     */
    public function deliver(string $toEmail, string $toName, string $otp, string $action = 'reset_password'): array {
        $subjects = [
            'reset_password' => 'Your Ecollab Password Reset Code',
            'verify_email'   => 'Verify Your Ecollab Email',
            '2fa'            => 'Your Ecollab Login Code',
        ];
        $subject = $subjects[$action] ?? 'Your Ecollab Verification Code';

        $expiryMin = (int)(OTP_EXPIRY / 60);
        $body = $this->buildEmailBody($toName, $otp, $expiryMin, $action);

        if (APP_DEBUG) {
            // Never send emails in dev — just return the code
            error_log("[OtpService] DEV OTP for {$toEmail} ({$action}): {$otp}");
            return ['success' => true, 'otp_debug' => $otp];
        }

        $headers = implode("\r\n", [
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'Reply-To: ' . MAIL_FROM,
            'Content-Type: text/html; charset=UTF-8',
            'MIME-Version: 1.0',
            'X-Mailer: Ecollab/1.0',
        ]);

        $sent = @mail($toEmail, $subject, $body, $headers);
        return ['success' => $sent];
    }

    // ═══════════════════════════════════════════════════════════════
    // CLEANUP
    // ═══════════════════════════════════════════════════════════════

    /**
     * Purge all expired or used codes older than 24 hours.
     * Call from a cron job or after successful verification.
     */
    public function purgeExpired(): int {
        $stmt = $this->db->prepare("
            DELETE FROM otp_codes
             WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                OR used_at   < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Count how many valid (unused, unexpired) codes a user has for an action.
     * Useful for rate-limiting at the application layer.
     */
    public function countValid(int $userId, string $action = 'reset_password'): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM otp_codes
             WHERE user_id   = :uid
               AND action    = :action
               AND expires_at > NOW()
               AND used_at  IS NULL
        ");
        $stmt->execute([':uid' => $userId, ':action' => $action]);
        return (int)$stmt->fetchColumn();
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function buildEmailBody(string $name, string $otp, int $expiryMin, string $action): string {
        $actionLabel = match ($action) {
            'verify_email'   => 'verify your email address',
            '2fa'            => 'complete your login',
            default          => 'reset your password',
        };

        // OTP displayed as individual digit blocks for readability
        $digitHtml = implode('', array_map(
            fn(string $d) => "<span style='display:inline-block;width:40px;height:48px;line-height:48px;text-align:center;background:#1a0a2e;border:1.5px solid rgba(255,45,117,0.5);border-radius:8px;font-size:24px;font-weight:700;color:#FF2D75;margin:0 3px;font-family:monospace'>{$d}</span>",
            str_split($otp)
        ));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#07040F;font-family:'Inter',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#07040F;padding:40px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:#0f0c1a;border:1px solid rgba(255,45,117,0.25);border-radius:20px;overflow:hidden;max-width:480px;">
        <!-- Header -->
        <tr><td style="background:linear-gradient(135deg,#FF2D75,#9F3BFF);padding:28px 32px;text-align:center;">
          <div style="font-size:28px;margin-bottom:4px;">🌿</div>
          <div style="color:#fff;font-size:20px;font-weight:700;font-family:'Poppins',Arial,sans-serif;">Ecollab</div>
        </td></tr>
        <!-- Body -->
        <tr><td style="padding:32px;">
          <p style="color:#fff;font-size:16px;font-weight:600;margin:0 0 8px;">Hi {$name},</p>
          <p style="color:#B0B0C0;font-size:14px;line-height:1.7;margin:0 0 24px;">
            You requested to {$actionLabel}. Use the code below — it expires in <strong style="color:#fff;">{$expiryMin} minutes</strong>.
          </p>
          <!-- OTP digits -->
          <div style="text-align:center;margin:0 0 28px;">{$digitHtml}</div>
          <p style="color:#6e6e82;font-size:12px;text-align:center;margin:0 0 24px;">
            If you didn't request this, you can safely ignore this email.
          </p>
          <hr style="border:none;border-top:1px solid rgba(255,255,255,0.06);margin:0 0 20px;">
          <p style="color:#6e6e82;font-size:11px;text-align:center;margin:0;">
            © <?= date('Y') ?> Ecollab · Built for Fatima Computing Students
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private function ensureTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS otp_codes (
                id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                user_id    BIGINT UNSIGNED  NOT NULL,
                action     VARCHAR(40)      NOT NULL DEFAULT 'reset_password',
                code_hash  VARCHAR(255)     NOT NULL,
                expires_at DATETIME         NOT NULL,
                used_at    DATETIME             NULL DEFAULT NULL,
                created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_action (user_id, action),
                KEY idx_expires     (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
