<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Companies;

use Mshahara\Payroll\Authz\RoleRepository;
use Mshahara\Payroll\Modules\Attendance\HolidayRepository;
use Mshahara\Payroll\Modules\Attendance\ShiftRepository;
use Mshahara\Payroll\Modules\Leave\LeaveRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;
use PDO;
use Throwable;

/**
 * Creating a company also creates the roles and policy it will start from, so
 * there is never a window where a company exists with no way to grant access.
 */
final class CompanyService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CompanyRepository $companyRepository,
        private readonly RoleRepository $roleRepository,
        private readonly LeaveRepository $leaveRepository,
        private readonly ShiftRepository $shiftRepository,
        private readonly HolidayRepository $holidayRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(string $name, string $slug): int
    {
        $this->db->beginTransaction();

        try {
            $companyId = $this->companyRepository->create($name, $slug);
            $this->roleRepository->provision($companyId);
            $this->leaveRepository->provisionDefaults($companyId);
            $this->shiftRepository->provisionDefaults($companyId);
            $this->holidayRepository->provisionDefaults($companyId);
            $this->db->commit();

            return $companyId;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function rename(int $companyId, string $name, int $actorId): void
    {
        $name = trim($name);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new HttpException(422, 'Give the company a name of 2 to 120 characters.');
        }

        $this->companyRepository->rename($companyId, $name);

        $this->auditLogger->record($companyId, $actorId, 'company.renamed', 'company', (string) $companyId, [
            'name' => $name,
        ]);
    }
}
