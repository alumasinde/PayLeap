<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Leave policy and the approval decision. A request moves once; a restricted
 * type is only approved for the gender it applies to, and an employee without
 * a recorded gender must be completed first.
 */
final class LeaveService
{
    private const GENDERS = ['any', 'female', 'male'];

    public function __construct(
        private readonly PDO $db,
        private readonly LeaveRepository $requests,
        private readonly EmployeeRepository $employees,
        private readonly AuditLogger $auditLogger,
        private readonly LeaveLedgerRepository $ledger,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveAccrualService $accrual,
        private readonly LeaveSettingsRepository $settings,
        private readonly LeaveApprovalRepository $approvals,
    ) {
    }

    /**
     * What an approver may see. Company approvers see everything; team
     * approvers see their direct reports and their own department.
     *
     * @return array{all:bool,team:bool,employee_id:?int,department_id:?int}
     */
    public function scopeFor(int $companyId, int $userId, bool $canAll, bool $canTeam): array
    {
        $actor = $this->employees->forUser($companyId, $userId);

        return [
            'all' => $canAll,
            'team' => $canTeam,
            'employee_id' => $actor === null ? null : (int) $actor['id'],
            'department_id' => $actor === null || $actor['department_id'] === null
                ? null
                : (int) $actor['department_id'],
        ];
    }

    public function decide(
        int $companyId,
        int $requestId,
        string $decision,
        int $actorId,
        string $note = '',
        bool $canAll = false,
        bool $canTeam = false,
    ): void {
        if (!$canAll && !$canTeam) {
            throw new HttpException(403, 'Your role cannot decide leave.');
        }

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException(422, 'Choose approve or reject.');
        }

        $request = $this->requests->find($companyId, $requestId);

        if ($request === null) {
            throw new HttpException(404, 'That leave request does not exist.');
        }

        $this->requireScope($companyId, $request, $actorId, $canAll, $canTeam);

        if ($request['status'] !== 'pending') {
            throw new HttpException(409, 'That leave request has already been decided.');
        }

        if ($decision === 'approved') {
            $this->requireEligible($request);
        }

        $actor = $this->employees->forUser($companyId, $actorId);
        $actorEmployeeId = $actor === null ? null : (int) $actor['id'];
        $stepNo = max(1, (int) ($request['current_step'] ?? 1));
        $totalSteps = max(1, (int) ($request['total_steps'] ?? 1));
        $step = $this->approvals->current($companyId, $requestId, $stepNo);

        if ($step !== null) {
            if (!$this->approvals->actorCanDecide($step, $actorEmployeeId, $canAll)) {
                throw new HttpException(403, 'This approval step belongs to another approver.');
            }
        }

        $note = trim($note);

        if (mb_strlen($note) > 255) {
            throw new HttpException(422, 'The decision note is too long.');
        }

        // A decision is a write request, so it may safely ensure the employee's
        // accrual batches exist before the FIFO allocation. A GET never does.
        $this->accrual->postEmployeeThrough(
            $companyId,
            (int) $request['employee_id'],
            date('Y-m-d'),
            $actorId,
        );

        $this->accrual->postEmployeeThrough(
            $companyId,
            (int) $request['employee_id'],
            (string) $request['end_date'],
            $actorId,
            true,
        );

        try {
            $this->db->beginTransaction();

            $finalApproval = $decision === 'approved' && ($step === null || $stepNo >= $totalSteps);

            if ($finalApproval) {
                $this->ledger->allocateRequest(
                    $companyId,
                    $requestId,
                    (int) $request['employee_id'],
                    (int) $request['leave_type_id'],
                    (float) $request['days'],
                    date('Y-m-d'),
                    $actorId,
                );
            }

            if ($step !== null) {
                $this->approvals->decide(
                    $companyId,
                    $requestId,
                    $stepNo,
                    $decision,
                    $actorId,
                    $note === '' ? null : $note,
                );

                if ($decision === 'approved' && $stepNo < $totalSteps) {
                    $this->requests->setApprovalProgress($companyId, $requestId, $stepNo + 1, $totalSteps);
                } elseif (!$this->requests->decide($companyId, $requestId, $decision, $actorId, $note === '' ? null : $note)) {
                    throw new HttpException(409, 'That leave request has already been decided.');
                }
            } elseif (!$this->requests->decide($companyId, $requestId, $decision, $actorId, $note === '' ? null : $note)) {
                throw new HttpException(409, 'That leave request has already been decided.');
            }

            $this->db->commit();
        } catch (RuntimeException $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw new HttpException(422, 'There are not enough non-expired leave days to approve this request.');
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $error;
        }

        $this->auditLogger->record(
            $companyId,
            $actorId,
            'leave.request.' . $decision,
            'leave_request',
            (string) $requestId,
            [
                'employee_id' => (int) $request['employee_id'],
                'days' => (float) $request['days'],
                'step' => $stepNo,
                'total_steps' => $totalSteps,
            ],
        );
    }

    /**
     * Cancel a pending or approved request. An approved request reverses the
     * exact ledger batches it consumed; a pending request has no allocation.
     */
    public function cancel(
        int $companyId,
        int $requestId,
        int $actorId,
        bool $canAll = false,
        bool $canTeam = false,
    ): void {
        $request = $this->requests->find($companyId, $requestId);

        if ($request === null) {
            throw new HttpException(404, 'That leave request does not exist.');
        }

        if (!in_array((string) $request['status'], ['pending', 'approved'], true)) {
            throw new HttpException(409, 'That leave request cannot be cancelled.');
        }

        $actor = $this->employees->forUser($companyId, $actorId);
        $owns = $actor !== null && (int) $request['employee_id'] === (int) $actor['id'];

        if (!$owns) {
            if (!$canAll && !$canTeam) {
                throw new HttpException(403, 'Your role cannot cancel this leave request.');
            }

            if (!$canAll) {
                $this->requireScope($companyId, $request, $actorId, false, true);
            }
        }

        try {
            $this->db->beginTransaction();

            if ((string) $request['status'] === 'approved') {
                $reversed = $this->ledger->reverseRequest($companyId, $requestId, date('Y-m-d'), $actorId);

                if ($reversed === 0) {
                    $this->ledger->restoreLegacyRequest(
                        $companyId,
                        $requestId,
                        (int) $request['employee_id'],
                        (int) $request['leave_type_id'],
                        (float) $request['days'],
                        date('Y-m-d'),
                        $actorId,
                    );
                }
            }

            if (!$this->requests->cancel($companyId, $requestId, $actorId)) {
                throw new HttpException(409, 'That leave request cannot be cancelled.');
            }

            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $error;
        }

        $this->auditLogger->record(
            $companyId,
            $actorId,
            'leave.request.cancelled',
            'leave_request',
            (string) $requestId,
            ['employee_id' => (int) $request['employee_id'], 'days' => (float) $request['days']],
        );
    }

    /**
     * An employee raises their own request. The type must be active and suit
     * them, the range must be free of other pending or approved leave, and the
     * days asked for must fit what has accrued by the leave start date.
     */
    public function submit(
        int $companyId,
        int $employeeId,
        int $typeId,
        string $startDate,
        string $endDate,
        string $reason,
        int $actorId,
    ): int {
        $employee = $this->employees->find($companyId, $employeeId);

        if ($employee === null || $employee['status'] !== 'active') {
            throw new HttpException(403, 'Your employee record is not active.');
        }

        $type = $this->requests->findType($companyId, $typeId);

        if ($type === null || (int) $type['active'] !== 1) {
            throw new HttpException(422, 'Choose a leave type that is currently available.');
        }

        $start = $this->date($startDate, 'start date');
        $end = $this->date($endDate, 'end date');

        if ($end < $start) {
            throw new HttpException(422, 'The end date cannot be before the start date.');
        }

        if ($start < new \DateTimeImmutable('today')) {
            throw new HttpException(422, 'Leave cannot start in the past.');
        }

        $days = ($type['day_basis'] ?? 'working') === 'calendar'
            ? (int) $start->diff($end)->days + 1
            : $this->workingDays($companyId, $start, $end);

        if ($days === 0) {
            throw new HttpException(422, 'Choose a date range with at least one working day.');
        }

        if ($days > 366) {
            throw new HttpException(422, 'A request cannot cover more than 366 days.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) > 255) {
            throw new HttpException(422, 'Keep the reason to 255 characters.');
        }

        $restriction = (string) $type['gender_restriction'];
        $gender = (string) ($employee['gender'] ?? '');

        if ($restriction !== 'any' && $gender !== $restriction) {
            if ($gender === '') {
                throw new HttpException(422, 'Ask HR to record your gender before applying for ' . $type['name'] . '.');
            }

            throw new HttpException(422, $type['name'] . ' is only available to ' . $restriction . ' employees.');
        }

        if ($this->requests->overlaps($companyId, $employeeId, $start->format('Y-m-d'), $end->format('Y-m-d'))) {
            throw new HttpException(422, 'You already have leave covering one of those days.');
        }

        // A POST may safely bring the ledger up to date. GET requests never do.
        $this->accrual->postEmployeeThrough($companyId, $employeeId, date('Y-m-d'), $actorId);

        if (($type['accrual_rule'] ?? '') === 'fixed' && $end > new \DateTimeImmutable('today')) {
            $this->accrual->postEmployeeThrough(
                $companyId,
                $employeeId,
                $end->format('Y-m-d'),
                $actorId,
                true,
            );
        }

        $balance = null;

        foreach ($this->balances->balances(
            $companyId,
            $employeeId,
            (int) $start->format('Y'),
            $start->format('Y-m-d'),
        ) as $row) {
            if ((int) $row['id'] === $typeId) {
                $balance = $row;
                break;
            }
        }

        if ($balance === null) {
            throw new HttpException(422, 'That leave type is not available to you.');
        }

        $available = (float) $balance['available'];

        if ($days > $available + 0.0001) {
            throw new HttpException(
                422,
                'That is more than your accrued balance of ' . self::days(max(0.0, $available)) . ' day(s) for ' . $type['name'] . '.',
            );
        }

        $requestId = $this->requests->createRequest($companyId, $employeeId, $typeId, [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'days' => $days,
            'reason' => $reason === '' ? null : $reason,
        ]);

        $steps = $this->approvals->start(
            $companyId,
            $requestId,
            $this->settings->forCompany($companyId),
            $this->employees->managerFor($companyId, $employeeId),
        );

        if ($steps > 1) {
            $this->requests->setApprovalProgress($companyId, $requestId, 1, $steps);
        }

        $this->auditLogger->record($companyId, $actorId, 'leave.request.submitted', 'leave_request', (string) $requestId, [
            'employee_id' => $employeeId,
            'leave_type_id' => $typeId,
            'days' => $days,
        ]);

        return $requestId;
    }

    /** @param array<string,mixed> $input */
    public function saveType(int $companyId, array $input, int $actorId): int
    {
        $typeId = (int) ($input['id'] ?? 0);
        $existing = null;

        if ($typeId > 0) {
            $existing = $this->requests->findType($companyId, $typeId);

            if ($existing === null) {
                throw new HttpException(404, 'That leave type does not exist.');
            }
        }

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 80) {
            throw new HttpException(422, 'Give the leave type a name of 1 to 80 characters.');
        }

        if ($this->requests->typeNameExists($companyId, $name, $typeId > 0 ? $typeId : null)) {
            throw new HttpException(422, 'A leave type with that name already exists.');
        }

        $days = $this->decimal($input['days_per_year'] ?? null, 'Days per year');
        $carry = $this->decimal($input['carry_over_max'] ?? null, 'Carry-over limit');
        $gender = (string) ($input['gender_restriction'] ?? 'any');
        $accrual = (string) ($input['accrual_rule'] ?? 'fixed');
        $basis = (string) ($input['day_basis'] ?? 'working');
        $waiting = (int) ($input['accrual_start_months'] ?? 0);
        $systemCode = trim((string) ($input['system_code'] ?? ''));
        $expiryMonths = (int) ($input['expiry_months'] ?? ($existing['expiry_months'] ?? 18));
        $dailyPercent = round((float) ($input['daily_pay_percent'] ?? ($existing['daily_pay_percent'] ?? 100)), 2);

        if ($systemCode === '' && $existing !== null) {
            $systemCode = (string) ($existing['system_code'] ?? '');
        }

        if (!in_array($gender, self::GENDERS, true)) {
            throw new HttpException(422, 'Choose who may take this leave type.');
        }

        if (!in_array($accrual, ['monthly', 'fixed'], true)) {
            throw new HttpException(422, 'Choose how this leave type accrues.');
        }

        if ($waiting < 0 || $waiting > 24) {
            throw new HttpException(422, 'The qualifying period must be between 0 and 24 months.');
        }

        if (!in_array($basis, ['working', 'calendar'], true)) {
            throw new HttpException(422, 'Choose whether this leave counts working or calendar days.');
        }

        if ($systemCode !== '' && preg_match('/^[a-z][a-z0-9_]{1,39}$/', $systemCode) !== 1) {
            throw new HttpException(422, 'The system code must be lowercase letters, numbers or underscores.');
        }

        if ($expiryMonths < 0 || $expiryMonths > 60) {
            throw new HttpException(422, 'The expiry window must be between 0 and 60 months.');
        }

        if ($dailyPercent < 0 || $dailyPercent > 100) {
            throw new HttpException(422, 'The daily pay percentage must be between 0 and 100.');
        }

        $data = [
            'name' => $name,
            'days_per_year' => $days,
            'accrual_rule' => $accrual,
            'accrual_start_months' => $waiting,
            'day_basis' => $basis,
            'paid' => !empty($input['paid']) ? 1 : 0,
            'gender_restriction' => $gender,
            'requires_document' => !empty($input['requires_document']) ? 1 : 0,
            'carry_over_max' => $carry,
            'system_code' => $systemCode === '' ? null : $systemCode,
            'expiry_months' => $expiryMonths,
            'daily_pay_percent' => $dailyPercent,
            'active' => !empty($input['active']) ? 1 : 0,
        ];

        if ($typeId > 0) {
            $this->requests->updateType($companyId, $typeId, $data);
        } else {
            $typeId = $this->requests->createType($companyId, $data);
        }

        $this->auditLogger->record($companyId, $actorId, 'leave.type.saved', 'leave_type', (string) $typeId, [
            'name' => $name,
            'days_per_year' => $days,
            'accrual_rule' => $accrual,
            'accrual_start_months' => $waiting,
            'day_basis' => $basis,
            'gender_restriction' => $gender,
        ]);

        return $typeId;
    }

    public function setTypeActive(int $companyId, int $typeId, bool $active, int $actorId): void
    {
        if ($this->requests->findType($companyId, $typeId) === null) {
            throw new HttpException(404, 'That leave type does not exist.');
        }

        $this->requests->setTypeActive($companyId, $typeId, $active);

        $this->auditLogger->record($companyId, $actorId, 'leave.type.' . ($active ? 'enabled' : 'disabled'), 'leave_type', (string) $typeId);
    }

    /**
     * Nobody decides their own request. A team approver is limited to direct
     * reports and their own department; a company approver covers the rest.
     *
     * @param array<string,mixed> $request
     */
    private function requireScope(int $companyId, array $request, int $actorId, bool $canAll, bool $canTeam): void
    {
        $actor = $this->employees->forUser($companyId, $actorId);
        $actorEmployeeId = $actor === null ? null : (int) $actor['id'];

        if ($actorEmployeeId !== null && (int) $request['employee_id'] === $actorEmployeeId) {
            throw new HttpException(403, 'You cannot decide your own leave request.');
        }

        if ($canAll) {
            return;
        }

        if (!$canTeam) {
            throw new HttpException(403, 'Your role cannot decide leave.');
        }

        if ($actorEmployeeId === null) {
            throw new HttpException(403, 'Link your login to an employee record before approving team leave.');
        }

        if ((int) ($actor['manager_id'] ?? 0) > 0
            && (int) $request['employee_id'] === (int) $actor['manager_id']) {
            throw new HttpException(403, 'Your manager\'s leave is approved above your level.');
        }

        $isManager = (int) ($request['employee_manager_id'] ?? 0) === $actorEmployeeId;
        $sameDepartment = $actor['department_id'] !== null
            && $request['employee_department_id'] !== null
            && (int) $request['employee_department_id'] === (int) $actor['department_id'];

        if (!$isManager && !$sameDepartment) {
            throw new HttpException(403, 'That leave request is outside your team.');
        }
    }

    /** @param array<string,mixed> $request */
    private function requireEligible(array $request): void
    {
        $restriction = (string) ($request['gender_restriction'] ?? 'any');

        if ($restriction === 'any') {
            return;
        }

        $type = (string) ($request['leave_type_name'] ?? 'This leave type');
        $gender = (string) ($request['employee_gender'] ?? '');

        if ($gender === '') {
            throw new HttpException(422, 'Set ' . $request['first_name'] . '\'s gender before approving ' . $type . '.');
        }

        if ($gender !== $restriction) {
            throw new HttpException(422, $type . ' is only available to ' . $restriction . ' employees.');
        }
    }

    private function date(string $value, string $label): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'Choose a valid ' . $label . '.');
        }

        return $date;
    }

    /** Calendar days minus weekends and company public holidays. */
    private function workingDays(int $companyId, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $holidays = $this->requests->publicHolidays(
            $companyId,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        );
        $days = 0;
        $cursor = $start;

        while ($cursor <= $end) {
            if ((int) $cursor->format('N') <= 5 && !isset($holidays[$cursor->format('Y-m-d')])) {
                $days++;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    private static function days(float $days): string
    {
        $formatted = number_format($days, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function decimal(mixed $value, string $label): float
    {
        if (!is_numeric($value)) {
            throw new HttpException(422, $label . ' must be a number.');
        }

        $number = round((float) $value, 2);

        if ($number < 0 || $number > 366) {
            throw new HttpException(422, $label . ' must be between 0 and 366.');
        }

        return $number;
    }
}
