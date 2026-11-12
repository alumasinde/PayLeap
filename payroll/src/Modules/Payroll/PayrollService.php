<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Payroll;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Mshahara\Paye\Calculator;
use Mshahara\Paye\Ruleset;
use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Modules\Finance\ClaimRepository;
use Mshahara\Payroll\Modules\Finance\LoanRepository;
use Mshahara\Payroll\Modules\Finance\PayItemRepository;
use Mshahara\Payroll\Modules\Leave\LeavePayrollService;
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
 * never rewrites history. A correction run is a separate, linked run containing
 * only the difference between the recalculated payroll and its parent.
 */
final class PayrollService
{
    /** @var list<string> */
    private const LINE_FIELDS = [
        'gross_cents', 'taxable_cents', 'paye_cents', 'nssf_employee_cents',
        'nssf_employer_cents', 'shif_cents', 'housing_levy_employee_cents',
        'housing_levy_employer_cents', 'nita_cents', 'deductions_cents', 'net_cents',
        'employer_cost_cents', 'reimbursements_cents', 'loan_deduction_cents',
        'advance_deduction_cents', 'other_deduction_cents', 'commission_cents',
        'service_charge_cents', 'final_dues_cents', 'leave_deduction_cents',
        'holiday_premium_cents',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly PayrollRepository $runs,
        private readonly EmployeeRepository $employees,
        private readonly RateVersionRepository $rates,
        private readonly AuditLogger $auditLogger,
        private readonly Calculator $calculator,
        private readonly LeavePayrollService $leavePayroll,
        private readonly PayItemRepository $payItems,
        private readonly LoanRepository $loans,
        private readonly ClaimRepository $claims,
    ) {
    }

    public function prepare(int $companyId, string $period, string $label, int $actorId): int
    {
        $periodStart = $this->periodStart($period);
        $periodEnd = $this->periodEnd($periodStart);

        if ($this->runs->existsForPeriod($companyId, $period)) {
            throw new HttpException(409, 'A regular payroll run already exists for that month.');
        }

        $rate = $this->rate($periodStart);
        $computed = $this->compute($companyId, $period, $periodStart, $periodEnd, $rate, false);

        if ($computed['lines'] === []) {
            throw new HttpException(422, 'No active employees with a salary are on the register.');
        }

        $label = $this->label($label, $periodStart);

        $header = $computed['totals'] + [
            'period' => $period,
            'label' => $label,
            'run_type' => 'regular',
            'run_no' => 1,
            'parent_run_id' => null,
            'rate_version_id' => (string) $rate['id'],
            'prepared_by' => $actorId,
        ];

        $runId = $this->persist($companyId, $header, $computed);

        $this->auditLogger->record($companyId, $actorId, 'payroll.run.prepared', 'payroll_run', (string) $runId, [
            'period' => $period,
            'rate_version' => $rate['id'],
            'employees' => $computed['totals']['employee_count'],
            'net_cents' => $computed['totals']['net_cents'],
        ]);

        return $runId;
    }

    /**
     * Create a correction run for an approved run. The parent remains untouched;
     * this run carries the difference between a fresh calculation and the parent.
     */
    public function prepareAdjustment(int $companyId, int $parentRunId, string $reason, int $actorId): int
    {
        $parent = $this->runs->find($companyId, $parentRunId);

        if ($parent === null) {
            throw new HttpException(404, 'That payroll run does not exist.');
        }

        if ($parent['status'] !== 'approved') {
            throw new HttpException(409, 'Approve the original run before correcting it.');
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new HttpException(422, 'Give the correction a reason of 1 to 255 characters.');
        }

        $period = (string) $parent['period'];
        $periodStart = $this->periodStart($period);
        $periodEnd = $this->periodEnd($periodStart);
        $rate = $this->rate($periodStart);
        $computed = $this->compute($companyId, $period, $periodStart, $periodEnd, $rate, true, $parentRunId);

        $parentLines = [];

        foreach ($this->runs->lines($parentRunId) as $line) {
            $parentLines[(int) $line['employee_id']] = $line;
        }

        $lines = $this->difference($computed['lines'], $parentLines, $reason, $parentRunId);

        if ($lines === []) {
            throw new HttpException(409, 'Nothing has changed since that run, so there is no correction to post.');
        }

        $totals = $this->totalsFor($lines);
        $header = $totals + [
            'period' => $period,
            'label' => 'Adjustment: ' . mb_substr($reason, 0, 100),
            'run_type' => 'adjustment',
            'run_no' => $this->runs->nextRunNo($companyId, $period),
            'parent_run_id' => $parentRunId,
            'adjustment_reason' => $reason,
            'rate_version_id' => (string) $rate['id'],
            'prepared_by' => $actorId,
        ];

        $runId = $this->persist($companyId, $header, [
            'lines' => $lines,
            'items' => [],
            'recoveries' => [],
            'source' => [],
            'consume' => false,
            'period_end' => $periodEnd,
        ]);

        $this->auditLogger->record($companyId, $actorId, 'payroll.adjustment.prepared', 'payroll_run', (string) $runId, [
            'parent_run_id' => $parentRunId,
            'period' => $period,
            'reason' => $reason,
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

    /**
     * Calculate every line. One-off sources are consumed only for a regular run;
     * a correction recalculates them so the parent can be subtracted cleanly.
     *
     * @return array<string,mixed>
     */
    private function compute(
        int $companyId,
        string $period,
        string $periodStart,
        string $periodEnd,
        array $rate,
        bool $includeConsumed,
        ?int $parentRunId = null,
    ): array {
        $staff = $this->employees->forPayroll($companyId, $periodStart, $periodEnd);

        $commissions = $this->sumByEmployee($this->claims->commissionsForPeriod($companyId, $period, $includeConsumed), 'amount_cents');
        $service = $this->sumByEmployee($this->claims->serviceForPeriod($companyId, $period, $includeConsumed), 'amount_cents');
        $expenses = $this->sumByEmployee($this->claims->expensesForPeriod($companyId, $period, $includeConsumed), 'amount_cents');
        $damageRows = [];

        foreach ($this->claims->damageForPeriod($companyId, $includeConsumed) as $row) {
            $damageRows[(int) $row['employee_id']][] = $row;
        }

        $finalDues = $this->sumByEmployee($this->claims->finalDuesForPeriod($companyId, $period, $includeConsumed), 'total_cents');

        $loanRows = [];

        foreach ($this->loans->dueForPeriod($companyId, $period, $includeConsumed) as $row) {
            $loanRows[(int) $row['employee_id']][] = $row;
        }

        $lines = [];
        $items = [];
        $recoveries = [];
        $damageRecoveries = [];
        $source = [
            'commissions' => [],
            'service' => [],
            'expenses' => [],
            'damage' => [],
            'final_dues' => [],
        ];

        foreach ($staff as $employee) {
            $employeeId = (int) $employee['id'];
            $payRates = $this->employees->payRates($companyId, (int) $employee['employment_id'], $periodEnd);
            $proration = $this->proration($employee, $periodStart, $periodEnd, $payRates);

            if ($proration['days'] <= 0) {
                continue;
            }

            $base = $proration['base_cents'];
            $leave = $this->leavePayroll->adjust(
                $companyId,
                $employeeId,
                $periodStart,
                $periodEnd,
                $base,
                $includeConsumed,
            );

            $gross = $base + (int) $leave['holiday_premium_cents'] + (int) $leave['encashment_cents'] - (int) $leave['leave_deduction_cents'];
            $commission = (int) ($commissions[$employeeId] ?? 0);
            $serviceShare = (int) ($service[$employeeId] ?? 0);
            $final = (int) ($finalDues[$employeeId] ?? 0);
            $reimbursement = (int) ($expenses[$employeeId] ?? 0);
            $gross += $commission + $serviceShare + $final;

            $options = ['benefits_in_kind' => 0.0, 'pension' => 0.0, 'mortgage_interest' => 0.0, 'insurance_premium' => 0.0, 'helb' => 0.0, 'sacco' => 0.0];
            $lineItems = [];
            $postTaxItems = [];
            $mappedDeductions = [];

            if ($base > 0) {
                $lineItems[] = $this->item($companyId, null, 'Basic salary', 'earning', $base);
            }

            foreach ($this->payItems->forEmployee($companyId, $employeeId, $periodStart, $periodEnd) as $assignment) {
                $requested = $this->itemAmount($assignment, $base, $proration['factor']);

                if ($requested <= 0) {
                    continue;
                }

                $code = strtolower((string) ($assignment['code'] ?? ''));
                $kind = (string) $assignment['kind'];

                if ($kind === 'earning') {
                    if ((int) $assignment['cash'] === 1 && (int) $assignment['taxable'] === 1) {
                        $gross += $requested;
                        $lineItems[] = $this->item($companyId, (int) $assignment['pay_item_id'], (string) $assignment['name'], 'earning', $requested);
                    } elseif ((int) $assignment['cash'] === 1) {
                        $reimbursement += $requested;
                        $lineItems[] = $this->item($companyId, (int) $assignment['pay_item_id'], (string) $assignment['name'], 'reimbursement', $requested);
                    } else {
                        $options['benefits_in_kind'] += $requested / 100;
                        $lineItems[] = $this->item($companyId, (int) $assignment['pay_item_id'], (string) $assignment['name'], 'benefit', $requested);
                    }

                    continue;
                }

                $mapped = match ($code) {
                    'pension' => 'pension',
                    'insurance' => 'insurance_premium',
                    'helb' => 'helb',
                    default => null,
                };

                if ($mapped !== null) {
                    $options[$mapped] += $requested / 100;
                    $mappedDeductions[$mapped] = [
                        'pay_item_id' => (int) $assignment['pay_item_id'],
                        'label' => (string) $assignment['name'],
                        'requested_cents' => $requested,
                    ];
                } else {
                    $postTaxItems[] = [
                        'pay_item_id' => (int) $assignment['pay_item_id'],
                        'label' => (string) $assignment['name'],
                        'amount_cents' => $requested,
                    ];
                }
            }

            $slip = $this->calculator->payslip($gross / 100, $rate, $options);
            $cost = $this->calculator->employerCost($gross / 100, $rate, $options);

            $engineDeductions = Money::fromShillings((float) $slip['total_deductions']);
            $net = Money::fromShillings((float) $slip['net']);

            foreach ($mappedDeductions as $key => $mapped) {
                $actual = match ($key) {
                    'pension' => Money::fromShillings((float) $slip['pension']),
                    'insurance_premium' => Money::fromShillings((float) $slip['insurance_premium']),
                    'helb' => Money::fromShillings((float) $slip['helb']),
                    'sacco' => Money::fromShillings((float) $slip['sacco']),
                    default => 0,
                };

                if ($actual > 0) {
                    $lineItems[] = $this->item($companyId, $mapped['pay_item_id'], $mapped['label'], 'deduction', $actual);
                }
            }

            $remainingNet = max(0, $net);
            $otherDeduction = 0;
            $wageFloor = $this->wageFloor($gross);

            foreach ($postTaxItems as $deduction) {
                $actual = min($deduction['amount_cents'], max(0, $remainingNet - $wageFloor));
                $remainingNet -= $actual;
                $otherDeduction += $actual;

                if ($actual > 0) {
                    $lineItems[] = $this->item($companyId, $deduction['pay_item_id'], $deduction['label'], 'deduction', $actual);
                }
            }

            $loanDeduction = 0;
            $advanceDeduction = 0;

            foreach ($loanRows[$employeeId] ?? [] as $schedule) {
                $due = (int) $schedule['principal_cents'] + (int) $schedule['interest_cents'] - (int) $schedule['paid_cents'];
                $actual = min(max(0, $due), max(0, $remainingNet - $wageFloor));

                if ($actual <= 0) {
                    continue;
                }

                $remainingNet -= $actual;

                if ($schedule['type'] === 'advance') {
                    $advanceDeduction += $actual;
                    $lineItems[] = $this->item($companyId, null, 'Advance recovery', 'deduction', $actual);
                } else {
                    $loanDeduction += $actual;
                    $lineItems[] = $this->item($companyId, null, 'Loan recovery', 'deduction', $actual);
                }

                if (!$includeConsumed) {
                    $recoveries[] = ['schedule_id' => (int) $schedule['id'], 'amount_cents' => $actual, 'loan_id' => (int) $schedule['loan_id']];
                }
            }

            $damageRecovery = 0;

            foreach ($damageRows[$employeeId] ?? [] as $claim) {
                $due = (int) ($claim['due_cents'] ?? $claim['amount_cents'] ?? 0);
                $actual = min(max(0, $due), max(0, $remainingNet - $wageFloor));

                if ($actual <= 0) {
                    continue;
                }

                $remainingNet -= $actual;
                $damageRecovery += $actual;

                if (!$includeConsumed) {
                    $damageRecoveries[] = [
                        'claim_id' => (int) $claim['id'],
                        'amount_cents' => $actual,
                    ];
                }
            }

            if ($damageRecovery > 0) {
                $otherDeduction += $damageRecovery;
                $lineItems[] = $this->item($companyId, null, 'Damage or loss recovery', 'deduction', $damageRecovery);
            }

            if ($commission > 0) {
                $lineItems[] = $this->item($companyId, null, 'Commission', 'earning', $commission);
            }

            if ($serviceShare > 0) {
                $lineItems[] = $this->item($companyId, null, 'Service charge', 'earning', $serviceShare);
            }

            if ($final > 0) {
                $lineItems[] = $this->item($companyId, null, 'Final dues', 'earning', $final);
            }

            if ($reimbursement > 0) {
                $lineItems[] = $this->item($companyId, null, 'Expense reimbursement', 'reimbursement', $reimbursement);
            }

            $net = $remainingNet + $reimbursement;
            $items[$employeeId] = $lineItems;

            $line = [
                'employee_id' => $employeeId,
                'employee_no' => (string) $employee['employee_no'],
                'employee_name' => trim($employee['first_name'] . ' ' . $employee['last_name']),
                'gross_cents' => $gross,
                'taxable_cents' => Money::fromShillings((float) $slip['taxable_pay']),
                'nssf_employee_cents' => Money::fromShillings((float) $slip['nssf']['total']),
                'shif_cents' => Money::fromShillings((float) $slip['shif']),
                'housing_levy_employee_cents' => Money::fromShillings((float) $slip['housing_levy']),
                'paye_cents' => Money::fromShillings((float) $slip['paye']),
                'deductions_cents' => $engineDeductions + $otherDeduction + $loanDeduction + $advanceDeduction,
                'net_cents' => $net,
                'reimbursements_cents' => $reimbursement,
                'loan_deduction_cents' => $loanDeduction,
                'advance_deduction_cents' => $advanceDeduction,
                'other_deduction_cents' => $otherDeduction,
                'commission_cents' => $commission,
                'service_charge_cents' => $serviceShare,
                'final_dues_cents' => $final,
                'proration_bp' => $proration['basis_points'],
                'nssf_employer_cents' => Money::fromShillings((float) $cost['nssf']),
                'housing_levy_employer_cents' => Money::fromShillings((float) $cost['housing_levy']),
                'nita_cents' => Money::fromShillings((float) $cost['nita']),
                'employer_cost_cents' => Money::fromShillings((float) $cost['total_cost']),
                'leave_deduction_cents' => (int) $leave['leave_deduction_cents'],
                'holiday_premium_cents' => (int) $leave['holiday_premium_cents'],
                'toil_days' => (float) $leave['toil_days'],
                'breakdown' => json_encode([
                    'payslip' => $slip,
                    'employer' => $cost,
                    'leave' => $leave,
                    'base_salary_cents' => $base,
                    'proration' => $proration,
                    'pay_items' => $lineItems,
                    'reimbursements_cents' => $reimbursement,
                    'commission_cents' => $commission,
                    'service_charge_cents' => $serviceShare,
                    'final_dues_cents' => $final,
                    'loan_deduction_cents' => $loanDeduction,
                    'advance_deduction_cents' => $advanceDeduction,
                    'other_deduction_cents' => $otherDeduction,
                    'wage_floor_cents' => $wageFloor,
                    'deduction_capped' => $remainingNet < $wageFloor || $remainingNet === $wageFloor,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];

            $lines[] = $line;
        }

        foreach ($this->claims->commissionsForPeriod($companyId, $period, $includeConsumed) as $row) {
            if (!$includeConsumed || $row['run_id'] === null) {
                $source['commissions'][] = (int) $row['id'];
            }
        }

        foreach ($this->claims->serviceForPeriod($companyId, $period, $includeConsumed) as $row) {
            if (!$includeConsumed || $row['run_id'] === null) {
                $source['service'][] = (int) $row['id'];
            }
        }

        foreach ($this->claims->expensesForPeriod($companyId, $period, $includeConsumed) as $row) {
            if (!$includeConsumed || $row['run_id'] === null) {
                $source['expenses'][] = (int) $row['id'];
            }
        }

        foreach ($this->claims->damageForPeriod($companyId, $includeConsumed) as $row) {
            if (!$includeConsumed || $row['run_id'] === null) {
                $source['damage'][] = (int) $row['id'];
            }
        }

        foreach ($this->claims->finalDuesForPeriod($companyId, $period, $includeConsumed) as $row) {
            if (!$includeConsumed || $row['run_id'] === null) {
                $source['final_dues'][] = (int) $row['id'];
            }
        }

        return [
            'lines' => $lines,
            'items' => $items,
            'recoveries' => $recoveries,
            'damage_recoveries' => $damageRecoveries,
            'source' => $source,
            'consume' => !$includeConsumed,
            'totals' => $this->totalsFor($lines),
            'period_end' => $periodEnd,
            'parent_run_id' => $parentRunId,
        ];
    }

    /**
     * @param array<string,mixed> $header
     * @param array<string,mixed> $computed
     */
    private function persist(int $companyId, array $header, array $computed): int
    {
        $this->db->beginTransaction();

        try {
            $runId = $this->runs->create($companyId, $header);
            $this->runs->addLines($runId, $computed['lines']);
            $this->runs->addLineItems($runId, $computed['items']);

            if ($computed['consume']) {
                $this->leavePayroll->markEncashed($companyId, (string) $computed['period_end'], $runId);

                foreach ($computed['recoveries'] as $recovery) {
                    $this->loans->applyRecovery($companyId, $recovery['schedule_id'], $recovery['amount_cents']);
                    $this->loans->settle($companyId, $recovery['loan_id']);
                }

                foreach ($computed['source']['commissions'] as $id) {
                    $this->claims->markCommissionPaid($companyId, $id, $runId);
                }

                foreach ($computed['source']['service'] as $id) {
                    $this->claims->markServicePaid($companyId, $id, $runId);
                }

                foreach ($computed['source']['expenses'] as $id) {
                    $this->claims->markExpensePaid($companyId, $id, $runId);
                }

                foreach ($computed['damage_recoveries'] as $recovery) {
                    $this->claims->markDamageRecovered(
                        $companyId,
                        (int) $recovery['claim_id'],
                        $runId,
                        (int) $recovery['amount_cents'],
                    );
                }

                foreach ($computed['source']['final_dues'] as $id) {
                    $this->claims->markFinalDuePaid($companyId, $id, $runId);
                }
            }

            $this->db->commit();

            return $runId;
        } catch (PDOException $error) {
            $this->db->rollBack();

            if ((string) $error->getCode() === '23000') {
                throw new HttpException(409, 'A payroll run already exists for that month and sequence.');
            }

            throw $error;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /**
     * @param list<array<string,mixed>> $current
     * @param array<int,array<string,mixed>> $parent
     * @return list<array<string,mixed>>
     */
    private function difference(array $current, array $parent, string $reason, int $parentRunId): array
    {
        $lines = [];
        $seen = [];

        foreach ($current as $line) {
            $employeeId = (int) $line['employee_id'];
            $seen[$employeeId] = true;
            $before = $parent[$employeeId] ?? null;
            $delta = $line;

            foreach (self::LINE_FIELDS as $field) {
                $delta[$field] = (int) ($line[$field] ?? 0) - (int) ($before[$field] ?? 0);
            }

            $delta['toil_days'] = (float) ($line['toil_days'] ?? 0) - (float) ($before['toil_days'] ?? 0);
            $delta['proration_bp'] = (int) $line['proration_bp'];
            $delta['breakdown'] = json_encode([
                'adjustment' => true,
                'reason' => $reason,
                'parent_run_id' => $parentRunId,
                'current' => json_decode((string) $line['breakdown'], true),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($this->nonZero($delta)) {
                $lines[] = $delta;
            }
        }

        foreach ($parent as $employeeId => $line) {
            if (isset($seen[$employeeId])) {
                continue;
            }

            $delta = $line;

            foreach (self::LINE_FIELDS as $field) {
                $delta[$field] = -(int) ($line[$field] ?? 0);
            }

            $delta['toil_days'] = -(float) ($line['toil_days'] ?? 0);
            $delta['breakdown'] = json_encode([
                'adjustment' => true,
                'reason' => $reason,
                'parent_run_id' => $parentRunId,
                'removed_employee' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($this->nonZero($delta)) {
                $lines[] = $delta;
            }
        }

        return $lines;
    }

    /** @param array<string,mixed> $line */
    private function nonZero(array $line): bool
    {
        foreach (self::LINE_FIELDS as $field) {
            if ((int) ($line[$field] ?? 0) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,int>
     */
    private function totalsFor(array $lines): array
    {
        $totals = [
            'employee_count' => count($lines),
            'gross_cents' => 0,
            'deductions_cents' => 0,
            'net_cents' => 0,
            'employer_nssf_cents' => 0,
            'employer_housing_cents' => 0,
            'employer_nita_cents' => 0,
            'employer_cost_cents' => 0,
            'reimbursements_cents' => 0,
            'loan_deductions_cents' => 0,
            'other_deductions_cents' => 0,
            'commission_cents' => 0,
            'service_charge_cents' => 0,
            'final_dues_cents' => 0,
        ];

        foreach ($lines as $line) {
            $totals['gross_cents'] += (int) $line['gross_cents'];
            $totals['deductions_cents'] += (int) $line['deductions_cents'];
            $totals['net_cents'] += (int) $line['net_cents'];
            $totals['employer_nssf_cents'] += (int) $line['nssf_employer_cents'];
            $totals['employer_housing_cents'] += (int) $line['housing_levy_employer_cents'];
            $totals['employer_nita_cents'] += (int) $line['nita_cents'];
            $totals['employer_cost_cents'] += (int) $line['employer_cost_cents'];
            $totals['reimbursements_cents'] += (int) $line['reimbursements_cents'];
            $totals['loan_deductions_cents'] += (int) $line['loan_deduction_cents'] + (int) $line['advance_deduction_cents'];
            $totals['other_deductions_cents'] += (int) $line['other_deduction_cents'];
            $totals['commission_cents'] += (int) $line['commission_cents'];
            $totals['service_charge_cents'] += (int) $line['service_charge_cents'];
            $totals['final_dues_cents'] += (int) $line['final_dues_cents'];
        }

        return $totals;
    }

    /**
     * Prorate by calendar days for monthly staff and by paid weekdays for daily
     * or hourly staff. A leaver is paid through their last day, not the month end.
     *
     * @param array<string,mixed> $employee
     * @param list<array<string,mixed>> $rates effective-dated pay rates
     * @return array{days:int,basis_points:int,factor:float,base_cents:int,segments:list<array<string,mixed>>}
     */
    private function proration(array $employee, string $periodStart, string $periodEnd, array $rates = []): array
    {
        $start = max((string) $employee['start_date'], $periodStart);
        $end = min(
            (string) ($employee['employment_end_date'] ?? $periodEnd) ?: $periodEnd,
            $periodEnd,
        );

        if ($end < $start) {
            return ['days' => 0, 'basis_points' => 0, 'factor' => 0.0, 'base_cents' => 0, 'segments' => []];
        }

        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        $days = (int) $startDate->diff($endDate)->days + 1;
        $monthDays = (int) (new DateTimeImmutable($periodStart))->format('t');
        $workingDays = $this->workingDays(new DateTimeImmutable($periodStart), new DateTimeImmutable($periodEnd));
        $paidWorkingDays = $this->workingDays($startDate, $endDate);
        $factor = $workingDays > 0 && (string) ($employee['pay_basis'] ?? 'monthly') !== 'monthly'
            ? $paidWorkingDays / $workingDays
            : $days / $monthDays;
        $basisPoints = (int) round($factor * 10000);

        if ($rates === []) {
            $salary = (int) $employee['monthly_salary_cents'];
            $basis = (string) ($employee['pay_basis'] ?? 'monthly');
            $base = match ($basis) {
                'daily' => $salary * $paidWorkingDays,
                'hourly' => $salary * $paidWorkingDays * 8,
                default => (int) round($salary * $days / $monthDays),
            };

            return ['days' => $days, 'basis_points' => $basisPoints, 'factor' => $factor, 'base_cents' => $base, 'segments' => []];
        }

        usort($rates, static fn (array $a, array $b): int => strcmp((string) $a['effective_from'], (string) $b['effective_from']));
        $boundaries = [$start];

        foreach ($rates as $rate) {
            $effective = (string) $rate['effective_from'];

            if ($effective > $start && $effective <= $end) {
                $boundaries[] = $effective;
            }
        }

        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);
        $base = 0;
        $segments = [];

        foreach ($boundaries as $index => $segmentStart) {
            $next = $boundaries[$index + 1] ?? null;
            $segmentEnd = $next === null ? $end : date('Y-m-d', strtotime($next . ' -1 day'));

            if ($segmentEnd > $end) {
                $segmentEnd = $end;
            }

            $active = null;

            foreach ($rates as $rate) {
                if ((string) $rate['effective_from'] <= $segmentStart) {
                    $active = $rate;
                }
            }

            if ($active === null) {
                continue;
            }

            $segmentDays = (int) (new DateTimeImmutable($segmentStart))->diff(new DateTimeImmutable($segmentEnd))->days + 1;
            $segmentWorkingDays = $this->workingDays(new DateTimeImmutable($segmentStart), new DateTimeImmutable($segmentEnd));
            $segmentSalary = (int) $active['salary_cents'];
            $segmentBasis = (string) ($active['pay_basis'] ?? 'monthly');
            $segmentBase = match ($segmentBasis) {
                'daily' => $segmentSalary * $segmentWorkingDays,
                'hourly' => $segmentSalary * $segmentWorkingDays * 8,
                default => (int) round($segmentSalary * $segmentDays / $monthDays),
            };

            $base += $segmentBase;
            $segments[] = [
                'from' => $segmentStart,
                'to' => $segmentEnd,
                'days' => $segmentDays,
                'salary_cents' => $segmentSalary,
                'pay_basis' => $segmentBasis,
                'base_cents' => $segmentBase,
            ];
        }

        return ['days' => $days, 'basis_points' => $basisPoints, 'factor' => $factor, 'base_cents' => $base, 'segments' => $segments];
    }

    /** Employment Act s.19: net wages must not fall below one third. */
    private function wageFloor(int $grossCents): int
    {
        return $grossCents > 0 ? intdiv($grossCents + 2, 3) : 0;
    }

    private function workingDays(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $days = 0;
        $cursor = $start;

        while ($cursor <= $end) {
            if ((int) $cursor->format('N') <= 5) {
                $days++;
            }

            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        return $days;
    }

    /** @param array<string,mixed> $assignment */
    private function itemAmount(array $assignment, int $base, float $factor): int
    {
        if ((string) $assignment['calc_type'] === 'percent' && $assignment['percent'] !== null) {
            return max(0, (int) round($base * (float) $assignment['percent'] / 100));
        }

        $amount = (int) ($assignment['amount_cents'] ?? 0);

        return max(0, (int) round($amount * $factor));
    }

    /** @return array<string,mixed> */
    private function item(int $companyId, ?int $payItemId, string $label, string $kind, int $amount): array
    {
        return [
            'company_id' => $companyId,
            'pay_item_id' => $payItemId,
            'label' => $label,
            'kind' => $kind,
            'amount_cents' => $amount,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,int>
     */
    private function sumByEmployee(array $rows, string $field): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $employeeId = (int) $row['employee_id'];
            $totals[$employeeId] = ($totals[$employeeId] ?? 0) + (int) $row[$field];
        }

        return $totals;
    }

    private function rate(string $periodStart): array
    {
        $rate = $this->rates->forDate($periodStart);

        if ($rate === null) {
            throw new HttpException(422, 'No statutory rates are effective for that month.');
        }

        try {
            Ruleset::assertValid($rate);
        } catch (InvalidArgumentException) {
            throw new HttpException(422, 'The statutory rates for that month are incomplete.');
        }

        return $rate;
    }

    private function label(string $label, string $periodStart): string
    {
        $label = trim($label);

        if (mb_strlen($label) > 120) {
            throw new HttpException(422, 'The run label is too long.');
        }

        return $label === '' ? date('F Y', strtotime($periodStart)) . ' payroll' : $label;
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

    private function periodEnd(string $periodStart): string
    {
        return (new DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
    }
}
