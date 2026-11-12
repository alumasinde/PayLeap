<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use PDO;

/** Explicit approval steps for a request that needs more than one decision. */
final class LeaveApprovalRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed>|null $manager
     */
    public function start(int $companyId, int $requestId, array $settings, ?array $manager): int
    {
        if ((int) ($settings['multi_level_approval'] ?? 0) !== 1) {
            return 1;
        }

        $steps = [];

        if ($manager !== null && (int) $manager['user_id'] > 0) {
            $steps[] = ['employee_id' => (int) $manager['id'], 'permission' => null];
        }

        $steps[] = ['employee_id' => null, 'permission' => 'leave.approve.all'];

        if (count($steps) < 2) {
            return 1;
        }

        $statement = $this->db->prepare(
            'INSERT INTO leave_approval_steps
                (company_id, request_id, step_no, approver_employee_id, approver_permission, status)
             VALUES (?, ?, ?, ?, ?, ?)',
        );

        foreach ($steps as $index => $step) {
            $statement->execute([
                $companyId,
                $requestId,
                $index + 1,
                $step['employee_id'],
                $step['permission'],
                'pending',
            ]);
        }

        return count($steps);
    }

    /** @return list<array<string,mixed>> */
    public function steps(int $companyId, int $requestId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_approval_steps
             WHERE company_id = ? AND request_id = ? ORDER BY step_no',
        );
        $statement->execute([$companyId, $requestId]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function current(int $companyId, int $requestId, int $stepNo): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_approval_steps
             WHERE company_id = ? AND request_id = ? AND step_no = ? LIMIT 1',
        );
        $statement->execute([$companyId, $requestId, $stepNo]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $step */
    public function actorCanDecide(array $step, ?int $actorEmployeeId, bool $canAll): bool
    {
        if ($step['approver_employee_id'] !== null) {
            return $actorEmployeeId !== null && (int) $step['approver_employee_id'] === $actorEmployeeId;
        }

        return $canAll && (string) ($step['approver_permission'] ?? '') === 'leave.approve.all';
    }

    public function decide(int $companyId, int $requestId, int $stepNo, string $decision, int $actorId, ?string $note): void
    {
        $this->db->prepare(
            'UPDATE leave_approval_steps
             SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), note = ?
             WHERE company_id = ? AND request_id = ? AND step_no = ? AND status = ?',
        )->execute([$decision, $actorId, $note, $companyId, $requestId, $stepNo, 'pending']);
    }
}
