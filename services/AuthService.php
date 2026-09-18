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
    public function login(string $identifier, string $password, bool $remember = false, bool $requireOtp = true): array {
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

        // ── Password accepted ──────────────────────────────────────────────
        $lockout->recordSuccess($identifier);

        // Rehash if cost changed.
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $this->db->prepare("UPDATE users SET password_hash=:h WHERE id=:id")
                ->execute([':h' => $newHash, ':id' => $user['id']]);
        }

        // Do not authenticate the PHP session until the second factor succeeds.
        // The pending-login state is intentionally stored server-side.
        if ($requireOtp) {
            if (session_status() === PHP_SESSION_NONE) session_start();

            $otp = $this->otpService->generate((int)$user['id'], '2fa');
            $deliverResult = $this->otpService->deliver(
                (string)$user['email'],
                (string)$user['full_name'],
                $otp,
                '2fa'
            );

            if (!$deliverResult['success']) {
                unset(
                    $_SESSION['pending_login_user_id'],
                    $_SESSION['pending_login_remember'],
                    $_SESSION['pending_login_expires']
                );
                return [
                    'success' => false,
                    'error'   => $deliverResult['error'] ?? 'Unable to send the login verification code.'
                ];
            }

            $_SESSION['pending_login_user_id'] = (int)$user['id'];
            $_SESSION['pending_login_remember'] = $remember;
            $_SESSION['pending_login_expires'] = time() + OTP_EXPIRY;

            $result = [
                'success'      => true,
                'otp_required' => true,
                'user'         => $user,
                'role'          => $user['role'],
            ];

            if (APP_DEBUG && isset($deliverResult['otp_debug'])) {
                $result['otp_debug'] = $deliverResult['otp_debug'];
            }

            return $result;
        }

        // Legacy/internal callers can explicitly bypass the OTP gate.
        $this->completeAuthenticatedLogin($user, $remember);
        return ['success' => true, 'user' => $user, 'role' => $user['role']];
    }

    // ═══════════════════════════════════════════════════════════════
    // PRE-SIGNUP EMAIL VERIFICATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * Send an email verification OTP before the user advances past signup step 1.
     * No user row is created until the full signup form is submitted.
     */
    public function sendPreSignupEmailOtp(string $email, string $fullName): array {
        $email = trim(strtolower($email));
        $fullName = trim($fullName);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please enter a valid email address.'];
        }
        if ($fullName === '') {
            return ['success' => false, 'error' => 'Please enter your full name.'];
        }

        // Do not allow an already-registered address to start a new signup.
        $stmt = $this->db->prepare("SELECT id, email_verified FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'An account with that email already exists.'];
        }

        if (session_status() === PHP_SESSION_NONE) session_start();

        $otp = str_pad((string)random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);

        $_SESSION['pending_signup_email'] = $email;
        $_SESSION['pending_signup_email_hash'] = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);
        $_SESSION['pending_signup_email_expires'] = time() + OTP_EXPIRY;
        $_SESSION['pending_signup_email_verified'] = false;

        $deliverResult = $this->otpService->deliver($email, $fullName, $otp, 'verify_email');

        if (!$deliverResult['success']) {
            unset(
                $_SESSION['pending_signup_email_hash'],
                $_SESSION['pending_signup_email_expires'],
                $_SESSION['pending_signup_email_verified']
            );
            return [
                'success' => false,
                'error' => $deliverResult['error'] ?? 'Unable to send the verification code.'
            ];
        }

        $result = [
            'success' => true,
            'otp_required' => true,
            'mail_sent' => true,
            'email' => $email,
        ];

        if (APP_DEBUG && isset($deliverResult['otp_debug'])) {
            $result['otp_debug'] = $deliverResult['otp_debug'];
        }

        return $result;
    }

    /**
     * Verify the pre-signup email OTP and bind the verified email to this session.
     */
    public function verifyPreSignupEmailOtp(string $email, string $otp): array {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $email = trim(strtolower($email));
        $pendingEmail = (string)($_SESSION['pending_signup_email'] ?? '');
        $expires = (int)($_SESSION['pending_signup_email_expires'] ?? 0);
        $hash = (string)($_SESSION['pending_signup_email_hash'] ?? '');

        if ($pendingEmail === '' || !hash_equals($pendingEmail, $email) || $expires < time() || $hash === '') {
            return ['success' => false, 'error' => 'Email verification expired. Please request a new code.'];
        }

        if (!password_verify(trim($otp), $hash)) {
            return ['success' => false, 'error' => 'Incorrect code. Please try again.'];
        }

        $_SESSION['pending_signup_email_verified'] = true;
        unset(
            $_SESSION['pending_signup_email_hash'],
            $_SESSION['pending_signup_email_expires']
        );

        return ['success' => true, 'verified' => true, 'email' => $email];
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

        if (session_status() === PHP_SESSION_NONE) session_start();
        $verifiedEmail = strtolower(trim((string)($_SESSION['pending_signup_email'] ?? '')));
        if (empty($_SESSION['pending_signup_email_verified']) || $verifiedEmail !== $email) {
            return ['success' => false, 'error' => 'Please verify your email address before creating your account.', 'field' => 'email'];
        }

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
                                   full_name, avatar_color_gradient, role, status, email_verified, created_at, updated_at)
                VALUES (:inst, :uname, :email, :hash, :name, :grad, 'student', 'active', 0, NOW(), NOW())
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

            // Email verification is completed before an authenticated session
            // is created. The account and onboarding data are already committed,
            // so a mail delivery failure leaves a recoverable pending account.
            if (session_status() === PHP_SESSION_NONE) session_start();

            $otp = $this->otpService->generate($userId, 'verify_email');
            $_SESSION['pending_signup_user_id'] = $userId;
            $_SESSION['pending_signup_expires'] = time() + OTP_EXPIRY;

            $deliverResult = $this->otpService->deliver(
                $email,
                $fullName,
                $otp,
                'verify_email'
            );

            $result = [
                'success'             => true,
                'otp_required'        => true,
                'verification_pending'=> true,
                'user_id'             => $userId,
                'username'            => $username,
                'role'                => 'student',
                'mail_sent'           => $deliverResult['success'],
                'mail_error'          => $deliverResult['success']
                    ? null
                    : ($deliverResult['error'] ?? 'Unable to send the verification code.'),
            ];

            if (APP_DEBUG && isset($deliverResult['otp_debug'])) {
                $result['otp_debug'] = $deliverResult['otp_debug'];
            }

            return $result;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
        if (session_status() === PHP_SESSION_NONE) session_start();

        // The existing verify-otp endpoint is shared with forgot-password.
        // When a pending login exists for this session, bind this verification
        // to the login 2FA action without trusting a client-supplied action.
        $pendingUserId = (int)($_SESSION['pending_login_user_id'] ?? 0);
        $pendingExpires = (int)($_SESSION['pending_login_expires'] ?? 0);
        if ($action === 'reset_password'
            && $pendingUserId > 0
            && $pendingUserId === $userId
            && $pendingExpires >= time()
        ) {
            $action = '2fa';
        }

        // Signup email verification is also bound to the server-side pending
        // signup session. Never trust a client-supplied user ID for this flow.
        $pendingSignupUserId = (int)($_SESSION['pending_signup_user_id'] ?? 0);
        $pendingSignupExpires = (int)($_SESSION['pending_signup_expires'] ?? 0);

        if ($action === 'reset_password'
            && $pendingSignupUserId > 0
            && $pendingSignupUserId === $userId
            && $pendingSignupExpires >= time()
        ) {
            $action = 'verify_email';
        }

        if ($action === 'verify_email') {
            if ($pendingSignupUserId <= 0
                || $pendingSignupUserId !== $userId
                || $pendingSignupExpires < time()
            ) {
                unset(
                    $_SESSION['pending_signup_user_id'],
                    $_SESSION['pending_signup_expires']
                );
                return ['success' => false, 'error' => 'Email verification expired. Please register again.'];
            }
        }

        if ($action === '2fa') {
            $pendingUserId = (int)($_SESSION['pending_login_user_id'] ?? 0);
            $pendingExpires = (int)($_SESSION['pending_login_expires'] ?? 0);

            if ($pendingUserId <= 0 || $pendingUserId !== $userId || $pendingExpires < time()) {
                unset(
                    $_SESSION['pending_login_user_id'],
                    $_SESSION['pending_login_remember'],
                    $_SESSION['pending_login_expires']
                );
                return ['success' => false, 'error' => 'Login verification expired. Please sign in again.'];
            }
        }

        // Delegate verification to OtpService.
        $result = $this->otpService->verify($userId, $otp, $action);
        if (!$result['success']) {
            return $result;
        }

        if ($action === '2fa') {
            $cols = SchemaVersion::selectColumns('users',
                required: [
                    'u.id', 'u.username', 'u.email', 'u.full_name',
                    'u.role', 'u.status', 'u.avatar_color_gradient',
                ],
                optional: [
                    'plan_id' => 'u.plan_id',
                ]
            );

            $stmt = $this->db->prepare("
                SELECT $cols
                FROM users u
                WHERE u.id = :id
                  AND u.deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if (!$user || in_array($user['status'], ['banned', 'suspended', 'deactivated'], true)) {
                return ['success' => false, 'error' => 'This account is no longer available.'];
            }

            $remember = !empty($_SESSION['pending_login_remember']);

            $this->db->prepare("UPDATE users SET status='active', is_online=1, last_seen_at=NOW() WHERE id=:id")
                ->execute([':id' => $userId]);

            $this->completeAuthenticatedLogin($user, $remember);

            unset(
                $_SESSION['pending_login_user_id'],
                $_SESSION['pending_login_remember'],
                $_SESSION['pending_login_expires']
            );

            AuditLogger::log(AuditLogger::LOGIN_SUCCESS,
                ['user_id' => $userId, 'role' => $user['role'], 'mfa' => true],
                'success', AuditLogger::RISK_LOW);

            $redirect = match (true) {
                in_array($user['role'], ['admin', 'super_admin', 'moderator'], true) => BASE_URL . '/modules/admin/dashboard.php',
                $user['role'] === 'facilitator' => BASE_URL . '/modules/facilitator/dashboard.php',
                default => BASE_URL . '/modules/chat/chat.php',
            };

            return [
                'success' => true,
                'role' => $user['role'],
                'user' => $user,
                'redirect' => $redirect,
            ];
        }

        if ($action === 'verify_email') {
            $cols = SchemaVersion::selectColumns('users',
                required: [
                    'u.id', 'u.username', 'u.email', 'u.full_name',
                    'u.role', 'u.status', 'u.email_verified', 'u.avatar_color_gradient',
                ],
                optional: [
                    'plan_id' => 'u.plan_id',
                ]
            );

            $stmt = $this->db->prepare("
                SELECT $cols
                FROM users u
                WHERE u.id = :id
                  AND u.deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if (!$user || in_array($user['status'], ['banned', 'suspended', 'deactivated'], true)) {
                return ['success' => false, 'error' => 'This account is no longer available.'];
            }

            $this->db->prepare("
                UPDATE users
                   SET email_verified = 1,
                       status = 'active',
                       is_online = 1,
                       last_seen_at = NOW(),
                       updated_at = NOW()
                 WHERE id = :id
            ")->execute([':id' => $userId]);

            $this->completeAuthenticatedLogin($user, false);

            unset(
                $_SESSION['pending_signup_user_id'],
                $_SESSION['pending_signup_expires']
            );

            return [
                'success'    => true,
                'verified'   => true,
                'role'       => $user['role'],
                'user'       => $user,
                'redirect'   => BASE_URL . '/modules/onboarding/server-discovery.php',
            ];
        }

        // Forgot-password OTPs issue a short-lived reset token.
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

    private function completeAuthenticatedLogin(array $user, bool $remember): void {
        // This is the only path that creates an authenticated session after password+OTP.
        $this->writeSession($user);

        FieldEncryption::storePii((int)$user['id'], [
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
        ]);

        if ($remember) {
            $this->setRememberToken((int)$user['id']);
        }

        if (!isset($_SESSION['avatar_color_gradient'])) {
            $_SESSION['avatar_color_gradient'] = $_SESSION['avatar_gradient'] ?? '#a855f7,#ec4899';
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
