<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use UserService;

final class UserServiceFacadeTest extends TestCase
{
    private UserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new \UserService();
    }

    public function testMembershipSummaryPreservesExistingApi(): void
    {
        $result = $this->service->getMembershipSummary(6);

        $this->assertIsArray($result);

        $this->assertArrayHasKey(
            'my_servers',
            $result
        );

        $this->assertArrayHasKey(
            'my_channels',
            $result
        );

        $this->assertArrayHasKey(
            'owned_servers',
            $result
        );

        $this->assertArrayHasKey(
            'owned_channels',
            $result
        );
    }

    public function testStudentDashboardPreservesExistingApi(): void
    {
        $result =
            $this->service->getStudentDashboardData(6);

        $this->assertIsArray($result);

        $this->assertArrayHasKey(
            'courses',
            $result
        );

        $this->assertArrayHasKey(
            'notifications',
            $result
        );

        $this->assertArrayHasKey(
            'membership',
            $result
        );
    }

    public function testFacilitatorDashboardPreservesExistingApi(): void
    {
        $result =
            $this->service
            ->getFacilitatorDashboardData(6);

        $this->assertIsArray($result);

        $this->assertArrayHasKey(
            'channel',
            $result
        );

        $this->assertArrayHasKey(
            'stats',
            $result
        );

        $this->assertArrayHasKey(
            'membership',
            $result
        );
    }

    public function testAdminDashboardPreservesExistingApi(): void
    {
        $result =
            $this->service
            ->getAdminDashboardData(6);

        $this->assertIsArray($result);

        $this->assertArrayHasKey(
            'stats',
            $result
        );

        $this->assertArrayHasKey(
            'recent_users',
            $result
        );

        $this->assertArrayHasKey(
            'servers',
            $result
        );

        $this->assertArrayHasKey(
            'membership',
            $result
        );
    }
}
