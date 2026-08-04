<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Covers RoleMiddleware::hasRole() and ::atLeast() — the two methods that
 * return a bool instead of calling exit(), so they're safely unit-testable.
 *
 * NOT covered here: requireRole() / requireMinRole(). Both call
 * http_response_code()/exit() on failure, which terminates the PHP process —
 * safely asserting their failure path needs PHPUnit process isolation
 * (@runInSeparateProcess) that could not be verified against a live PHP
 * interpreter in the environment this suite was authored in. Flagged in
 * tests/README.md as a follow-up rather than claimed as covered here.
 */
final class RoleMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // RoleMiddleware::hasRole()/atLeast() read $_SESSION['role'] directly.
        // Reset before every test so tests can't leak state into each other.
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testHasRoleMatchesSingleRoleString(): void
    {
        $_SESSION['role'] = 'facilitator';
        $this->assertTrue(\RoleMiddleware::hasRole('facilitator'));
        $this->assertFalse(\RoleMiddleware::hasRole('student'));
    }

    public function testHasRoleMatchesAnyInArray(): void
    {
        $_SESSION['role'] = 'admin';
        $this->assertTrue(\RoleMiddleware::hasRole(['moderator', 'admin']));
        $this->assertFalse(\RoleMiddleware::hasRole(['student', 'facilitator']));
    }

    public function testHasRoleWithNoSessionReturnsFalse(): void
    {
        // No 'role' key set at all — simulates a logged-out visitor.
        $this->assertFalse(\RoleMiddleware::hasRole('student'));
    }

    /**
     * @dataProvider hierarchyProvider
     */
    public function testAtLeastRespectsHierarchyOrder(string $userRole, string $minRole, bool $expected): void
    {
        $_SESSION['role'] = $userRole;
        $this->assertSame($expected, \RoleMiddleware::atLeast($minRole));
    }

    public static function hierarchyProvider(): array
    {
        // Hierarchy under test (from RoleMiddleware::HIERARCHY):
        // student(1) < facilitator(2) < moderator(3) < admin(4) < super_admin(5)
        return [
            'student meets student minimum'            => ['student', 'student', true],
            'student fails facilitator minimum'         => ['student', 'facilitator', false],
            'admin exceeds student minimum'             => ['admin', 'student', true],
            'admin exactly meets admin minimum'         => ['admin', 'admin', true],
            'admin fails super_admin minimum'           => ['admin', 'super_admin', false],
            'super_admin meets every minimum'           => ['super_admin', 'moderator', true],
            'moderator exceeds facilitator minimum'     => ['moderator', 'facilitator', true],
            'facilitator fails moderator minimum'       => ['facilitator', 'moderator', false],
        ];
    }

    public function testAtLeastWithUnknownRoleTreatsAsLevelZero(): void
    {
        // A role string that isn't in the hierarchy at all should never pass
        // any minimum — HIERARCHY[...] ?? 0 makes it level 0.
        $_SESSION['role'] = 'not_a_real_role';
        $this->assertFalse(\RoleMiddleware::atLeast('student'));
    }

    public function testAtLeastWithUnknownMinRoleAlwaysFails(): void
    {
        // An invalid $minRole falls back to level 99 (HIERARCHY[...] ?? 99),
        // which nothing can ever meet or exceed — a safe-by-default failure
        // mode if a typo'd role name is ever passed to atLeast().
        $_SESSION['role'] = 'super_admin';
        $this->assertFalse(\RoleMiddleware::atLeast('not_a_real_role'));
    }
}
