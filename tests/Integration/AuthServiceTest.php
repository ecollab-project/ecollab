<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/LocalOtpTransport.php';

/**
 * Integration tests for AuthService::register() / login().
 *
 * Requires a real 'ecollab_test' database with migrations applied — see
 * tests/README.md. Each test creates and cleans up its own user rows by
 * a unique, test-run-specific email so tests can run in any order without
 * colliding with each other or with real data.
 */
final class AuthServiceTest extends TestCase
{
    private \PDO $db;
    private array $createdEmails = [];
    private \AuthService $auth;
    private \Tests\Support\LocalOtpTransport $transport;

    protected function setUp(): void
    {
        $this->db = \Database::getInstance();
        $_SESSION = [];
        $this->auth = new \AuthService();
        $this->transport = new \Tests\Support\LocalOtpTransport();
        // Test-only seam; no production service/transport changes or debug-mode bypass.
        (new \ReflectionProperty(\AuthService::class, 'otpService'))->setValue($this->auth, $this->transport);
    }

    protected function tearDown(): void
    {
        // Clean up every user this test created, cascading through the
        // tables AuthService::register() writes to.
        foreach ($this->createdEmails as $email) {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :e");
            $stmt->execute([':e' => $email]);
            $id = $stmt->fetchColumn();
            if ($id) {
                // OtpService creates this table lazily without a cascading user FK.
                $this->db->prepare("DELETE FROM otp_codes WHERE user_id = :id")->execute([':id' => $id]);
                $this->db->prepare("DELETE FROM pm_user_study_prefs WHERE user_id = :id")->execute([':id' => $id]);
                $this->db->prepare("DELETE FROM user_interests WHERE user_id = :id")->execute([':id' => $id]);
                $this->db->prepare("DELETE FROM user_hobbies WHERE user_id = :id")->execute([':id' => $id]);
                $this->db->prepare("DELETE FROM user_profiles WHERE user_id = :id")->execute([':id' => $id]);
                $this->db->prepare("DELETE FROM user_encrypted_pii WHERE user_id = :id")->execute([':id' => $id]);
                $this->db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
            }
        }
        $this->createdEmails = [];
        $_SESSION = [];
    }

    private function validRegistrationData(array $overrides = []): array
    {
        $unique = uniqid('phpunit_', true);
        $email  = $unique . '@fatima.edu.ph';
        $data = array_merge([
            'full_name'    => 'Test Student ' . $unique,
            'email'        => $email,
            'password'     => 'a-valid-password-8',
            'course'       => 'BS Computer Science',
            'year_level'   => 2,
            'study_style'  => 'Solo',
            'primary_goal' => 'Pass exams',
            'terms_agreed' => true,
            'interests'    => [],
            'collab_style' => [],
            'goals'        => [],
            'availability' => [],
            'hobbies'      => [],
        ], $overrides);
        $this->createdEmails[] = strtolower(trim($data['email']));
        return $data;
    }

    private function verifySignup(array $data): void
    {
        $sent = $this->auth->sendPreSignupEmailOtp($data['email'], $data['full_name']);
        $this->assertTrue($sent['success'], $sent['error'] ?? 'expected local delivery');
        $this->assertFalse($_SESSION['pending_signup_email_verified']);
        $verified = $this->auth->verifyPreSignupEmailOtp(
            $data['email'], $this->transport->codeFor($data['email'], 'verify_email')
        );
        $this->assertSame(['success' => true, 'verified' => true, 'email' => strtolower(trim($data['email']))], $verified);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testRegisterCreatesUserAndReturnsSuccess(): void
    {
        $data = $this->validRegistrationData();
        $this->verifySignup($data);
        $result = $this->auth->register($data);

        $this->assertTrue($result['success'], $result['error'] ?? 'expected success');
        $this->assertArrayHasKey('user_id', $result);
        $this->assertIsInt($result['user_id']);
        $this->assertTrue($result['verified']);
        $this->assertFalse($result['otp_required']);
        $this->assertSame($result['user_id'], $_SESSION['user_id']);
        $this->assertSame('student', $_SESSION['role']);
        $row = $this->db->query('SELECT email_verified, role FROM users WHERE id=' . $result['user_id'])->fetch();
        $this->assertSame(1, (int)$row['email_verified']);
        $this->assertSame('student', $row['role']);
        $this->assertArrayNotHasKey('pending_signup_email_verified', $_SESSION);
        $this->assertFalse($this->auth->verifyPreSignupEmailOtp(
            $data['email'], $this->transport->codeFor($data['email'], 'verify_email')
        )['success']);
        $this->assertArrayNotHasKey('pending_signup_email', $_SESSION);
    }

    public function testRegisterRejectsPasswordUnderEightCharacters(): void
    {
        $authService = new \AuthService();
        $result = $authService->register($this->validRegistrationData(['password' => 'short']));

        $this->assertFalse($result['success']);
        $this->assertSame('password', $result['field'] ?? null);
    }

    public function testRegisterRejectsMissingTermsAgreement(): void
    {
        $authService = new \AuthService();
        $result = $authService->register($this->validRegistrationData(['terms_agreed' => false]));

        $this->assertFalse($result['success']);
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $data = $this->validRegistrationData();
        $this->verifySignup($data);
        // A second session could have verified the same address before the first commit.
        $verifiedSession = $_SESSION;
        $first = $this->auth->register($data);
        $this->assertTrue($first['success'], 'precondition: first registration must succeed');

        $_SESSION = $verifiedSession;
        $second = $this->auth->register($data);
        $this->assertFalse($second['success']);
        $this->assertSame('email', $second['field'] ?? null);
        $this->assertSame('An account with that email already exists.', $second['error']);
    }

    /**
     * Ties directly to Phase 2 Task 2.1: confirms study_style/primary_goal
     * are seeded into pm_user_study_prefs at registration time, using the
     * exact enum values register() already computes (no slug inference).
     */
    public function testRegisterSeedsPmUserStudyPrefs(): void
    {
        $data   = $this->validRegistrationData(['study_style' => 'Group', 'primary_goal' => 'Build projects']);
        $this->verifySignup($data);
        $result = $this->auth->register($data);
        $this->assertTrue($result['success'], $result['error'] ?? 'expected success');

        $userId = $result['user_id'];
        $stmt = $this->db->prepare("SELECT study_style, primary_goal FROM pm_user_study_prefs WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'pm_user_study_prefs row should exist after registration');
        $this->assertSame('group', $row['study_style']);
        $this->assertSame('build_projects', $row['primary_goal']);
    }

    public function testRegisterFallsBackToDefaultsWhenStyleGoalUnrecognized(): void
    {
        // Values that don't match any key in AuthService's $styleMap/$goalMap
        $data   = $this->validRegistrationData(['study_style' => 'Not A Real Option', 'primary_goal' => 'Also Not Real']);
        $this->verifySignup($data);
        $result = $this->auth->register($data);
        $this->assertTrue($result['success'], $result['error'] ?? 'expected success');

        $stmt = $this->db->prepare("SELECT study_style, primary_goal FROM pm_user_study_prefs WHERE user_id = :id");
        $stmt->execute([':id' => $result['user_id']]);
        $row = $stmt->fetch();

        $this->assertSame('mixed', $row['study_style'], 'unrecognized style should fall back to mixed');
        $this->assertSame('improve_skills', $row['primary_goal'], 'unrecognized goal should fall back to improve_skills');
    }

    public function testLoginSucceedsWithCorrectCredentials(): void
    {
        $data = $this->validRegistrationData();
        $this->verifySignup($data);
        $reg = $this->auth->register($data);
        $this->assertTrue($reg['success'], 'precondition: registration must succeed');

        $_SESSION = [];
        // Match API/auth/login.php's explicit current policy, not the optional service default.
        $login = $this->auth->login($data['email'], $data['password'], false, false);

        $this->assertTrue($login['success'], $login['error'] ?? 'expected login success');
        $this->assertSame($data['email'], $login['user']['email']);
        $this->assertSame($reg['user_id'], $_SESSION['user_id']);
    }

    public function testLoginFailsWithIncorrectPassword(): void
    {
        $data = $this->validRegistrationData();
        $this->verifySignup($data);
        $reg = $this->auth->register($data);
        $this->assertTrue($reg['success']);
        $_SESSION = [];
        $login = $this->auth->login($data['email'], 'the-wrong-password', false, false);

        $this->assertFalse($login['success']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testLoginFailsForNonexistentEmail(): void
    {
        $authService = new \AuthService();
        $login = $authService->login('nobody-' . uniqid() . '@fatima.edu.ph', 'irrelevant-password');

        $this->assertFalse($login['success']);
    }

    private function assertRegistrationBlocked(array $data): void
    {
        $result = $this->auth->register($data);
        $this->assertFalse($result['success']);
        $this->assertSame('email', $result['field'] ?? null);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE email=?');
        $stmt->execute([$data['email']]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    public function testRegisterRejectsMissingVerification(): void
    {
        $this->assertRegistrationBlocked($this->validRegistrationData());
    }

    public function testSendingCodeDoesNotAuthorizeRegistration(): void
    {
        $data = $this->validRegistrationData();
        $sent = $this->auth->sendPreSignupEmailOtp($data['email'], $data['full_name']);
        $this->assertTrue($sent['success']);
        $this->assertRegistrationBlocked($data);
    }

    public function testWrongCodeDoesNotAuthorizeRegistration(): void
    {
        $data = $this->validRegistrationData();
        $this->auth->sendPreSignupEmailOtp($data['email'], $data['full_name']);
        $code = $this->transport->codeFor($data['email'], 'verify_email');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->assertFalse($this->auth->verifyPreSignupEmailOtp($data['email'], $wrong)['success']);
        $this->assertRegistrationBlocked($data);
    }

    public function testExpiredCodeDoesNotAuthorizeRegistration(): void
    {
        $data = $this->validRegistrationData();
        $this->auth->sendPreSignupEmailOtp($data['email'], $data['full_name']);
        $_SESSION['pending_signup_email_expires'] = time() - 60;
        $this->assertFalse($this->auth->verifyPreSignupEmailOtp(
            $data['email'], $this->transport->codeFor($data['email'], 'verify_email')
        )['success']);
        $this->assertRegistrationBlocked($data);
    }

    public function testCodeIsBoundToItsEmail(): void
    {
        $data = $this->validRegistrationData();
        $other = $this->validRegistrationData();
        $this->auth->sendPreSignupEmailOtp($data['email'], $data['full_name']);
        $this->assertFalse($this->auth->verifyPreSignupEmailOtp(
            $other['email'], $this->transport->codeFor($data['email'], 'verify_email')
        )['success']);
        $this->assertRegistrationBlocked($other);
    }

    public function testVerifiedSessionCannotRegisterAnotherEmail(): void
    {
        $data = $this->validRegistrationData();
        $this->verifySignup($data);
        $this->assertRegistrationBlocked($this->validRegistrationData());
    }

    public function testVerificationDoesNotTransferToAnotherSession(): void
    {
        $data = $this->validRegistrationData();
        $this->verifySignup($data);
        $_SESSION = [];
        $this->assertRegistrationBlocked($data);
    }

    public function testConsumedSignupCodeCannotBeVerifiedAgain(): void
    {
        $data = $this->validRegistrationData();
        $this->verifySignup($data);
        $this->assertFalse($this->auth->verifyPreSignupEmailOtp(
            $data['email'], $this->transport->codeFor($data['email'], 'verify_email')
        )['success']);
        $this->assertTrue($this->auth->register($data)['success']);
        $this->assertArrayNotHasKey('pending_signup_email_verified', $_SESSION);
        $_SESSION = [];
        $this->assertRegistrationBlocked($this->validRegistrationData());
    }

    public function testOptionalLoginOtpDoesNotAuthenticateUntilVerified(): void
    {
        $data = $this->validRegistrationData();
        $this->verifySignup($data);
        $reg = $this->auth->register($data);
        $this->assertTrue($reg['success']);
        $_SESSION = [];
        $login = $this->auth->login($data['email'], $data['password']);
        $this->assertTrue($login['success'], $login['error'] ?? 'login failed');
        $this->assertTrue($login['otp_required']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $code = $this->transport->codeFor($data['email'], '2fa');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->assertFalse($this->auth->verifyOtp($reg['user_id'], $wrong, '2fa')['success']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $verified = $this->auth->verifyOtp($reg['user_id'], $code, '2fa');
        $clock = $this->db->query("SELECT NOW() AS db_now, UNIX_TIMESTAMP() AS db_epoch, @@session.time_zone AS db_zone, MAX(expires_at) AS last_expiry FROM otp_codes")->fetch();
        $this->assertTrue($verified['success'], ($verified['error'] ?? 'OTP verification failed') . json_encode(['php_epoch'=>time(),'php_zone'=>date_default_timezone_get(),'database'=>$clock]));
        $this->assertSame($reg['user_id'], $_SESSION['user_id']);
        $this->assertArrayNotHasKey('pending_login_user_id', $_SESSION);
        $_SESSION = [];
        $this->assertFalse($this->auth->verifyOtp($reg['user_id'], $code, '2fa')['success']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testExpiredLoginIntentRejectsCorrectOtp(): void
    {
        $data = $this->validRegistrationData();
        $this->verifySignup($data);
        $reg = $this->auth->register($data);
        $this->assertTrue($reg['success']);
        $_SESSION = [];
        $this->assertTrue($this->auth->login($data['email'], $data['password'])['success']);
        $_SESSION['pending_login_expires'] = time() - 60;
        $this->assertFalse($this->auth->verifyOtp(
            $reg['user_id'], $this->transport->codeFor($data['email'], '2fa'), '2fa'
        )['success']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertArrayNotHasKey('pending_login_user_id', $_SESSION);
    }

    public function testRegistrationRollsBackOnPersistenceFailure(): void
    {
        // Invalid nested tags cause an exception after user/profile INSERTs.
        // Exercise the existing service rollback without DDL or production hooks.
        $data = $this->validRegistrationData(['interests' => [['invalid' => 'nested tag']]]);
        $this->verifySignup($data);
        $this->assertFalse($this->auth->register($data)['success']);
        $this->assertFalse($this->db->inTransaction());
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE email=?');
        $stmt->execute([$data['email']]);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }
}
