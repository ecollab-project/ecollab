<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

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

    protected function setUp(): void
    {
        $this->db = \Database::getInstance();
        $_SESSION = [];
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
        $this->createdEmails[] = $email;

        return array_merge([
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
    }

    public function testRegisterCreatesUserAndReturnsSuccess(): void
    {
        $authService = new \AuthService();
        $result = $authService->register($this->validRegistrationData());

        $this->assertTrue($result['success'], $result['error'] ?? 'expected success');
        $this->assertArrayHasKey('user_id', $result);
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
        $authService = new \AuthService();
        $data = $this->validRegistrationData();

        $first = $authService->register($data);
        $this->assertTrue($first['success'], 'precondition: first registration must succeed');

        $second = $authService->register($data); // same email again
        $this->assertFalse($second['success']);
        $this->assertSame('email', $second['field'] ?? null);
    }

    /**
     * Ties directly to Phase 2 Task 2.1: confirms study_style/primary_goal
     * are seeded into pm_user_study_prefs at registration time, using the
     * exact enum values register() already computes (no slug inference).
     */
    public function testRegisterSeedsPmUserStudyPrefs(): void
    {
        $authService = new \AuthService();
        $data   = $this->validRegistrationData(['study_style' => 'Group', 'primary_goal' => 'Build projects']);
        $result = $authService->register($data);
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
        $authService = new \AuthService();
        // Values that don't match any key in AuthService's $styleMap/$goalMap
        $data   = $this->validRegistrationData(['study_style' => 'Not A Real Option', 'primary_goal' => 'Also Not Real']);
        $result = $authService->register($data);
        $this->assertTrue($result['success'], $result['error'] ?? 'expected success');

        $stmt = $this->db->prepare("SELECT study_style, primary_goal FROM pm_user_study_prefs WHERE user_id = :id");
        $stmt->execute([':id' => $result['user_id']]);
        $row = $stmt->fetch();

        $this->assertSame('mixed', $row['study_style'], 'unrecognized style should fall back to mixed');
        $this->assertSame('improve_skills', $row['primary_goal'], 'unrecognized goal should fall back to improve_skills');
    }

    public function testLoginSucceedsWithCorrectCredentials(): void
    {
        $authService = new \AuthService();
        $data = $this->validRegistrationData();
        $reg  = $authService->register($data);
        $this->assertTrue($reg['success'], 'precondition: registration must succeed');

        $login = $authService->login($data['email'], $data['password']);

        $this->assertTrue($login['success'], $login['error'] ?? 'expected login success');
        $this->assertSame($data['email'], $login['user']['email']);
    }

    public function testLoginFailsWithIncorrectPassword(): void
    {
        $authService = new \AuthService();
        $data = $this->validRegistrationData();
        $authService->register($data);

        $login = $authService->login($data['email'], 'the-wrong-password');

        $this->assertFalse($login['success']);
    }

    public function testLoginFailsForNonexistentEmail(): void
    {
        $authService = new \AuthService();
        $login = $authService->login('nobody-' . uniqid() . '@fatima.edu.ph', 'irrelevant-password');

        $this->assertFalse($login['success']);
    }
}
