<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Employees;

use DateTimeImmutable;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Money;
use PDO;

/**
 * Employee records: validation, employee numbers, archiving and the audit
 * trail. Amounts arrive as typed shillings and are stored as cents.
 */
final class EmployeeService
{
    public const TYPES = [
        'permanent' => 'Permanent',
        'contract' => 'Contract',
        'casual' => 'Casual',
        'intern' => 'Intern',
    ];

    private const NULLABLE = [
        'employee_no', 'middle_name', 'email', 'phone', 'national_id', 'kra_pin',
        'nssf_number', 'shif_number', 'bank_name', 'bank_branch', 'bank_account',
        'job_title',
    ];

    private const TEXT_FIELDS = [
        'employee_no' => 30,
        'first_name' => 80,
        'middle_name' => 80,
        'last_name' => 80,
        'email' => 190,
        'phone' => 40,
        'national_id' => 30,
        'kra_pin' => 20,
        'nssf_number' => 30,
        'shif_number' => 30,
        'bank_name' => 80,
        'bank_branch' => 80,
        'bank_account' => 40,
        'job_title' => 80,
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly EmployeeRepository $employees,
        private readonly DepartmentRepository $departments,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function create(int $companyId, array $input, int $actorId): int
    {
        $data = $this->validate($companyId, $input, true);

        $data['employee_no'] = $this->number($companyId, $this->string($data['employee_no'] ?? null));

        if ($this->employees->exists($companyId, (string) $data['employee_no'])) {
            throw new HttpException(422, 'That employee number is already in use.');
        }

        $data['employment_type'] ??= 'permanent';
        $data['status'] = 'active';

        $this->checkDates($data['start_date'], $data['exit_date'] ?? null);

        $employeeId = $this->employees->create($companyId, $data);

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

        $data = $this->validate($companyId, $input, false);

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

        $this->employees->update($companyId, $employeeId, $data);

        $this->auditLogger->record($companyId, $actorId, 'employee.updated', 'employee', (string) $employeeId, [
            'fields' => array_keys($data),
        ]);
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
        ]);

        $this->auditLogger->record($companyId, $actorId, 'employee.reinstated', 'employee', (string) $employeeId, [
            'employee_no' => $existing['employee_no'],
        ]);
    }

    public function addDepartment(int $companyId, string $name, int $actorId): int
    {
        $name = trim($name);

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
    private function validate(int $companyId, array $input, bool $creating): array
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

        if (array_key_exists('employment_type', $input)) {
            $type = (string) $input['employment_type'];

            if (!isset(self::TYPES[$type])) {
                throw new HttpException(422, 'Choose a valid employment type.');
            }

            $data['employment_type'] = $type;
        }

        if (array_key_exists('monthly_salary', $input)) {
            $raw = trim((string) $input['monthly_salary']);

            if ($raw === '') {
                $data['monthly_salary_cents'] = null;
            } else {
                $cents = Money::toCents($raw);

                if ($cents === null) {
                    throw new HttpException(422, 'Enter the salary as an amount, for example 45000.00.');
                }

                $data['monthly_salary_cents'] = $cents;
            }
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
