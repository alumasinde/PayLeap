<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;

/**
 * Leave policy and the approval decision. A request moves once; a restricted
 * type is only approved for the gender it applies to, and an employee without
 * a recorded gender must be completed first.
 */
final class LeaveService
{
    private const GENDERS = ['any', 'female', 'male'];

    public function __construct(
        private readonly LeaveRepository $requests,
        private readonly EmployeeRepository $employees,
        private readonly AuditLogger $auditLogger,
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

        $note = trim($note);

        if (mb_strlen($note) > 255) {
            throw new HttpException(422, 'The decision note is too long.');
        }

        if (!$this->requests->decide($companyId, $requestId, $decision, $actorId, $note === '' ? null : $note)) {
            throw new HttpException(409, 'That leave request has already been decided.');
        }

        $this->auditLogger->record(
            $companyId,
            $actorId,
            'leave.request.' . $decision,
            'leave_request',
            (string) $requestId,
            ['employee_id' => (int) $request['employee_id'], 'days' => (float) $request['days']],
        );
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

        if (!in_array($gender, self::GENDERS, true)) {
            throw new HttpException(422, 'Choose who may take this leave type.');
        }

        $data = [
            'name' => $name,
            'days_per_year' => $days,
            'paid' => !empty($input['paid']) ? 1 : 0,
            'gender_restriction' => $gender,
            'requires_document' => !empty($input['requires_document']) ? 1 : 0,
            'carry_over_max' => $carry,
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
