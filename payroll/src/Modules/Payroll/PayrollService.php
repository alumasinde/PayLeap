<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;
use Mshahara\Paye\Calculator;
use Mshahara\Paye\Ruleset;
use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Money;
use PDO;
use PDOException;
use Throwable;

/**
 * Preparing and approving a monthly run.
 *
 * Every figure comes from the shared engine and the rate version effective in
 * that month. The run stores each line's full breakdown, so a later rate change
 * never rewrites history.
 */
final class PayrollService
{
    public function __construct(
        private readonly PDO $db,
        private readonly PayrollRepository $runs,
        private readonly EmployeeRepository $employees,
        private readonly RateVersionRepository $rates,
        private readonly AuditLogger $auditLogger,
        private readonly Calculator $calculator,
    ) {
    }

    public function prepare(int $companyId, string $period, string $label, int $actorId): int
    {
        $periodStart = $this->periodStart($period);
        $periodEnd = (new DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');

        if ($this->runs->existsForPeriod($companyId, $period)) {
            throw new HttpException(409, 'A payroll run already exists for that month.');
        }

        $rate = $this->rates->forDate($periodStart);

        if ($rate === null) {
            throw new HttpException(422, 'No statutory rates are effective for that month.');
        }

        try {
            Ruleset::assertValid($rate);
        } catch (InvalidArgumentException) {
            throw new HttpException(422, 'The statutory rates for that month are incomplete.');
        }

        $staff = $this->employees->forPayroll($companyId, $periodEnd);

        if ($staff === []) {
            throw new HttpException(422, 'No active employees with a salary are on the register.');
        }

        $label = trim($label);

        if (mb_strlen($label) > 120) {
            throw new HttpException(422, 'The run label is too long.');
        }

        if ($label === '') {
            $label = date('F Y', strtotime($periodStart)) . ' payroll';
        }

        $lines = [];
        $totals = [
            'employee_count' => 0,
            'gross_cents' => 0,
            'deductions_cents' => 0,
            'net_cents' => 0,
            'employer_nssf_cents' => 0,
            'employer_housing_cents' => 0,
            'employer_nita_cents' => 0,
            'employer_cost_cents' => 0,
        ];

        foreach ($staff as $employee) {
            $grossCents = (int) $employee['monthly_salary_cents'];
            $gross = $grossCents / 100;

            $slip = $this->calculator->payslip($gross, $rate);
            $cost = $this->calculator->employerCost($gross, $rate);

            $line = [
                'employee_id' => (int) $employee['id'],
                'employee_no' => (string) $employee['employee_no'],
                'employee_name' => trim($employee['first_name'] . ' ' . $employee['last_name']),
                'gross_cents' => $grossCents,
                'taxable_cents' => Money::fromShillings((float) $slip['taxable_pay']),
                'nssf_employee_cents' => Money::fromShillings((float) $slip['nssf']['total']),
                'shif_cents' => Money::fromShillings((float) $slip['shif']),
                'housing_levy_employee_cents' => Money::fromShillings((float) $slip['housing_levy']),
                'paye_cents' => Money::fromShillings((float) $slip['paye']),
                'deductions_cents' => Money::fromShillings((float) $slip['total_deductions']),
                'net_cents' => Money::fromShillings((float) $slip['net']),
                'nssf_employer_cents' => Money::fromShillings((float) $cost['nssf']),
                'housing_levy_employer_cents' => Money::fromShillings((float) $cost['housing_levy']),
                'nita_cents' => Money::fromShillings((float) $cost['nita']),
                'employer_cost_cents' => Money::fromShillings((float) $cost['total_cost']),
                'breakdown' => json_encode(
                    ['payslip' => $slip, 'employer' => $cost],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
            ];

            $lines[] = $line;

            $totals['employee_count']++;
            $totals['gross_cents'] += $line['gross_cents'];
            $totals['deductions_cents'] += $line['deductions_cents'];
            $totals['net_cents'] += $line['net_cents'];
            $totals['employer_nssf_cents'] += $line['nssf_employer_cents'];
            $totals['employer_housing_cents'] += $line['housing_levy_employer_cents'];
            $totals['employer_nita_cents'] += $line['nita_cents'];
            $totals['employer_cost_cents'] += $line['employer_cost_cents'];
        }

        $header = $totals + [
            'period' => $period,
            'label' => $label,
            'rate_version_id' => (string) $rate['id'],
            'prepared_by' => $actorId,
        ];

        $this->db->beginTransaction();

        try {
            $runId = $this->runs->create($companyId, $header);
            $this->runs->addLines($runId, $lines);
            $this->db->commit();
        } catch (PDOException $error) {
            $this->db->rollBack();

            if ((string) $error->getCode() === '23000') {
                throw new HttpException(409, 'A payroll run already exists for that month.');
            }

            throw $error;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->auditLogger->record($companyId, $actorId, 'payroll.run.prepared', 'payroll_run', (string) $runId, [
            'period' => $period,
            'rate_version' => $rate['id'],
            'employees' => $totals['employee_count'],
            'net_cents' => $totals['net_cents'],
        ]);

        return $runId;
    }

    public function approve(int $companyId, int $runId, int $actorId): void
    {
        $run = $this->runs->find($companyId, $runId);

        if ($run === null) {
            throw new HttpException(404, 'That payroll run does not exist.');
        }

        if ($run['status'] !== 'draft') {
            throw new HttpException(409, 'That payroll run has already been approved.');
        }

        if ((int) $run['prepared_by'] === $actorId) {
            throw new HttpException(409, 'A payroll run cannot be approved by the person who prepared it.');
        }

        if (!$this->runs->markApproved($companyId, $runId, $actorId)) {
            throw new HttpException(409, 'That payroll run has already been approved.');
        }

        $this->auditLogger->record($companyId, $actorId, 'payroll.run.approved', 'payroll_run', (string) $runId, [
            'period' => $run['period'],
            'net_cents' => (int) $run['net_cents'],
        ]);
    }

    private function periodStart(string $period): string
    {
        if (preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/', $period, $matches) !== 1) {
            throw new HttpException(422, 'Choose a payroll month.');
        }

        $year = (int) $matches[1];

        if ($year < 2000 || $year > 2100) {
            throw new HttpException(422, 'Choose a payroll month.');
        }

        return $period . '-01';
    }
}
