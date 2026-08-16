<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';
require_once __DIR__ . '/AdminDashboardService.php';
require_once __DIR__ . '/MembershipService.php';
require_once __DIR__ . '/StudentDashboardService.php';
require_once __DIR__ . '/FacilitatorDashboardService.php';
require_once __DIR__ . '/AdminDashboardService.php';

/**
 * UserService
 *
 * Backward-compatible facade for dashboard data.
 *
 * Phase 4.6:
 * The original monolithic UserService has been split into
 * focused dashboard/domain services while preserving the
 * existing public API used by the application.
 */
class UserService
{
    private MembershipService $membershipService;
    private StudentDashboardService $studentDashboardService;
    private FacilitatorDashboardService $facilitatorDashboardService;
    private AdminDashboardService $adminDashboardService;
    private PDO $db;
    public function __construct()
    {
        $db = Database::getInstance();
        $this->adminDashboardService = new AdminDashboardService();
        $this->membershipService =
            new MembershipService($db);

        $this->studentDashboardService =
            new StudentDashboardService(
                $db,
                $this->membershipService
            );

        $this->facilitatorDashboardService =
            new FacilitatorDashboardService(
                $db,
                $this->membershipService
            );

        $this->adminDashboardService = new AdminDashboardService();
    }

    /**
     * Backward-compatible membership API.
     */
    public function getMembershipSummary(
        int $userId
    ): array {
        return $this->membershipService
            ->getMembershipSummary($userId);
    }

    /**
     * Backward-compatible student dashboard API.
     */
    public function getStudentDashboardData(
        int $userId
    ): array {
        return $this->studentDashboardService
            ->getStudentDashboardData($userId);
    }

    /**
     * Backward-compatible facilitator dashboard API.
     */
    public function getFacilitatorDashboardData(
        int $userId
    ): array {
        return $this->facilitatorDashboardService
            ->getFacilitatorDashboardData($userId);
    }

    /**
     * Backward-compatible admin dashboard API.
     */
    public function getAdminDashboardData(int $userId): array
    {
        return $this->adminDashboardService->getDashboardData(
            $userId,
            $this->getMembershipSummary($userId)
        );
    }
}
