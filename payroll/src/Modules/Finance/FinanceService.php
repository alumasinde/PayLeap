<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

use DateTimeImmutable;
use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Modules\Payroll\PayrollRepository;
use Mshahara\Payroll\Modules\Payroll\RateVersionRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Money;
use PDO;
use Throwable;

/**
 * Finance operations that sit around payroll: pay structures, payment
 * release, statutory returns, loans, claims, reports and audit.
 */
final class FinanceService
{
    public function __construct(
        private readonly PDO $db,
        private readonly FinanceRepository $finance,
        private readonly PayItemRepository $payItems,
        private readonly PaymentRepository $payments,
        private readonly ReturnRepository $returns,
        private readonly LoanRepository $loans,
        private readonly ClaimRepository $claims,
        private readonly PayrollRepository $runs,
        private readonly RateVersionRepository $rates,
        private readonly EmployeeRepository $employees,
        private readonly AuditLogger $audit,
    ) {
    }

    public function dashboard(int $companyId): array
    {
        $this->finance->ensureGlDefaults($companyId);

        return $this->finance->dashboard($companyId);
    }

    /** @param array<string,mixed> $input */
    public function savePayItem(int $companyId, ?int $id, array $input, int $actorId): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        $kind = (string) ($input['kind'] ?? 'earning');
        $calc = (string) ($input['calc_type'] ?? 'fixed');
        $code = strtoupper(trim((string) ($input['code'] ?? '')));

        if ($name === '' || mb_strlen($name) > 120) {
            throw new HttpException(422, 'Give the pay item a name of 1 to 120 characters.');
        }

        if (!in_array($kind, ['earning', 'deduction'], true)) {
            throw new HttpException(422, 'Choose whether the item is an earning or a deduction.');
        }

        if (!in_array($calc, ['fixed', 'percent'], true)) {
            throw new HttpException(422, 'Choose a fixed amount or a percentage.');
        }

        if ($code !== '' && preg_match('/^[A-Z0-9_-]{2,40}$/', $code) !== 1) {
            throw new HttpException(422, 'Use letters, numbers, _ or - in the item code.');
        }

        $sameCode = $code === '' ? null : $this->payItems->findByCode($companyId, $code);

        if ($sameCode !== null && ($id === null || (int) $sameCode['id'] !== $id)) {
            throw new HttpException(422, 'That pay item code is already in use.');
        }

        $data = [
            'code' => $code,
            'name' => $name,
            'kind' => $kind,
            'taxable' => !empty($input['taxable']) ? 1 : 0,
            'pensionable' => !empty($input['pensionable']) ? 1 : 0,
            'cash' => !empty($input['cash']) ? 1 : 0,
            'calc_type' => $calc,
            'active' => 1,
            'sort_order' => max(0, min(999, (int) ($input['sort_order'] ?? 0))),
        ];

        if ($id === null) {
            $id = $this->payItems->create($companyId, $data);
            $this->audit->record($companyId, $actorId, 'pay_item.created', 'pay_item', (string) $id, ['name' => $name]);
        } else {
            if ($this->payItems->find($companyId, $id) === null) {
                throw new HttpException(404, 'That pay item does not exist.');
            }

            $this->payItems->update($companyId, $id, $data);
            $this->audit->record($companyId, $actorId, 'pay_item.updated', 'pay_item', (string) $id, ['name' => $name]);
        }

        return $id;
    }

    /** @param array<string,mixed> $input */
    public function assignPayItem(int $companyId, array $input, int $actorId): int
    {
        $employeeId = (int) ($input['employee_id'] ?? 0);

        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(422, 'Choose an employee from this company.');
        }

        $values = $this->payItemAssignmentValues($companyId, $input);

        if ($this->payItems->hasAssignment(
            $companyId,
            $employeeId,
            $values['pay_item_id'],
            $values['start_date'],
            $values['end_date'],
        )) {
            throw new HttpException(409, 'That employee already has this pay item for the selected dates.');
        }

        $assignmentId = $this->payItems->assign($companyId, ['employee_id' => $employeeId] + $values);

        $this->audit->record($companyId, $actorId, 'pay_item.assigned', 'employee_pay_item', (string) $assignmentId, [
            'employee_id' => $employeeId,
            'pay_item' => $values['pay_item_name'],
        ]);

        return $assignmentId;
    }

    /**
     * Assign one pay item to a checked set of employees atomically. Every
     * employee is validated before the first insert; one bad id rolls back
     * the whole batch. Existing overlapping assignments are skipped.
     *
     * @param array<string,mixed> $input
     * @return int number of new assignments
     */
    public function assignPayItemMany(int $companyId, array $input, int $actorId): int
    {
        $ids = $input['employee_ids'] ?? [];
        $ids = is_array($ids) ? $ids : [$ids];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            throw new HttpException(422, 'Select at least one employee.');
        }

        $values = $this->payItemAssignmentValues($companyId, $input);
        $valid = [];

        foreach ($ids as $employeeId) {
            $employee = $this->employees->find($companyId, $employeeId);

            if ($employee === null) {
                throw new HttpException(422, 'One of the selected employees does not belong to this company.');
            }

            if ((string) $employee['status'] !== 'active') {
                throw new HttpException(422, 'Pay items can only be assigned to active employees.');
            }

            $valid[] = $employeeId;
        }

        $this->db->beginTransaction();

        try {
            $assigned = 0;

            foreach ($valid as $employeeId) {
                if ($this->payItems->hasAssignment(
                    $companyId,
                    $employeeId,
                    $values['pay_item_id'],
                    $values['start_date'],
                    $values['end_date'],
                )) {
                    continue;
                }

                $assignmentId = $this->payItems->assign($companyId, ['employee_id' => $employeeId] + $values);
                $this->audit->record($companyId, $actorId, 'pay_item.assigned', 'employee_pay_item', (string) $assignmentId, [
                    'employee_id' => $employeeId,
                    'pay_item' => $values['pay_item_name'],
                ]);
                $assigned++;
            }

            $this->db->commit();

            return $assigned;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{pay_item_id:int,pay_item_name:string,amount_cents:?int,percent:?float,start_date:?string,end_date:?string}
     */
    private function payItemAssignmentValues(int $companyId, array $input): array
    {
        $itemId = (int) ($input['pay_item_id'] ?? 0);
        $item = $this->payItems->find($companyId, $itemId);

        if ($item === null || (int) $item['active'] !== 1) {
            throw new HttpException(422, 'Choose an active pay item.');
        }

        $amount = Money::toCents((string) ($input['amount'] ?? ''));
        $percent = trim((string) ($input['percent'] ?? ''));
        $percentValue = $percent === '' ? null : (float) $percent;

        if ($amount === null && $percentValue === null) {
            throw new HttpException(422, 'Enter a fixed amount or a percentage.');
        }

        if ($percentValue !== null && ($percentValue <= 0 || $percentValue > 100)) {
            throw new HttpException(422, 'The percentage must be between 0 and 100.');
        }

        $start = $this->date((string) ($input['start_date'] ?? ''), 'Start date', true);
        $end = $this->date((string) ($input['end_date'] ?? ''), 'End date', false);

        if ($start !== null && $end !== null && $end < $start) {
            throw new HttpException(422, 'The end date cannot be before the start date.');
        }

        return [
            'pay_item_id' => $itemId,
            'pay_item_name' => (string) $item['name'],
            'amount_cents' => $amount,
            'percent' => $percentValue,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }

    public function removePayItemAssignment(int $companyId, int $id, int $actorId): void
    {
        if (!$this->payItems->removeAssignment($companyId, $id)) {
            throw new HttpException(404, 'That assignment does not exist.');
        }

        $this->audit->record($companyId, $actorId, 'pay_item.assignment_removed', 'employee_pay_item', (string) $id);
    }

    public function togglePayItem(int $companyId, int $id, int $actorId): void
    {
        if ($this->payItems->find($companyId, $id) === null) {
            throw new HttpException(404, 'That pay item does not exist.');
        }

        $this->payItems->toggle($companyId, $id);
        $this->audit->record($companyId, $actorId, 'pay_item.toggled', 'pay_item', (string) $id);
    }

    /** @param array<string,mixed> $input */
    public function createLoan(int $companyId, array $input, int $actorId): int
    {
        $employeeId = (int) ($input['employee_id'] ?? 0);
        $principal = Money::toCents((string) ($input['principal'] ?? ''));
        $installments = (int) ($input['installments'] ?? 1);
        $rateBp = (int) round((float) ($input['rate_percent'] ?? 0) * 100);
        $period = trim((string) ($input['start_period'] ?? date('Y-m')));

        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(422, 'Choose an employee from this company.');
        }

        if ($principal === null || $principal <= 0) {
            throw new HttpException(422, 'Enter a loan amount greater than zero.');
        }

        if ($installments < 1 || $installments > 120) {
            throw new HttpException(422, 'Choose between 1 and 120 installments.');
        }

        if ($rateBp < 0 || $rateBp > 10000) {
            throw new HttpException(422, 'Enter an interest rate between 0 and 100 percent.');
        }

        $period = $this->period($period);
        $reference = trim((string) ($input['reference'] ?? ''));
        $reference = $reference === ''
            ? 'LOAN-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)))
            : strtoupper(substr($reference, 0, 80));

        $loanId = $this->loans->create(
            $companyId,
            $employeeId,
            $principal,
            $rateBp,
            $installments,
            $period,
            'company',
            $reference,
            trim((string) ($input['notes'] ?? '')) ?: null,
        );

        $this->audit->record($companyId, $actorId, 'loan.created', 'loan', (string) $loanId, [
            'employee_id' => $employeeId,
            'principal_cents' => $principal,
            'installments' => $installments,
        ]);

        return $loanId;
    }

    /** @param array<string,mixed> $input */
    public function createAdvance(int $companyId, array $input, int $actorId): int
    {
        $employeeId = (int) ($input['employee_id'] ?? 0);
        $amount = Money::toCents((string) ($input['amount'] ?? ''));
        $installments = max(1, min(12, (int) ($input['installments'] ?? 1)));

        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(422, 'Choose an employee from this company.');
        }

        if ($amount === null || $amount <= 0) {
            throw new HttpException(422, 'Enter an advance amount greater than zero.');
        }

        $id = $this->loans->createAdvance($companyId, [
            'employee_id' => $employeeId,
            'amount_cents' => $amount,
            'reason' => trim((string) ($input['reason'] ?? '')),
            'start_period' => $this->period((string) ($input['start_period'] ?? date('Y-m'))),
            'installments' => $installments,
        ]);

        $this->audit->record($companyId, $actorId, 'advance.requested', 'advance_request', (string) $id, [
            'employee_id' => $employeeId,
            'amount_cents' => $amount,
        ]);

        return $id;
    }

    public function decideAdvance(int $companyId, int $id, string $decision, int $actorId): void
    {
        if ($decision === 'approved') {
            $loanId = $this->loans->approveAdvance($companyId, $id, $actorId);
            $this->audit->record($companyId, $actorId, 'advance.approved', 'advance_request', (string) $id, ['loan_id' => $loanId]);

            return;
        }

        $this->loans->rejectAdvance($companyId, $id, $actorId);
        $this->audit->record($companyId, $actorId, 'advance.rejected', 'advance_request', (string) $id);
    }

    public function createExpense(int $companyId, array $input, int $actorId): int
    {
        $employeeId = (int) ($input['employee_id'] ?? 0);
        $amount = Money::toCents((string) ($input['amount'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));

        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(422, 'Choose an employee from this company.');
        }

        if ($category === '' || $amount === null || $amount <= 0) {
            throw new HttpException(422, 'Enter a category and an amount greater than zero.');
        }

        $id = $this->claims->createExpense($companyId, [
            'employee_id' => $employeeId,
            'category' => $category,
            'period' => $this->period((string) ($input['period'] ?? date('Y-m'))),
            'amount_cents' => $amount,
            'receipt_ref' => trim((string) ($input['receipt_ref'] ?? '')),
        ]);

        $this->audit->record($companyId, $actorId, 'expense_claim.created', 'expense_claim', (string) $id, [
            'employee_id' => $employeeId,
            'amount_cents' => $amount,
        ]);

        return $id;
    }

    public function decideExpense(int $companyId, int $id, string $decision, int $actorId): void
    {
        $this->claims->decideExpense($companyId, $id, $decision, $actorId);
        $this->audit->record($companyId, $actorId, 'expense_claim.' . $decision, 'expense_claim', (string) $id);
    }

    public function createDamage(int $companyId, array $input, int $actorId): int
    {
        $employeeId = (int) ($input['employee_id'] ?? 0);
        $amount = Money::toCents((string) ($input['amount'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(422, 'Choose an employee from this company.');
        }

        if ($description === '' || $amount === null || $amount <= 0) {
            throw new HttpException(422, 'Describe the damage and enter an amount greater than zero.');
        }

        $id = $this->claims->createDamage($companyId, [
            'employee_id' => $employeeId,
            'description' => $description,
            'amount_cents' => $amount,
            'recover_via_payroll' => 1,
        ]);

        $this->audit->record($companyId, $actorId, 'damage_claim.created', 'loss_damage_claim', (string) $id, [
            'employee_id' => $employeeId,
            'amount_cents' => $amount,
        ]);

        return $id;
    }

    public function decideDamage(int $companyId, int $id, string $decision, int $actorId): void
    {
        $this->claims->decideDamage($companyId, $id, $decision);
        $this->audit->record($companyId, $actorId, 'damage_claim.' . $decision, 'loss_damage_claim', (string) $id);
    }

    public function createCommission(int $companyId, array $input, int $actorId): int
    {
        $employeeId = (int) ($input['employee_id'] ?? 0);
        $amount = Money::toCents((string) ($input['amount'] ?? ''));
        $base = Money::toCents((string) ($input['base'] ?? '0')) ?? 0;

        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(422, 'Choose an employee from this company.');
        }

        if ($amount === null || $amount <= 0) {
            throw new HttpException(422, 'Enter a commission amount greater than zero.');
        }

        $id = $this->claims->createCommission($companyId, [
            'employee_id' => $employeeId,
            'period' => $this->period((string) ($input['period'] ?? date('Y-m'))),
            'base_cents' => $base,
            'amount_cents' => $amount,
        ]);

        $this->audit->record($companyId, $actorId, 'commission.created', 'commission_entry', (string) $id, [
            'employee_id' => $employeeId,
            'amount_cents' => $amount,
        ]);

        return $id;
    }

    public function createServicePool(int $companyId, array $input, int $actorId): int
    {
        $total = Money::toCents((string) ($input['total'] ?? ''));
        $period = $this->period((string) ($input['period'] ?? date('Y-m')));

        if ($total === null || $total <= 0) {
            throw new HttpException(422, 'Enter a service charge total greater than zero.');
        }

        $existing = $this->db->prepare('SELECT id FROM service_charge_pools WHERE company_id = ? AND period = ? LIMIT 1');
        $existing->execute([$companyId, $period]);

        if ($existing->fetchColumn() !== false) {
            throw new HttpException(409, 'A service charge pool already exists for that month.');
        }

        $id = $this->claims->createServicePool($companyId, $period, $total);
        $this->audit->record($companyId, $actorId, 'service_charge.created', 'service_charge_pool', (string) $id, [
            'period' => $period,
            'total_cents' => $total,
        ]);

        return $id;
    }

    public function createFinalDue(int $companyId, array $input, int $actorId): int
    {
        $employeeId = (int) ($input['employee_id'] ?? 0);

        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(422, 'Choose an employee from this company.');
        }

        $data = [
            'employee_id' => $employeeId,
            'period' => $this->period((string) ($input['period'] ?? date('Y-m'))),
            'leave_payout_cents' => Money::toCents((string) ($input['leave_payout'] ?? '0')) ?? 0,
            'notice_cents' => Money::toCents((string) ($input['notice'] ?? '0')) ?? 0,
            'severance_cents' => Money::toCents((string) ($input['severance'] ?? '0')) ?? 0,
            'service_pay_cents' => Money::toCents((string) ($input['service_pay'] ?? '0')) ?? 0,
            'other_cents' => Money::toCents((string) ($input['other'] ?? '0')) ?? 0,
            'notes' => trim((string) ($input['notes'] ?? '')),
        ];

        if (array_sum([
            $data['leave_payout_cents'], $data['notice_cents'], $data['severance_cents'],
            $data['service_pay_cents'], $data['other_cents'],
        ]) <= 0) {
            throw new HttpException(422, 'Enter at least one final dues amount.');
        }

        $id = $this->claims->createFinalDue($companyId, $data);
        $this->audit->record($companyId, $actorId, 'final_dues.created', 'final_dues', (string) $id, [
            'employee_id' => $employeeId,
            'total_cents' => $data['leave_payout_cents'] + $data['notice_cents'] + $data['severance_cents'] + $data['service_pay_cents'] + $data['other_cents'],
        ]);

        return $id;
    }

    public function decideFinalDue(int $companyId, int $id, string $decision, int $actorId): void
    {
        $this->claims->decideFinalDue($companyId, $id, $decision);
        $this->audit->record($companyId, $actorId, 'final_dues.' . $decision, 'final_dues', (string) $id);
    }

    public function createPayment(int $companyId, int $runId, string $method, int $actorId): int
    {
        if (!in_array($method, ['bank', 'mpesa', 'cash'], true)) {
            throw new HttpException(422, 'Choose bank, M-Pesa or cash.');
        }

        $run = $this->runs->find($companyId, $runId);

        if ($run === null) {
            throw new HttpException(404, 'That payroll run does not exist.');
        }

        if ($run['status'] !== 'approved') {
            throw new HttpException(409, 'Only an approved run can be paid.');
        }

        if ($run['payment_status'] === 'paid') {
            throw new HttpException(409, 'That run has already been paid.');
        }

        if ((int) $run['net_cents'] <= 0) {
            throw new HttpException(422, 'This run has no positive net pay to release.');
        }

        if ($this->payments->existingForRun($companyId, $runId) !== null) {
            throw new HttpException(409, 'A payment batch already exists for that run.');
        }

        if ($method !== 'cash') {
            $missing = $this->payments->missingDetails($companyId, $runId, $method);

            if ($missing !== []) {
                $names = implode(', ', array_slice(array_column($missing, 'employee_name'), 0, 4));
                throw new HttpException(422, 'Payment details are missing for ' . count($missing) . ' employee(s): ' . $names . '.');
            }
        }

        $reference = sprintf('PAY-%s-%03d', str_replace('-', '', (string) $run['period']), (int) $run['run_no']);
        $attempt = 1;

        while ($this->payments->referenceExists($companyId, $reference) && $attempt < 100) {
            $attempt++;
            $reference = sprintf(
                'PAY-%s-%03d-R%d',
                str_replace('-', '', (string) $run['period']),
                (int) $run['run_no'],
                $attempt,
            );
        }

        if ($this->payments->referenceExists($companyId, $reference)) {
            throw new HttpException(409, 'Could not allocate a payment reference for that run.');
        }

        $batchId = $this->payments->create($companyId, $runId, $method, $reference, $actorId);

        $this->audit->record($companyId, $actorId, 'payment.batch_prepared', 'payment_batch', (string) $batchId, [
            'run_id' => $runId,
            'method' => $method,
            'reference' => $reference,
        ]);

        return $batchId;
    }

    public function releasePayment(int $companyId, int $batchId, int $actorId): void
    {
        if (!$this->payments->release($companyId, $batchId, $actorId)) {
            throw new HttpException(409, 'That payment batch is not waiting for release.');
        }

        $this->audit->record($companyId, $actorId, 'payment.released', 'payment_batch', (string) $batchId);
    }

    public function cancelPayment(int $companyId, int $batchId, int $actorId): void
    {
        if (!$this->payments->cancel($companyId, $batchId, $actorId)) {
            throw new HttpException(409, 'Only a prepared payment batch can be cancelled.');
        }

        $this->audit->record($companyId, $actorId, 'payment.batch_cancelled', 'payment_batch', (string) $batchId);
    }

    /**
     * Generate a statutory return from a run. Returns are immutable snapshots;
     * generating the same type twice for a run is refused.
     *
     * @return int return id
     */
    public function generateReturn(int $companyId, int $runId, string $type, int $actorId): int
    {
        $types = $this->returnTypes();

        if (!isset($types[$type])) {
            throw new HttpException(422, 'Choose a supported statutory return.');
        }

        $run = $this->runs->find($companyId, $runId);

        if ($run === null) {
            throw new HttpException(404, 'That payroll run does not exist.');
        }

        if ($run['status'] !== 'approved') {
            throw new HttpException(409, 'Generate returns from an approved run.');
        }

        $period = $type === 'p9a' ? substr((string) $run['period'], 0, 4) : (string) $run['period'];

        if ($this->returns->existing($companyId, $type, $period, $runId) !== null) {
            throw new HttpException(409, 'That return has already been generated for this run.');
        }

        $payload = $this->returnPayload($companyId, $run, $type);
        $amount = array_sum(array_map(static fn (array $row): int => (int) ($row['amount_cents'] ?? 0), $payload['rows']));
        $base = new DateTimeImmutable($type === 'p9a' ? $period . '-12-01' : $period . '-01');
        $due = $base->modify('first day of next month')->format('Y-m-09');

        $id = $this->returns->create($companyId, [
            'type' => $type,
            'period' => $period,
            'run_id' => $runId,
            'amount_cents' => $amount,
            'due_date' => $due,
            'payload' => $payload,
        ]);

        $this->audit->record($companyId, $actorId, 'return.prepared', 'statutory_return', (string) $id, [
            'type' => $type,
            'period' => $period,
            'amount_cents' => $amount,
        ]);

        return $id;
    }

    public function markReturnFiled(int $companyId, int $id, string $reference, int $actorId): void
    {
        $return = $this->returns->find($companyId, $id);

        if ($return === null) {
            throw new HttpException(404, 'That return does not exist.');
        }

        if ((string) $return['status'] !== 'prepared') {
            throw new HttpException(409, 'That return has already been filed.');
        }

        $reference = trim($reference);

        if ($reference === '' || mb_strlen($reference) > 120) {
            throw new HttpException(422, 'Enter the portal filing reference.');
        }

        $this->returns->markFiled($companyId, $id, $reference, $actorId);
        $this->audit->record($companyId, $actorId, 'return.filed', 'statutory_return', (string) $id, [
            'reference' => $reference,
        ]);
    }

    /** @return array<string,string> */
    public function returnTypes(): array
    {
        return [
            'p10' => 'P10 monthly PAYE',
            'paye' => 'PAYE schedule',
            'nssf' => 'NSSF return',
            'shif' => 'SHIF return',
            'housing' => 'Housing Levy return',
            'nita' => 'NITA schedule',
            'p9a' => 'P9A annual tax certificate',
        ];
    }

    /**
     * Report data with a stable shape for the template and CSV export.
     *
     * @return array{title:string,period:string,columns:list<array{key:string,label:string,type:string}>,rows:list<array<string,mixed>>,totals:array<string,int>}
     */
    public function report(int $companyId, string $key, string $period): array
    {
        $this->finance->ensureGlDefaults($companyId);
        $period = $this->period($period);

        return match ($key) {
            'department-cost' => $this->departmentReport($companyId, $period),
            'variance' => $this->varianceReport($companyId, $period),
            'statutory' => $this->statutoryReport($companyId, $period),
            'leave-liability' => $this->leaveLiabilityReport($companyId),
            'loan-balances' => $this->loanReport($companyId),
            'p9' => $this->p9Report($companyId, $period),
            'p10' => $this->p10Report($companyId, $period),
            'gl-journal' => $this->glReport($companyId, $period),
            default => $this->summaryReport($companyId, $period),
        };
    }

    /** @return list<array<string,mixed>> */
    public function paymentFileRows(int $companyId, int $batchId): array
    {
        $batch = $this->payments->find($companyId, $batchId);

        if ($batch === null) {
            throw new HttpException(404, 'That payment batch does not exist.');
        }

        $rows = [];

        foreach ($this->payments->lines($companyId, $batchId) as $line) {
            $rows[] = [
                'employee_no' => $line['employee_no'],
                'name' => $line['first_name'] . ' ' . $line['last_name'],
                'amount' => number_format((int) $line['amount_cents'] / 100, 2, '.', ''),
                'bank' => $line['bank_name'] ?? '',
                'branch' => $line['bank_branch'] ?? '',
                'account' => $line['account_no'] ?? '',
                'mpesa' => $line['mpesa_no'] ?? '',
                'reference' => $batch['reference'],
            ];
        }

        return $rows;
    }

    private function summaryReport(int $companyId, string $period): array
    {
        $rows = array_values(array_filter(
            $this->finance->payrollTrend($companyId, 24),
            static fn (array $row): bool => (string) $row['period'] === $period,
        ));

        if ($rows === []) {
            $run = $this->db->prepare(
                'SELECT period, label, gross_cents, deductions_cents, net_cents, employer_cost_cents, employee_count
                 FROM payroll_runs WHERE company_id = ? AND period = ? AND status = "approved"
                 ORDER BY run_no DESC LIMIT 1',
            );
            $run->execute([$companyId, $period]);
            $row = $run->fetch();
            $rows = $row === false ? [] : [$row];
        }

        return [
            'title' => 'Payroll summary',
            'period' => $period,
            'columns' => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'text'],
                ['key' => 'label', 'label' => 'Run', 'type' => 'text'],
                ['key' => 'employee_count', 'label' => 'People', 'type' => 'int'],
                ['key' => 'gross_cents', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'deductions_cents', 'label' => 'Deductions', 'type' => 'money'],
                ['key' => 'net_cents', 'label' => 'Net pay', 'type' => 'money'],
                ['key' => 'employer_cost_cents', 'label' => 'Employer cost', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['gross_cents', 'deductions_cents', 'net_cents', 'employer_cost_cents']),
        ];
    }

    private function departmentReport(int $companyId, string $period): array
    {
        $rows = $this->finance->departmentCost($companyId, $period);

        return [
            'title' => 'Payroll cost by department',
            'period' => $period,
            'columns' => [
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'employee_count', 'label' => 'People', 'type' => 'int'],
                ['key' => 'gross_cents', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'deductions_cents', 'label' => 'Deductions', 'type' => 'money'],
                ['key' => 'net_cents', 'label' => 'Net pay', 'type' => 'money'],
                ['key' => 'employer_cost_cents', 'label' => 'Employer cost', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['gross_cents', 'deductions_cents', 'net_cents', 'employer_cost_cents']),
        ];
    }

    private function varianceReport(int $companyId, string $period): array
    {
        $trend = $this->finance->payrollTrend($companyId, 36);
        $current = null;
        $previous = null;

        foreach ($trend as $index => $row) {
            if ((string) $row['period'] === $period) {
                $current = $row;
                $previous = $trend[$index - 1] ?? null;
            }
        }

        $rows = [];

        if ($current !== null) {
            $rows[] = [
                'measure' => 'Gross pay',
                'current_cents' => (int) $current['gross_cents'],
                'previous_cents' => (int) ($previous['gross_cents'] ?? 0),
                'variance_cents' => (int) $current['gross_cents'] - (int) ($previous['gross_cents'] ?? 0),
            ];
            $rows[] = [
                'measure' => 'Net pay',
                'current_cents' => (int) $current['net_cents'],
                'previous_cents' => (int) ($previous['net_cents'] ?? 0),
                'variance_cents' => (int) $current['net_cents'] - (int) ($previous['net_cents'] ?? 0),
            ];
            $rows[] = [
                'measure' => 'Employer cost',
                'current_cents' => (int) $current['employer_cost_cents'],
                'previous_cents' => (int) ($previous['employer_cost_cents'] ?? 0),
                'variance_cents' => (int) $current['employer_cost_cents'] - (int) ($previous['employer_cost_cents'] ?? 0),
            ];
        }

        return [
            'title' => 'Payroll variance',
            'period' => $period,
            'columns' => [
                ['key' => 'measure', 'label' => 'Measure', 'type' => 'text'],
                ['key' => 'current_cents', 'label' => 'This month', 'type' => 'money'],
                ['key' => 'previous_cents', 'label' => 'Previous month', 'type' => 'money'],
                ['key' => 'variance_cents', 'label' => 'Variance', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => [],
        ];
    }

    private function statutoryReport(int $companyId, string $period): array
    {
        $run = $this->db->prepare(
            'SELECT * FROM payroll_runs WHERE company_id = ? AND period = ? AND status = "approved"
             ORDER BY run_no DESC LIMIT 1',
        );
        $run->execute([$companyId, $period]);
        $row = $run->fetch();

        if ($row === false) {
            return ['title' => 'Statutory liability', 'period' => $period, 'columns' => [], 'rows' => [], 'totals' => []];
        }

        $s = $this->finance->statutory((int) $row['id']);
        $rows = [
            ['item' => 'PAYE', 'amount_cents' => $s['paye']],
            ['item' => 'NSSF (employee and employer)', 'amount_cents' => $s['nssf']],
            ['item' => 'SHIF', 'amount_cents' => $s['shif']],
            ['item' => 'Housing Levy (employee and employer)', 'amount_cents' => $s['housing']],
            ['item' => 'NITA', 'amount_cents' => $s['nita']],
        ];

        return [
            'title' => 'Statutory liability',
            'period' => $period,
            'columns' => [
                ['key' => 'item', 'label' => 'Item', 'type' => 'text'],
                ['key' => 'amount_cents', 'label' => 'Amount', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => ['amount_cents' => $s['total']],
        ];
    }

    private function leaveLiabilityReport(int $companyId): array
    {
        $rows = $this->finance->leaveLiability($companyId);
        $total = 0;

        foreach ($rows as &$row) {
            $row['liability_cents'] = (int) round((float) $row['liability'] * 100);
            $total += $row['liability_cents'];
        }

        unset($row);

        return [
            'title' => 'Leave liability',
            'period' => date('Y-m'),
            'columns' => [
                ['key' => 'employee_no', 'label' => 'No.', 'type' => 'text'],
                ['key' => 'first_name', 'label' => 'First name', 'type' => 'text'],
                ['key' => 'last_name', 'label' => 'Last name', 'type' => 'text'],
                ['key' => 'days', 'label' => 'Days', 'type' => 'decimal'],
                ['key' => 'liability_cents', 'label' => 'Liability', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => ['liability_cents' => $total],
        ];
    }

    private function loanReport(int $companyId): array
    {
        $rows = $this->finance->loanReport($companyId);

        return [
            'title' => 'Loan balances',
            'period' => date('Y-m'),
            'columns' => [
                ['key' => 'reference', 'label' => 'Reference', 'type' => 'text'],
                ['key' => 'employee_no', 'label' => 'No.', 'type' => 'text'],
                ['key' => 'first_name', 'label' => 'First name', 'type' => 'text'],
                ['key' => 'last_name', 'label' => 'Last name', 'type' => 'text'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'text'],
                ['key' => 'balance_cents', 'label' => 'Balance', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['balance_cents']),
        ];
    }

    private function p9Report(int $companyId, string $period): array
    {
        $rows = $this->finance->p9Rows($companyId, substr($period, 0, 4));

        return [
            'title' => 'P9 annual tax',
            'period' => substr($period, 0, 4),
            'columns' => [
                ['key' => 'employee_no', 'label' => 'No.', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'kra_pin', 'label' => 'KRA PIN', 'type' => 'text'],
                ['key' => 'gross_cents', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'taxable_cents', 'label' => 'Taxable', 'type' => 'money'],
                ['key' => 'paye_cents', 'label' => 'PAYE', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['gross_cents', 'taxable_cents', 'paye_cents']),
        ];
    }

    private function p10Report(int $companyId, string $period): array
    {
        $run = $this->db->prepare(
            'SELECT * FROM payroll_runs WHERE company_id = ? AND period = ? AND status = "approved"
             ORDER BY run_no DESC LIMIT 1',
        );
        $run->execute([$companyId, $period]);
        $row = $run->fetch();

        if ($row === false) {
            return ['title' => 'P10 monthly PAYE', 'period' => $period, 'columns' => [], 'rows' => [], 'totals' => []];
        }

        $lines = $this->returns->linesForRun((int) $row['id']);
        $rows = array_map(static fn (array $line): array => [
            'employee_no' => $line['employee_no'],
            'employee_name' => $line['employee_name'],
            'kra_pin' => $line['kra_pin'] ?? '',
            'gross_cents' => (int) $line['gross_cents'],
            'taxable_cents' => (int) $line['taxable_cents'],
            'paye_cents' => (int) $line['paye_cents'],
        ], $lines);

        return [
            'title' => 'P10 monthly PAYE',
            'period' => $period,
            'columns' => [
                ['key' => 'employee_no', 'label' => 'No.', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'kra_pin', 'label' => 'KRA PIN', 'type' => 'text'],
                ['key' => 'gross_cents', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'taxable_cents', 'label' => 'Taxable', 'type' => 'money'],
                ['key' => 'paye_cents', 'label' => 'PAYE', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['gross_cents', 'taxable_cents', 'paye_cents']),
        ];
    }

    private function glReport(int $companyId, string $period): array
    {
        $run = $this->db->prepare(
            'SELECT * FROM payroll_runs WHERE company_id = ? AND period = ? AND status = "approved"
             ORDER BY run_no DESC LIMIT 1',
        );
        $run->execute([$companyId, $period]);
        $row = $run->fetch();

        if ($row === false) {
            return ['title' => 'General ledger journal', 'period' => $period, 'columns' => [], 'rows' => [], 'totals' => []];
        }

        $s = $this->finance->statutory((int) $row['id']);
        $accounts = [];

        foreach ($this->finance->glAccounts($companyId) as $account) {
            $accounts[(string) $account['code']] = $account;
        }

        $mappings = [];

        foreach ($this->finance->glMappings($companyId) as $mapping) {
            $mappings[(string) $mapping['pay_item_key']] = $mapping;
        }

        $employer = (int) $row['employer_nssf_cents'] + (int) $row['employer_housing_cents'] + (int) $row['employer_nita_cents'];
        $lines = [
            ['account' => $mappings['salary']['debit_account'] ?? '5000', 'description' => 'Gross salaries', 'debit_cents' => (int) $row['gross_cents'], 'credit_cents' => 0],
            ['account' => $mappings['employer_statutory']['debit_account'] ?? '5010', 'description' => 'Employer statutory contributions', 'debit_cents' => $employer, 'credit_cents' => 0],
            ['account' => $mappings['reimbursements']['debit_account'] ?? '5100', 'description' => 'Staff reimbursements', 'debit_cents' => (int) $row['reimbursements_cents'], 'credit_cents' => 0],
            ['account' => $mappings['net_pay']['credit_account'] ?? '2100', 'description' => 'Net pay payable', 'debit_cents' => 0, 'credit_cents' => (int) $row['net_cents']],
            ['account' => $mappings['paye']['credit_account'] ?? '2200', 'description' => 'PAYE payable', 'debit_cents' => 0, 'credit_cents' => $s['paye']],
            ['account' => $mappings['nssf']['credit_account'] ?? '2210', 'description' => 'NSSF payable', 'debit_cents' => 0, 'credit_cents' => $s['nssf']],
            ['account' => $mappings['shif']['credit_account'] ?? '2220', 'description' => 'SHIF payable', 'debit_cents' => 0, 'credit_cents' => $s['shif']],
            ['account' => $mappings['housing']['credit_account'] ?? '2230', 'description' => 'Housing Levy payable', 'debit_cents' => 0, 'credit_cents' => $s['housing']],
            ['account' => $mappings['nita']['credit_account'] ?? '2240', 'description' => 'NITA payable', 'debit_cents' => 0, 'credit_cents' => $s['nita']],
            ['account' => $mappings['loans']['credit_account'] ?? '1300', 'description' => 'Loan recoveries', 'debit_cents' => 0, 'credit_cents' => (int) $row['loan_deductions_cents']],
            ['account' => '2250', 'description' => 'Other staff deductions payable', 'debit_cents' => 0, 'credit_cents' => (int) $row['other_deductions_cents']],
        ];

        foreach ($lines as &$line) {
            $line['account_name'] = $accounts[$line['account']]['name'] ?? 'Unmapped account';
        }

        unset($line);

        return [
            'title' => 'General ledger journal',
            'period' => $period,
            'columns' => [
                ['key' => 'account', 'label' => 'Account', 'type' => 'text'],
                ['key' => 'account_name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
                ['key' => 'debit_cents', 'label' => 'Debit', 'type' => 'money'],
                ['key' => 'credit_cents', 'label' => 'Credit', 'type' => 'money'],
            ],
            'rows' => $lines,
            'totals' => [
                'debit_cents' => array_sum(array_column($lines, 'debit_cents')),
                'credit_cents' => array_sum(array_column($lines, 'credit_cents')),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function returnPayload(int $companyId, array $run, string $type): array
    {
        $lines = $type === 'p9a'
            ? $this->returns->linesForYear($companyId, substr((string) $run['period'], 0, 4))
            : $this->returns->linesForRun((int) $run['id']);

        $rows = [];

        foreach ($lines as $line) {
            $amount = match ($type) {
                'p10', 'paye' => (int) $line['paye_cents'],
                'nssf' => (int) $line['nssf_employee_cents'] + (int) $line['nssf_employer_cents'],
                'shif' => (int) $line['shif_cents'],
                'housing' => (int) $line['housing_levy_employee_cents'] + (int) $line['housing_levy_employer_cents'],
                'nita' => (int) $line['nita_cents'],
                'p9a' => (int) $line['paye_cents'],
                default => 0,
            };

            $rows[] = [
                'employee_no' => $line['employee_no'],
                'employee_name' => $line['employee_name'],
                'kra_pin' => $line['kra_pin'] ?? '',
                'nssf_no' => $line['nssf_no'] ?? '',
                'shif_no' => $line['sha_no'] ?? '',
                'period' => $line['period'] ?? $run['period'],
                'gross_cents' => (int) $line['gross_cents'],
                'taxable_cents' => (int) $line['taxable_cents'],
                'amount_cents' => $amount,
            ];
        }

        return [
            'type' => $type,
            'run_id' => (int) $run['id'],
            'period' => $run['period'],
            'rows' => $rows,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $keys
     * @return array<string,int>
     */
    private function totals(array $rows, array $keys): array
    {
        $totals = [];

        foreach ($keys as $key) {
            $totals[$key] = 0;

            foreach ($rows as $row) {
                $totals[$key] += (int) ($row[$key] ?? 0);
            }
        }

        return $totals;
    }

    private function period(string $period): string
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new HttpException(422, 'Choose a valid month.');
        }

        return $period;
    }

    private function date(string $value, string $label, bool $required): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return $required ? date('Y-m-d') : null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false) {
            throw new HttpException(422, $label . ' is not a valid date.');
        }

        return $date->format('Y-m-d');
    }
}
