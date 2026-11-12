<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Employees;

use DateTimeImmutable;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\FileStore;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Money;
use PDO;
use Throwable;

/**
 * Employee records: validation, employee numbers, archiving and the audit
 * trail. Amounts arrive as typed shillings and are stored as cents.
 */
final class EmployeeService
{
    public const TYPES = [
        'permanent' => 'Permanent',
        'fixed_term' => 'Fixed term',
        'casual' => 'Casual',
        'consultant' => 'Consultant',
        'intern' => 'Intern',
    ];

    public const PAY_BASIS = [
        'monthly' => 'Monthly salary',
        'daily' => 'Daily rate',
        'hourly' => 'Hourly rate',
    ];

    public const GENDERS = [
        'female' => 'Female',
        'male' => 'Male',
    ];

    public const MARITAL_STATUSES = [
        'single' => 'Single',
        'married' => 'Married',
        'divorced' => 'Divorced',
        'widowed' => 'Widowed',
        'separated' => 'Separated',
    ];

    private const NULLABLE = [
        'employee_no', 'middle_name', 'gender', 'email', 'phone', 'national_id', 'address',
        'marital_status', 'nationality', 'cost_center', 'photo_path',
        'kra_pin', 'nssf_number', 'shif_number', 'bank_name', 'bank_branch',
        'bank_account', 'mpesa_no', 'job_title',
    ];

    private const TEXT_FIELDS = [
        'employee_no' => 30,
        'first_name' => 80,
        'middle_name' => 80,
        'last_name' => 80,
        'gender' => 10,
        'email' => 190,
        'phone' => 40,
        'national_id' => 30,
        'address' => 255,
        'marital_status' => 20,
        'nationality' => 80,
        'cost_center' => 80,
        'photo_path' => 255,
        'kra_pin' => 20,
        'nssf_number' => 30,
        'shif_number' => 30,
        'bank_name' => 80,
        'bank_branch' => 80,
        'bank_account' => 40,
        'mpesa_no' => 40,
        'job_title' => 80,
    ];

    private const PHOTO_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const PHOTO_MAX_BYTES = 2097152;

    /** @var array<string,array{0:string,1:int}> contact input => [column, max length] */
    private const NEXT_OF_KIN = [
        'next_of_kin_name' => ['name', 120],
        'next_of_kin_relationship' => ['relationship', 40],
        'next_of_kin_phone' => ['phone', 40],
        'next_of_kin_email' => ['email', 190],
        'next_of_kin_address' => ['address', 255],
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly EmployeeRepository $employees,
        private readonly DepartmentRepository $departments,
        private readonly AuditLogger $auditLogger,
        private readonly FileStore $files,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function create(int $companyId, array $input, int $actorId): int
    {
        $data = $this->validate($companyId, $input, true);
        $photo = $this->photoChange($input, null);

        if ($photo['changed']) {
            $data['photo_path'] = $photo['path'];
        }

        $data['employee_no'] = $this->number($companyId, $this->string($data['employee_no'] ?? null));

        if ($this->employees->exists($companyId, (string) $data['employee_no'])) {
            throw new HttpException(422, 'That employee number is already in use.');
        }

        $data['employment_type'] ??= 'permanent';
        $data['status'] = 'active';

        $this->checkDates($data['start_date'], $data['exit_date'] ?? null);

        try {
            $employeeId = $this->employees->create($companyId, $data);
        } catch (Throwable $error) {
            $this->files->delete($photo['stored']);
            throw $error;
        }

        $this->auditLogger->record($companyId, $actorId, 'employee.created', 'employee', (string) $employeeId, [
            'employee_no' => $data['employee_no'],
            'name' => trim($data['first_name'] . ' ' . $data['last_name']),
        ]);

        return $employeeId;
    }

    /** @param array<string,mixed> $input */
    public function update(int $companyId, int $employeeId, array $input, int $actorId): void
    {
        $existing = $this->employees->find($companyId, $employeeId);

        if ($existing === null) {
            throw new HttpException(404, 'That employee does not exist.');
        }

        $data = $this->validate($companyId, $input, false, $employeeId);
        $photo = $this->photoChange($input, $existing['photo_path'] ?? null);

        if ($photo['changed']) {
            $data['photo_path'] = $photo['path'];
        }

        if ($data === []) {
            return;
        }

        if (isset($data['employee_no'])) {
            if ($this->employees->exists($companyId, (string) $data['employee_no'], $employeeId)) {
                throw new HttpException(422, 'That employee number is already in use.');
            }
        }

        $startDate = (string) ($data['start_date'] ?? $existing['start_date']);
        $exitDate = array_key_exists('exit_date', $data) ? $data['exit_date'] : $existing['exit_date'];
        $this->checkDates($startDate, is_string($exitDate) ? $exitDate : null);

        try {
            $this->employees->update($companyId, $employeeId, $data);
        } catch (Throwable $error) {
            $this->files->delete($photo['stored']);
            throw $error;
        }

        if ($photo['changed'] && $photo['old'] !== null) {
            $this->files->delete($photo['old']);
        }

        $this->auditLogger->record($companyId, $actorId, 'employee.updated', 'employee', (string) $employeeId, [
            'fields' => array_keys($data),
        ]);
    }

    /**
     * Set or clear the cost centre on a checked set of employees.
     *
     * @param list<int|string> $employeeIds
     */
    public function bulkCostCenter(int $companyId, array $employeeIds, string $costCenter, int $actorId): int
    {
        $costCenter = trim($costCenter);

        if (mb_strlen($costCenter) > 80) {
            throw new HttpException(422, 'Cost center is too long.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            throw new HttpException(422, 'Select at least one employee.');
        }

        $changed = $this->employees->setCostCenter($companyId, $ids, $costCenter === '' ? null : $costCenter);
        $this->auditLogger->record($companyId, $actorId, 'employee.cost_center_bulk', 'employee', null, [
            'employee_ids' => $ids,
            'cost_center' => $costCenter === '' ? null : $costCenter,
            'changed' => $changed,
        ]);

        return $changed;
    }

    public function archive(int $companyId, int $employeeId, string $exitDate, int $actorId): void
    {
        $existing = $this->employees->find($companyId, $employeeId);

        if ($existing === null) {
            throw new HttpException(404, 'That employee does not exist.');
        }

        if ($existing['status'] === 'exited') {
            throw new HttpException(409, 'That employee is already archived.');
        }

        $date = trim($exitDate) === '' ? date('Y-m-d') : $this->date($exitDate, 'Exit date');
        $this->checkDates((string) $existing['start_date'], $date);

        $this->employees->update($companyId, $employeeId, [
            'status' => 'exited',
            'exit_date' => $date,
            'archived_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditLogger->record($companyId, $actorId, 'employee.archived', 'employee', (string) $employeeId, [
            'employee_no' => $existing['employee_no'],
            'exit_date' => $date,
        ]);
    }

    public function reinstate(int $companyId, int $employeeId, int $actorId): void
    {
        $existing = $this->employees->find($companyId, $employeeId);

        if ($existing === null) {
            throw new HttpException(404, 'That employee does not exist.');
        }

        if ($existing['status'] === 'active') {
            throw new HttpException(409, 'That employee is already active.');
        }

        $this->employees->update($companyId, $employeeId, [
            'status' => 'active',
            'exit_date' => null,
            'archived_at' => null,
        ]);

        $this->auditLogger->record($companyId, $actorId, 'employee.reinstated', 'employee', (string) $employeeId, [
            'employee_no' => $existing['employee_no'],
        ]);
    }

    /** @param array<string,mixed> $input */
    public function addEducation(int $companyId, int $employeeId, array $input, int $actorId): int
    {
        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(404, 'That employee does not exist.');
        }

        $institution = is_scalar($input['institution'] ?? null) ? trim((string) $input['institution']) : '';

        if ($institution === '' || mb_strlen($institution) > 150) {
            throw new HttpException(422, 'Give the institution a name of 1 to 150 characters.');
        }

        $data = ['institution' => $institution];

        foreach ([
            'qualification' => 120,
            'field_of_study' => 120,
            'level' => 40,
            'grade' => 40,
        ] as $field => $max) {
            $value = is_scalar($input[$field] ?? null) ? trim((string) $input[$field]) : '';

            if (mb_strlen($value) > $max) {
                throw new HttpException(422, $this->label($field) . ' is too long.');
            }

            $data[$field] = $value === '' ? null : $value;
        }

        foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $field => $label) {
            $value = is_scalar($input[$field] ?? null) ? trim((string) $input[$field]) : '';
            $data[$field] = $value === '' ? null : $this->date($value, $label);
        }

        if ($data['start_date'] !== null && $data['end_date'] !== null && $data['end_date'] < $data['start_date']) {
            throw new HttpException(422, 'The end date cannot be before the start date.');
        }

        $educationId = $this->employees->addEducation($companyId, $employeeId, $data);

        $this->auditLogger->record(
            $companyId,
            $actorId,
            'employee.education.added',
            'employee',
            (string) $employeeId,
            ['institution' => $institution],
        );

        return $educationId;
    }

    public function removeEducation(int $companyId, int $employeeId, int $educationId, int $actorId): void
    {
        if (!$this->employees->deleteEducation($companyId, $employeeId, $educationId)) {
            throw new HttpException(404, 'That education record does not exist.');
        }

        $this->auditLogger->record(
            $companyId,
            $actorId,
            'employee.education.removed',
            'employee',
            (string) $employeeId,
            ['education_id' => $educationId],
        );
    }

    public function addDepartment(int $companyId, string $name, int $actorId): int
    {        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new HttpException(422, 'Give the department a name of 1 to 80 characters.');
        }

        if ($this->departments->exists($companyId, $name)) {
            throw new HttpException(422, 'That department already exists.');
        }

        $departmentId = $this->departments->create($companyId, $name);

        $this->auditLogger->record($companyId, $actorId, 'department.created', 'department', (string) $departmentId, [
            'name' => $name,
        ]);

        return $departmentId;
    }

    /**
     * Normalise only the keys that were sent, so a partial update never blanks
     * a field the caller could not see.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(int $companyId, array $input, bool $creating, ?int $employeeId = null): array
    {
        $data = [];

        foreach (self::TEXT_FIELDS as $field => $max) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = is_scalar($input[$field]) ? trim((string) $input[$field]) : '';

            if (mb_strlen($value) > $max) {
                throw new HttpException(422, $this->label($field) . ' is too long.');
            }

            $data[$field] = $value === '' && in_array($field, self::NULLABLE, true) ? null : $value;
        }

        if (($data['first_name'] ?? null) === '') {
            throw new HttpException(422, 'First name is required.');
        }

        if (($data['last_name'] ?? null) === '') {
            throw new HttpException(422, 'Last name is required.');
        }

        if (isset($data['employee_no'])) {
            $data['employee_no'] = strtoupper($data['employee_no']);

            if (preg_match('/^[A-Z0-9][A-Z0-9\/-]{0,29}$/', $data['employee_no']) !== 1) {
                throw new HttpException(422, 'Use letters, numbers, / or - in the employee number.');
            }
        }

        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);

            if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new HttpException(422, 'Enter a valid email address.');
            }
        }

        if (isset($data['national_id'])) {
            $data['national_id'] = (string) preg_replace('/\s+/', '', $data['national_id']);
        }

        if (isset($data['kra_pin'])) {
            $data['kra_pin'] = strtoupper(str_replace(' ', '', $data['kra_pin']));

            if (preg_match('/^[A-Z][0-9]{9}[A-Z]$/', $data['kra_pin']) !== 1) {
                throw new HttpException(422, 'A KRA PIN looks like A012345678Z.');
            }
        }

        if (array_key_exists('department_id', $input)) {
            $departmentId = (int) ($input['department_id'] ?? 0);

            if ($departmentId > 0) {
                if ($this->departments->find($companyId, $departmentId) === null) {
                    throw new HttpException(422, 'That department does not exist for this company.');
                }

                $data['department_id'] = $departmentId;
            } else {
                $data['department_id'] = null;
            }
        }

        if (array_key_exists('manager_id', $input)) {
            $managerId = (int) ($input['manager_id'] ?? 0);

            if ($managerId > 0) {
                $manager = $this->employees->find($companyId, $managerId);

                if ($manager === null || $manager['status'] !== 'active') {
                    throw new HttpException(422, 'Choose an active manager from this company.');
                }

                if ($employeeId !== null && $managerId === $employeeId) {
                    throw new HttpException(422, 'An employee cannot report to themselves.');
                }

                if ($employeeId !== null && (int) ($manager['manager_id'] ?? 0) === $employeeId) {
                    throw new HttpException(422, 'That reporting line would loop back to this employee.');
                }

                $data['manager_id'] = $managerId;
            } else {
                $data['manager_id'] = null;
            }
        }

        if (array_key_exists('user_id', $input)) {
            $userId = (int) ($input['user_id'] ?? 0);

            if ($userId > 0) {
                if (!$this->employees->userIsMember($companyId, $userId)) {
                    throw new HttpException(422, 'That login does not belong to this company.');
                }

                if ($this->employees->userLinkedToOther($companyId, $userId, $employeeId)) {
                    throw new HttpException(422, 'That login is already linked to another employee.');
                }

                $data['user_id'] = $userId;
            } else {
                $data['user_id'] = null;
            }
        }

        if (array_key_exists('employment_type', $input)) {
            $type = (string) $input['employment_type'];

            if (!isset(self::TYPES[$type])) {
                throw new HttpException(422, 'Choose a valid employment type.');
            }

            $data['employment_type'] = $type;
        }

        if (array_key_exists('pay_basis', $input)) {
            $basis = (string) $input['pay_basis'];

            if (!isset(self::PAY_BASIS[$basis])) {
                throw new HttpException(422, 'Choose monthly, daily or hourly pay.');
            }

            $data['pay_basis'] = $basis;
        }

        if (array_key_exists('gender', $input)) {
            $gender = is_scalar($input['gender']) ? trim((string) $input['gender']) : '';

            if ($gender !== '' && !isset(self::GENDERS[$gender])) {
                throw new HttpException(422, 'Choose a valid gender.');
            }

            $data['gender'] = $gender === '' ? null : $gender;
        }

        if (array_key_exists('marital_status', $input)) {
            $status = is_scalar($input['marital_status']) ? trim((string) $input['marital_status']) : '';

            if ($status !== '' && !isset(self::MARITAL_STATUSES[$status])) {
                throw new HttpException(422, 'Choose a valid marital status.');
            }

            $data['marital_status'] = $status === '' ? null : $status;
        }

        $contactTouched = false;
        $contact = [];

        foreach (self::NEXT_OF_KIN as $inputKey => [$column, $max]) {
            if (!array_key_exists($inputKey, $input)) {
                continue;
            }

            $contactTouched = true;
            $value = is_scalar($input[$inputKey]) ? trim((string) $input[$inputKey]) : '';

            if (mb_strlen($value) > $max) {
                throw new HttpException(422, $this->label($column) . ' is too long.');
            }

            $contact[$column] = $value === '' ? null : $value;
        }

        if ($contactTouched) {
            $existingContact = $employeeId === null ? null : $this->employees->nextOfKin($companyId, $employeeId);

            foreach (self::NEXT_OF_KIN as [$column]) {
                if (!array_key_exists($column, $contact)) {
                    $contact[$column] = $existingContact[$column] ?? null;
                }
            }

            if (($contact['email'] ?? null) !== null) {
                $contact['email'] = strtolower((string) $contact['email']);

                if (filter_var($contact['email'], FILTER_VALIDATE_EMAIL) === false) {
                    throw new HttpException(422, 'Enter a valid emergency contact email.');
                }
            }

            $hasAny = array_filter(
                $contact,
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ) !== [];

            if ($hasAny && (string) ($contact['name'] ?? '') === '') {
                throw new HttpException(422, 'Give the emergency contact a name.');
            }

            $data['next_of_kin'] = $contact;
        }

        if (array_key_exists('dob', $input)) {
            $raw = trim((string) $input['dob']);

            if ($raw === '') {
                $data['dob'] = null;
            } else {
                $dob = $this->date($raw, 'Date of birth');

                if ($dob > date('Y-m-d')) {
                    throw new HttpException(422, 'The date of birth cannot be in the future.');
                }

                $data['dob'] = $dob;
            }
        }

        if (array_key_exists('monthly_salary', $input)) {
            $raw = trim((string) $input['monthly_salary']);

            if ($raw === '') {
                $data['monthly_salary_cents'] = null;
            } else {
                $cents = Money::toCents($raw);

                if ($cents === null) {
                    throw new HttpException(422, 'Enter the pay rate as an amount, for example 45000.00.');
                }

                $data['monthly_salary_cents'] = $cents;
            }
        }

        if (array_key_exists('salary_effective_date', $input)) {
            $effective = trim((string) $input['salary_effective_date']);
            $data['salary_effective_date'] = $effective === '' ? date('Y-m-d') : $this->date($effective, 'Pay effective date');
        }

        if (array_key_exists('start_date', $input)) {
            $data['start_date'] = $this->date((string) $input['start_date'], 'Start date');
        }

        if (array_key_exists('exit_date', $input)) {
            $raw = trim((string) $input['exit_date']);
            $data['exit_date'] = $raw === '' ? null : $this->date($raw, 'Exit date');
        }

        if ($creating) {
            foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'start_date' => 'Start date'] as $field => $label) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    throw new HttpException(422, $label . ' is required.');
                }
            }
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{changed:bool,path:?string,old:?string,stored:?string}
     */
    private function photoChange(array $input, ?string $current): array
    {
        $file = $input['photo_file'] ?? null;

        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = $this->files->store($file, 'employees', self::PHOTO_MIMES, self::PHOTO_MAX_BYTES);

            return ['changed' => true, 'path' => $stored['path'], 'old' => $current, 'stored' => $stored['path']];
        }

        if (!empty($input['remove_photo']) && $current !== null) {
            return ['changed' => true, 'path' => null, 'old' => $current, 'stored' => null];
        }

        return ['changed' => false, 'path' => $current, 'old' => null, 'stored' => null];
    }

    private function number(int $companyId, string $requested): string
    {
        if ($requested !== '') {
            return $requested;
        }

        return $this->employees->nextNumber($companyId);
    }

    private function checkDates(string $startDate, ?string $exitDate): void
    {
        if ($exitDate !== null && $exitDate < $startDate) {
            throw new HttpException(422, 'The exit date cannot be before the start date.');
        }
    }

    private function date(string $value, string $label): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HttpException(422, $label . ' must be a date.');
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
