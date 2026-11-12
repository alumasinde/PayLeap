<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

use PDO;
use Throwable;

/**
 * Payment batches are created from an approved run and released as a separate
 * control step. The batch snapshots the payment destination so later changes
 * to an employee's bank account cannot alter an already-prepared file.
 */
final class PaymentRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function runsAwaitingPayment(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, rv.label AS rate_label
             FROM payroll_runs r
             JOIN rate_versions rv ON rv.id = r.rate_version_id
             WHERE r.company_id = ? AND r.status = "approved" AND r.payment_status = "unpaid"
               AND NOT EXISTS (
                   SELECT 1 FROM payment_batches b
                   WHERE b.company_id = r.company_id AND b.run_id = r.id
                     AND b.status <> \'cancelled\'
               )
             ORDER BY r.period DESC, r.run_no, r.id',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function batches(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT b.*, r.period, r.label AS run_label, r.run_type,
                    p.first_name AS prepared_first, p.last_name AS prepared_last,
                    u.first_name AS released_first, u.last_name AS released_last
             FROM payment_batches b
             JOIN payroll_runs r ON r.id = b.run_id AND r.company_id = b.company_id
             LEFT JOIN users p ON p.id = b.prepared_by
             LEFT JOIN users u ON u.id = b.released_by
             WHERE b.company_id = ?
             ORDER BY b.created_at DESC, b.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function find(int $companyId, int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT b.*, r.period, r.label AS run_label, r.run_type, r.status AS run_status,
                    p.first_name AS prepared_first, p.last_name AS prepared_last,
                    u.first_name AS released_first, u.last_name AS released_last
             FROM payment_batches b
             JOIN payroll_runs r ON r.id = b.run_id AND r.company_id = b.company_id
             LEFT JOIN users p ON p.id = b.prepared_by
             LEFT JOIN users u ON u.id = b.released_by
             WHERE b.id = ? AND b.company_id = ? LIMIT 1',
        );
        $statement->execute([$id, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $companyId, int $batchId): array
    {
        $statement = $this->db->prepare(
            'SELECT b.*, e.employee_no, e.first_name, e.last_name
             FROM payment_batch_lines b
             JOIN employees e ON e.id = b.employee_id AND e.company_id = b.company_id
             WHERE b.batch_id = ? AND b.company_id = ?
             ORDER BY b.id',
        );
        $statement->execute([$batchId, $companyId]);

        return $statement->fetchAll();
    }

    public function existingForRun(int $companyId, int $runId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM payment_batches
             WHERE company_id = ? AND run_id = ? AND status <> \'cancelled\'
             LIMIT 1',
        );
        $statement->execute([$companyId, $runId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function referenceExists(int $companyId, string $reference): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM payment_batches WHERE company_id = ? AND reference = ? LIMIT 1',
        );
        $statement->execute([$companyId, $reference]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function missingDetails(int $companyId, int $runId, string $method): array
    {
        $condition = $method === 'mpesa'
            ? '(b.mpesa_no IS NULL OR b.mpesa_no = "")'
            : '(bn.bank_name IS NULL OR bn.bank_name = "" OR b.account_no IS NULL OR b.account_no = "")';

        $statement = $this->db->prepare(
            'SELECT l.id, l.employee_no, l.employee_name, b.account_no, b.mpesa_no, bn.bank_name
             FROM payroll_lines l
             LEFT JOIN employee_bank_accounts b ON b.id = (
                 SELECT b2.id FROM employee_bank_accounts b2
                 WHERE b2.employee_id = l.employee_id AND b2.company_id = ?
                 ORDER BY b2.is_primary DESC, b2.id LIMIT 1
             )
             LEFT JOIN bank_names bn ON bn.id = b.bank_id
             WHERE l.run_id = ? AND l.net_cents > 0 AND ' . $condition,
        );
        $statement->execute([$companyId, $runId]);

        return $statement->fetchAll();
    }

    /**
     * Build a batch from a run. Returns the batch id; validation is done by the
     * finance service before this method is called.
     */
    public function create(int $companyId, int $runId, string $method, string $reference, int $actorId): int
    {
        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $lines = $this->db->prepare(
                'SELECT l.*, b.account_no, b.mpesa_no, bn.bank_name, bb.branch AS bank_branch
                 FROM payroll_lines l
                 LEFT JOIN employee_bank_accounts b ON b.id = (
                     SELECT b2.id FROM employee_bank_accounts b2
                     WHERE b2.employee_id = l.employee_id AND b2.company_id = ?
                     ORDER BY b2.is_primary DESC, b2.id LIMIT 1
                 )
                 LEFT JOIN bank_names bn ON bn.id = b.bank_id
                 LEFT JOIN bank_branches bb ON bb.id = b.branch_id
                 WHERE l.run_id = ? AND l.net_cents > 0
                 ORDER BY l.employee_name',
            );
            $lines->execute([$companyId, $runId]);
            $rows = $lines->fetchAll();

            $total = 0;

            foreach ($rows as $row) {
                $total += (int) $row['net_cents'];
            }

            $this->db->prepare(
                'INSERT INTO payment_batches
                    (company_id, run_id, reference, method, total_cents, status, prepared_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([$companyId, $runId, $reference, $method, $total, 'prepared', $actorId]);
            $batchId = (int) $this->db->lastInsertId();

            $insert = $this->db->prepare(
                'INSERT INTO payment_batch_lines
                    (company_id, batch_id, line_id, employee_id, amount_cents, bank_name,
                     bank_branch, account_no, mpesa_no, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );

            foreach ($rows as $row) {
                $insert->execute([
                    $companyId,
                    $batchId,
                    (int) $row['id'],
                    (int) $row['employee_id'],
                    (int) $row['net_cents'],
                    $row['bank_name'],
                    $row['bank_branch'],
                    $row['account_no'],
                    $row['mpesa_no'],
                    'pending',
                ]);
            }

            if ($own) {
                $this->db->commit();
            }

            return $batchId;
        } catch (Throwable $error) {
            if ($own) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    /** Void a prepared batch without changing the run, so it can be re-prepared. */
    public function cancel(int $companyId, int $batchId, int $actorId): bool
    {
        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $statement = $this->db->prepare(
                'UPDATE payment_batches
                 SET status = \'cancelled\'
                 WHERE id = ? AND company_id = ? AND status = \'prepared\'',
            );
            $statement->execute([$batchId, $companyId]);

            if ($statement->rowCount() !== 1) {
                if ($own) {
                    $this->db->rollBack();
                }

                return false;
            }

            $this->db->prepare(
                'UPDATE payment_batch_lines SET status = \'cancelled\' WHERE batch_id = ? AND company_id = ?',
            )->execute([$batchId, $companyId]);

            if ($own) {
                $this->db->commit();
            }

            return true;
        } catch (Throwable $error) {
            if ($own) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    /** Release a prepared batch and mark its run paid. */
    public function release(int $companyId, int $batchId, int $actorId): bool
    {
        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $statement = $this->db->prepare(
                'UPDATE payment_batches
                 SET status = "released", released_by = ?, released_at = UTC_TIMESTAMP()
                 WHERE id = ? AND company_id = ? AND status = "prepared"',
            );
            $statement->execute([$actorId, $batchId, $companyId]);

            if ($statement->rowCount() !== 1) {
                if ($own) {
                    $this->db->rollBack();
                }

                return false;
            }

            $batch = $this->find($companyId, $batchId);

            if ($batch === null) {
                if ($own) {
                    $this->db->rollBack();
                }

                return false;
            }

            $this->db->prepare('UPDATE payment_batch_lines SET status = "released" WHERE batch_id = ? AND company_id = ?')
                ->execute([$batchId, $companyId]);

            $this->db->prepare(
                'UPDATE payroll_runs
                 SET payment_status = "paid", paid_at = UTC_TIMESTAMP(), locked_at = COALESCE(locked_at, UTC_TIMESTAMP())
                 WHERE id = ? AND company_id = ? AND status = "approved"',
            )->execute([(int) $batch['run_id'], $companyId]);

            if ($own) {
                $this->db->commit();
            }

            return true;
        } catch (Throwable $error) {
            if ($own) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    /** @return array{count:int,total:int} */
    public function pendingSummary(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS count, COALESCE(SUM(total_cents), 0) AS total
             FROM payment_batches WHERE company_id = ? AND status = "prepared"',
        );
        $statement->execute([$companyId]);
        $row = $statement->fetch() ?: [];

        return ['count' => (int) ($row['count'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
    }
}
