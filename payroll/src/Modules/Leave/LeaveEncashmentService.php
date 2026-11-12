<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Support\HttpException;
use PDO;

/** Exit encashment for untaken annual leave. */
final class LeaveEncashmentService
{
    public function __construct(
        private readonly PDO $db,
        private readonly LeaveRepository $requests,
        private readonly LeaveLedgerRepository $ledger,
        private readonly LeaveSettingsRepository $settings,
        private readonly EmployeeRepository $employees,
    ) {
    }

    /** @return array<string,mixed> */
    public function quote(int $companyId, int $employeeId, string $asOf): array
    {
        $employee = $this->employees->find($companyId, $employeeId);

        if ($employee === null) {
            throw new HttpException(404, 'That employee does not exist.');
        }

        $type = $this->annualType($companyId);

        if ($type === null) {
            throw new HttpException(422, 'No annual leave type is configured.');
        }

        $days = max(0.0, $this->ledger->balance($companyId, $employeeId, (int) $type['id'], $asOf));
        $salary = max(0, (int) ($employee['monthly_salary_cents'] ?? 0));
        $divisor = max(1, (int) $this->settings->forCompany($companyId)['daily_rate_divisor']);
        $dailyRate = (int) round($salary / $divisor);
        $amount = (int) round($dailyRate * $days);

        return [
            'employee' => $employee,
            'type' => $type,
            'days' => round($days, 2),
            'daily_rate_cents' => $dailyRate,
            'amount_cents' => $amount,
        ];
    }

    public function create(int $companyId, int $employeeId, string $asOf, int $actorId): int
    {
        $quote = $this->quote($companyId, $employeeId, $asOf);

        if ((float) $quote['days'] <= 0.0001) {
            throw new HttpException(422, 'There are no untaken annual leave days to encash.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO leave_encashments
                (company_id, employee_id, leave_type_id, days, daily_rate_cents, amount_cents,
                 encashed_on, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
        );
        $statement->execute([
            $companyId,
            $employeeId,
            (int) $quote['type']['id'],
            $quote['days'],
            $quote['daily_rate_cents'],
            $quote['amount_cents'],
            $asOf,
            'pending',
            $actorId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function forCompany(int $companyId, string $status = 'all'): array
    {
        $sql = 'SELECT x.*, e.first_name, e.last_name, e.employee_no, lt.name AS leave_type
                FROM leave_encashments x
                JOIN employees e ON e.id = x.employee_id AND e.company_id = x.company_id
                JOIN leave_types lt ON lt.id = x.leave_type_id AND lt.company_id = x.company_id
                WHERE x.company_id = ?';
        $params = [$companyId];

        if (in_array($status, ['pending', 'paid'], true)) {
            $sql .= ' AND x.status = ?';
            $params[] = $status;
        }

        $statement = $this->db->prepare($sql . ' ORDER BY x.encashed_on DESC, x.id DESC LIMIT 200');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    private function annualType(int $companyId): ?array
    {
        foreach ($this->requests->types($companyId) as $type) {
            if ((string) ($type['system_code'] ?? '') === 'annual') {
                return $type;
            }
        }

        return null;
    }
}
