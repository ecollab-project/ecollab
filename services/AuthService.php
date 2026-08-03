<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/forgot-password/OtpService.php';
require_once ROOT_PATH . '/security/AuditLogger.php';
require_once ROOT_PATH . '/security/AccountLockout.php';
require_once ROOT_PATH . '/security/FieldEncryption.php';
require_once ROOT_PATH . '/security/SchemaVersion.php';

/**
 * AuthService — All authentication and account management business logic.
 * Every method returns a typed array with at minimum: ['success' => bool, 'error'? => string].
 * OTP generation/verification is delegated to OtpService.
 */
class AuthService {
    private PDO $db;
    private OtpService $otpService;

    public function __construct() {
        $this->db         = Database::getInstance();
        $this->otpService = new OtpService();
    }

    // ═══════════════════════════════════════════════════════════════
    // LOGIN
    // ═══════════════════════════════════════════════════════════════

    /**
     * Authenticate a user by email/student_id + password.
     * On success, writes session variables.
     */
    public function login(string $identifier, string $password, bool $remember = false): array {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return ['success' => false, 'error' => 'Email and password are required.'];
        }

        // ── Account lockout check ────────────────────────────────────────
        $lockout = new AccountLockout();
        $lockCheck = $lockout->check($identifier);
        if ($lockCheck['blocked']) {
            AuditLogger::log(AuditLogger::LOGIN_LOCKED,
                ['identifier' => hash('sha256', $identifier), 'reason' => $lockCheck['reason']],
                'blocked', AuditLogger::RISK_MEDIUM);
            return ['success' => false, 'error' => $lockCheck['message'],
                    'locked' => true, 'retry_after' => $lockCheck['retry_after'] ?? 0];
        }

        // Look up by email or student_id
        // NOTE: u.plan_id is from migration 017_user_plan_id.sql and may not
        // exist on databases that haven't applied it yet. SchemaVersion lets
        // this same query run correctly on old AND new schemas.
        $cols = SchemaVersion::selectColumns('users',
            required: [
                'u.id', 'u.username', 'u.email', 'u.full_name', 'u.password_hash',
                'u.role', 'u.status', 'u.email_verified', 'u.avatar_color_gradient',
            ],
            optional: [
                'plan_id' => 'u.plan_id',
            ]
        );
        $stmt = $this->db->prepare("
            SELECT $cols
            FROM users u
            WHERE (u.email = :id OR u.student_id = :id2)
              AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier, ':id2' => $identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            $result = $lockout->recordFailure($identifier);
            AuditLogger::log(AuditLogger::LOGIN_FAILURE,
                ['identifier' => hash('sha256', $identifier), 'reason' => 'user_not_found'],
                'failure', AuditLogger::RISK_LOW);
            $msg = $result['locked']
                ? $result['message']
                : 'No account found with that email or Student ID.';
            return ['success' => false, 'error' => $msg];
        }

        if (in_array($user['status'], ['banned', 'suspended', 'deactivated'], true)) {
            AuditLogger::log(AuditLogger::LOGIN_FAILURE,
                ['user_id' => $user['id'], 'reason' => 'account_' . $user['status']],
                'blocked', AuditLogger::RISK_MEDIUM);
            return ['success' => false, 'error' => 'Your account has been ' . $user['status'] . '. Please contact support.'];
        }

        if (empty($user['password_hash'])) {
            return ['success' => false, 'error' => 'This account uses SSO login (Google or Microsoft). Please use those buttons.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            $result = $lockout->recordFailure($identifier);
            AuditLogger::log(AuditLogger::LOGIN_FAILURE,
                ['user_id' => $user['id'], 'failed_count' => $result['failed_count']],
                'failure', AuditLogger::RISK_LOW);
            $msg = $result['locked']
                ? $result['message']
                : 'Incorrect password. ' . ($result['message'] ?? 'Please try again.');
            return ['success' => false, 'error' => $msg];
        }

        // ── Successful login ─────────────────────────────────────────────
        $lockout->recordSuccess($identifier);

        // Rehash if cost changed
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $this->db->prepare("UPDATE users SET password_hash=:h WHERE id=:id")
                ->execute([':h' => $newHash, ':id' => $user['id']]);
        }

        // Update status and last seen
        $this->db->prepare("UPDATE users SET status='active', is_online=1, last_seen_at=NOW() WHERE id=:id")
            ->execute([':id' => $user['id']]);

        // Write session
        $this->writeSession($user);

        // Encrypt and store PII on first/updated login
        FieldEncryption::storePii((int)$user['id'], [
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
        ]);

        // Remember me cookie
        if ($remember) {
            $this->setRememberToken((int)$user['id']);
        }

        // Write the avatar_color_gradient alias key so chat module can read it
        if (!isset($_SESSION['avatar_color_gradient'])) {
            $_SESSION['avatar_color_gradient'] = $_SESSION['avatar_gradient'] ?? '#a855f7,#ec4899';
        }

        AuditLogger::log(AuditLogger::LOGIN_SUCCESS,
            ['user_id' => $user['id'], 'role' => $user['role']],
            'success', AuditLogger::RISK_LOW);

        return ['success' => true, 'user' => $user, 'role' => $user['role']];
    }

    // ═══════════════════════════════════════════════════════════════
    // SIGNUP (3-step registration)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Register a new user.
     * $data keys: full_name, email, password, course, year_level,
     *             study_style, primary_goal, interests[] (array of tag slugs)
     */
    public function register(array $data): array {
        // ── Validate ────────────────────────────────────────────────
        $fullName = trim($data['full_name'] ?? '');
        $email    = trim(strtolower($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        $course   = trim($data['course']     ?? '');
        $year     = (int)($data['year_level'] ?? 0);
        $style    = $data['study_style']  ?? '';
        $goal     = $data['primary_goal'] ?? '';
        $terms    = !empty($data['terms_agreed']);

        if ($fullName === '')   return ['success' => false, 'error' => 'Full name is required.',       'field' => 'full_name'];
        if ($email === '')      return ['success' => false, 'error' => 'Email or Student ID required.', 'field' => 'email'];
        if (strlen($password) < 8) return ['success' => false, 'error' => 'Password must be at least 8 characters.', 'field' => 'password'];
        if (!$terms)            return ['success' => false, 'error' => 'You must agree to the Terms & Privacy Policy.'];
        if ($course === '')     return ['success' => false, 'error' => 'Please select your course.', 'field' => 'course'];
        if ($year < 1 || $year > 4) return ['success' => false, 'error' => 'Please select a valid year level.', 'field' => 'year_level'];

        // Check for duplicate email
        $dup = $this->db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $dup->execute([':email' => $email]);
        if ($dup->fetch()) {
            return ['success' => false, 'error' => 'An account with that email already exists.', 'field' => 'email'];
        }

        // ── Derive username ─────────────────────────────────────────
        $username = $this->generateUsername($fullName, $email);

        // ── Hash password ───────────────────────────────────────────
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        // ── Random avatar gradient ──────────────────────────────────
        $gradients = [
            '#FF2D75,#9F3BFF', '#FF4D4D,#FF2D75', '#3B82FF,#9F3BFF',
            '#10B981,#3B82FF', '#F59E0B,#EF4444', '#8B5CF6,#EC4899',
        ];
        $gradient = $gradients[array_rand($gradients)];

        // ── Lookup institution by domain ────────────────────────────
        $domain    = strstr($email, '@') ?: ('@' . DEFAULT_INSTITUTION_DOMAIN);
        $domain    = ltrim($domain, '@');
        $instStmt  = $this->db->prepare("SELECT id FROM institutions WHERE domain = :d LIMIT 1");
        $instStmt->execute([':d' => $domain]);
        $instId    = $instStmt->fetchColumn() ?: null;

        // ── Lookup academic program ─────────────────────────────────
        $progStmt = $this->db->prepare("SELECT id FROM academic_programs WHERE name = :name LIMIT 1");
        $progStmt->execute([':name' => $course]);
        $progId   = $progStmt->fetchColumn() ?: null;

        // ── Map study style ─────────────────────────────────────────
        $styleMap = ['Solo' => 'solo', 'Group' => 'group', 'Mixed' => 'mixed'];
        $styleMapped = $styleMap[$style] ?? null;

        // ── Map primary goal ────────────────────────────────────────
        $goalMap = [
            'Pass exams'            => 'pass_exams',
            'Build projects'        => 'build_projects',
            'Find study partners'   => 'find_study_partners',
            'Improve skills'        => 'improve_skills',
            'Network & collaborate' => 'network_collaborate',
        ];
        $goalMapped = $goalMap[$goal] ?? null;

        try {
            $this->db->beginTransaction();

            // Insert user
            $ins = $this->db->prepare("
                INSERT INTO users (institution_id, username, email, password_hash,
                                   full_name, avatar_color_gradient, role, status, created_at, updated_at)
                VALUES (:inst, :uname, :email, :hash, :name, :grad, 'student', 'active', NOW(), NOW())
            ");
            $ins->execute([
                ':inst'  => $instId,
                ':uname' => $username,
                ':email' => $email,
                ':hash'  => $hash,
                ':name'  => $fullName,
                ':grad'  => $gradient,
            ]);
            $userId = (int)$this->db->lastInsertId();

            // Insert profile
            $prof = $this->db->prepare("
                INSERT INTO user_profiles (user_id, academic_program_id, year_level, study_style, primary_goal)
                VALUES (:uid, :prog, :year, :style, :goal)
            ");
            $prof->execute([
                ':uid'   => $userId,
                ':prog'  => $progId,
                ':year'  => $year,
                ':style' => $styleMapped,
                ':goal'  => $goalMapped,
            ]);

            // ── Insert interest tags (step 4) ─────────────────────
            $allSlugs = [];

            // flat interests array from step 4
            $interests = $data['interests'] ?? [];
            if (!empty($interests) && is_array($interests)) {
                foreach ($interests as $slug) { $allSlugs[] = strtolower(trim($slug)); }
            }

            // collab_style slugs (step 3)
            $collabStyle = $data['collab_style'] ?? [];
            if (is_array($collabStyle)) {
                foreach ($collabStyle as $slug) { $allSlugs[] = strtolower(trim($slug)); }
            }

            // goals slugs (step 3)
            $goals = $data['goals'] ?? [];
            if (is_array($goals)) {
                foreach ($goals as $slug) { $allSlugs[] = strtolower(trim($slug)); }
            }

            // availability slugs (step 3)
            $availability = $data['availability'] ?? [];
            if (is_array($availability)) {
                foreach ($availability as $slug) { $allSlugs[] = strtolower(trim($slug)); }
            }

            if (!empty($allSlugs)) {
                $tagStmt = $this->db->prepare("SELECT id FROM interest_tags WHERE slug = :slug LIMIT 1");
                $uiStmt  = $this->db->prepare("INSERT IGNORE INTO user_interests (user_id, interest_tag_id) VALUES (:uid, :tid)");
                foreach (array_unique($allSlugs) as $slug) {
                    if ($slug === '') continue;
                    $tagStmt->execute([':slug' => $slug]);
                    $tagId = $tagStmt->fetchColumn();
                    if ($tagId) $uiStmt->execute([':uid' => $userId, ':tid' => $tagId]);
                }
            }

            // ── Insert hobbies (step 5) ────────────────────────────
            $hobbies = $data['hobbies'] ?? [];
            if (!empty($hobbies) && is_array($hobbies)) {
                // Check if user_hobbies table exists before inserting
                $tableCheck = $this->db->query("SHOW TABLES LIKE 'user_hobbies'");
                if ($tableCheck && $tableCheck->rowCount() > 0) {
                    $hobbyStmt = $this->db->prepare("
                        INSERT INTO user_hobbies
                            (user_id, hobby, genre, title, hours_per_month, playstyle, experience_level, created_at)
                        VALUES
                            (:uid, :hobby, :genre, :title, :hrs, :play, :exp, NOW())
                        ON DUPLICATE KEY UPDATE
                            genre=VALUES(genre), title=VALUES(title),
                            hours_per_month=VALUES(hours_per_month),
                            playstyle=VALUES(playstyle), experience_level=VALUES(experience_level)
                    ");
                    foreach ($hobbies as $h) {
                        if (empty($h['hobby'])) continue;
                        $hobbyStmt->execute([
                            ':uid'   => $userId,
                            ':hobby' => substr(trim($h['hobby']), 0, 60),
                            ':genre' => substr(trim($h['genre'] ?? ''), 0, 60),
                            ':title' => substr(trim($h['title'] ?? ''), 0, 120),
                            ':hrs'   => (int)($h['hoursPerMonth'] ?? 0),
                            ':play'  => substr(trim($h['playstyle'] ?? ''), 0, 60),
                            ':exp'   => substr(trim($h['experience'] ?? ''), 0, 60),
                        ]);
                    }
                }
            }

            // ── Seed peer-matching study preferences (pm_user_study_prefs) ─
            // Reuses $styleMapped / $goalMapped already computed above from the
            // dedicated study_style / primary_goal form fields — these are exact
            // matches for pm_user_study_prefs' enums, so no slug inference is
            // needed here. Other columns are left to the table's own defaults
            // (same defaults pm_save_profile() uses) since onboarding doesn't
            // collect session_length/time_preference/learning_mode/pace/comm_style.
            $pmStyle = in_array($styleMapped, ['solo', 'group', 'mixed'], true) ? $styleMapped : 'mixed';
            $pmGoal  = in_array($goalMapped, [
                'pass_exams', 'build_projects', 'find_study_partners',
                'improve_skills', 'network_collaborate', 'research',
            ], true) ? $goalMapped : 'improve_skills';

            $this->db->prepare("
                INSERT INTO pm_user_study_prefs (user_id, study_style, primary_goal)
                VALUES (:uid, :style, :goal)
                ON DUPLICATE KEY UPDATE
                    study_style = VALUES(study_style), primary_goal = VALUES(primary_goal)
            ")->execute([
                ':uid'   => $userId,
                ':style' => $pmStyle,
                ':goal'  => $pmGoal,
            ]);

            $this->db->commit();

            // Write session for the new user
            $newUser = [
                'id'                   => $userId,
                'username'             => $username,
                'email'                => $email,
                'full_name'            => $fullName,
                'role'                 => 'student',
                'status'               => 'active',
                'avatar_color_gradient'=> $gradient,
            ];
            $this->writeSession($newUser);

            return ['success' => true, 'user_id' => $userId, 'username' => $username, 'role' => 'student'];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('[AuthService::register] ' . $e->getMessage());
            return ['success' => false, 'error' => 'Registration failed. Please try again.'];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // FORGOT PASSWORD — initiate flow
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate a 6-digit OTP via OtpService, store it hashed, and email it.
     * Returns the OTP plaintext only in APP_DEBUG mode for testing.
     */
    public function forgotPassword(string $email): array {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }

        $stmt = $this->db->prepare("SELECT id, full_name FROM users WHERE email = :e AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch();

        // Always return success to prevent email enumeration
        if (!$user) {
            return ['success' => true, 'message' => 'If that email exists, a code has been sent.'];
        }

        // Delegate generation + delivery to OtpService
        $otp           = $this->otpService->generate((int)$user['id'], 'reset_password');
        $deliverResult = $this->otpService->deliver($email, $user['full_name'], $otp, 'reset_password');

        $result = [
            'success' => true,
            'message' => 'If that email exists, a code has been sent.',
            'user_id' => (int)$user['id'],
        ];

        // In dev mode expose OTP for inspection (never in production)
        if (APP_DEBUG && isset($deliverResult['otp_debug'])) {
            $result['otp_debug'] = $deliverResult['otp_debug'];
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════
    // VERIFY OTP
    // ═══════════════════════════════════════════════════════════════

    public function verifyOtp(int $userId, string $otp, string $action = 'reset_password'): array {
        // Delegate verification to OtpService
        $result = $this->otpService->verify($userId, $otp, $action);
        if (!$result['success']) {
            return $result;
        }

        // Issue a short-lived reset token (stored in session)
        $resetToken = bin2hex(random_bytes(32));
        $_SESSION['pwd_reset_token']   = $resetToken;
        $_SESSION['pwd_reset_user_id'] = $userId;
        $_SESSION['pwd_reset_expires'] = time() + 600;

        return ['success' => true, 'reset_token' => $resetToken];
    }

    // ═══════════════════════════════════════════════════════════════
    // RESET PASSWORD
    // ═══════════════════════════════════════════════════════════════

    public function resetPassword(string $resetToken, string $newPassword, string $confirmPassword): array {
        if (!isset($_SESSION['pwd_reset_token'])
            || !hash_equals($_SESSION['pwd_reset_token'], $resetToken)
            || ($_SESSION['pwd_reset_expires'] ?? 0) < time()
        ) {
            return ['success' => false, 'error' => 'Reset session expired. Please start again.'];
        }

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        if ($newPassword !== $confirmPassword) {
            return ['success' => false, 'error' => 'Passwords do not match.'];
        }

        $userId = (int)$_SESSION['pwd_reset_user_id'];
        $hash   = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        $this->db->prepare("UPDATE users SET password_hash=:h, updated_at=NOW() WHERE id=:id")
            ->execute([':h' => $hash, ':id' => $userId]);

        // Clear reset session vars
        unset($_SESSION['pwd_reset_token'], $_SESSION['pwd_reset_user_id'], $_SESSION['pwd_reset_expires']);

        return ['success' => true, 'message' => 'Password updated successfully.'];
    }

    // ═══════════════════════════════════════════════════════════════
    // SESSION VALIDATION
    // ═══════════════════════════════════════════════════════════════

    public function validateSession(): array {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['user_id'])) {
            return ['success' => false, 'authenticated' => false];
        }
        return [
            'success'       => true,
            'authenticated' => true,
            'user_id'       => $_SESSION['user_id'],
            'username'      => $_SESSION['username']  ?? '',
            'role'          => $_SESSION['role']       ?? 'student',
            'full_name'     => $_SESSION['full_name']  ?? '',
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // REMEMBER ME
    // ═══════════════════════════════════════════════════════════════

    public function loginFromRememberToken(): bool {
        $token = $_COOKIE['ecollab_remember'] ?? '';
        if ($token === '') return false;

        $stmt = $this->db->prepare("
            SELECT id, username, email, full_name, role, status, avatar_color_gradient
            FROM users WHERE remember_token = :t AND deleted_at IS NULL LIMIT 1
        ");
        $stmt->execute([':t' => hash('sha256', $token)]);
        $user = $stmt->fetch();

        if (!$user || in_array($user['status'], ['banned', 'suspended', 'deactivated'], true)) {
            return false;
        }

        $this->writeSession($user);
        $this->setRememberToken((int)$user['id']); // Rotate token
        return true;
    }

    // ═══════════════════════════════════════════════════════════════
    // LOGOUT
    // ═══════════════════════════════════════════════════════════════

    public function logout(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId) {
            $this->db->prepare("UPDATE users SET is_online=0, status='offline', last_seen_at=NOW() WHERE id=:id")
                ->execute([':id' => $userId]);
            // Clear remember token
            $this->db->prepare("UPDATE users SET remember_token=NULL WHERE id=:id")
                ->execute([':id' => $userId]);
            AuditLogger::log(AuditLogger::LOGOUT, ['user_id' => $userId], 'success', AuditLogger::RISK_LOW);
        }
        // Clear remember cookie
        setcookie('ecollab_remember', '', time() - 3600, '/', '', SESSION_SECURE, true);
        // Destroy the PHP session so the user is actually logged out
        AuthMiddleware::destroySession();
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function writeSession(array $user): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_regenerate_id(true);
        $_SESSION['user_id']          = (int)$user['id'];
        $_SESSION['username']         = $user['username'];
        $_SESSION['email']            = $user['email'];
        $_SESSION['full_name']        = $user['full_name'];
        $_SESSION['role']             = $user['role'];
        $_SESSION['plan_id']          = $user['plan_id'] ?? null;
        $_SESSION['avatar_gradient']  = $user['avatar_color_gradient'] ?? '#FF2D75,#9F3BFF';
        $_SESSION['logged_in_at']     = time();
        // Alias for chat module compatibility
        $_SESSION['avatar_color_gradient'] = $user['avatar_color_gradient'] ?? '#a855f7,#ec4899';
        // Sync the CSRF token key used by chat (AuthMiddleware::csrfToken())
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    private function setRememberToken(int $userId): void {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $this->db->prepare("UPDATE users SET remember_token=:t WHERE id=:id")
            ->execute([':t' => $tokenHash, ':id' => $userId]);
        setcookie('ecollab_remember', $token, time() + 30 * 86400, '/', '', SESSION_SECURE, true);
    }

    private function generateUsername(string $fullName, string $email): string {
        // Use first part of email, or slugified full name
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
        if (strlen($base) < 3) {
            $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', str_replace(' ', '', $fullName)));
        }
        $base = substr($base, 0, 20);

        $check = $this->db->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
        $username = $base;
        $i = 1;
        while (true) {
            $check->execute([':u' => $username]);
            if (!$check->fetchColumn()) break;
            $username = $base . $i++;
        }
        return $username;
    }

}
