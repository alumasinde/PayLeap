<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

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
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function decide(int $companyId, int $requestId, string $decision, int $actorId, string $note = ''): void
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException(422, 'Choose approve or reject.');
        }

        $request = $this->requests->find($companyId, $requestId);

        if ($request === null) {
            throw new HttpException(404, 'That leave request does not exist.');
        }

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
