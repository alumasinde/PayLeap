<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Money;

/**
 * Finance screens: pay structures, payment batches, statutory returns,
 * reports, loans, claims and audit. Every action is permission-gated.
 */
final class FinanceController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $this->app->requireCompany();
        $this->app->redirect('/finance');
    }

    public function payItems(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');
        $companyId = (int) $company['company_id'];

        echo $this->app->render('app/finance/pay-items.twig', [
            'items' => $this->app->finance->payItems->all($companyId),
            'assignments' => $this->app->finance->payItems->assignments($companyId),
            'employees' => $this->app->employees->register->activeForSelect($companyId),
            'can_manage' => $this->app->can('payroll.run'),
        ]);
    }

    public function storePayItem(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');

        try {
            $this->app->finance->service->savePayItem(
                (int) $company['company_id'],
                (int) $this->app->request->input('id', 0) ?: null,
                $this->app->request->all(),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/pay-items');
        }

        $this->app->flash->add('success', 'Pay item saved.');
        $this->app->redirect('/app/finance/pay-items');
    }

    public function togglePayItem(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');
        $this->app->finance->service->togglePayItem((int) $company['company_id'], (int) ($params['id'] ?? 0), (int) $this->app->user['id']);
        $this->app->flash->add('success', 'Pay item updated.');
        $this->app->redirect('/app/finance/pay-items');
    }

    public function assignPayItem(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');

        try {
            $this->app->finance->service->assignPayItem((int) $company['company_id'], $this->app->request->all(), (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/pay-items');
        }

        $this->app->flash->add('success', 'Pay item assigned.');
        $this->app->redirect('/app/finance/pay-items');
    }

    public function removeAssignment(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');
        $this->app->finance->service->removePayItemAssignment((int) $company['company_id'], (int) ($params['id'] ?? 0), (int) $this->app->user['id']);
        $this->app->flash->add('success', 'Assignment removed.');
        $this->app->redirect('/app/finance/pay-items');
    }

    public function payments(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payment.release');
        $companyId = (int) $company['company_id'];

        echo $this->app->render('app/finance/payments.twig', [
            'runs' => $this->app->finance->payments->runsAwaitingPayment($companyId),
            'batches' => $this->app->finance->payments->batches($companyId),
            'pending' => $this->app->finance->payments->pendingSummary($companyId),
        ]);
    }

    public function createPayment(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payment.release');

        try {
            $batchId = $this->app->finance->service->createPayment(
                (int) $company['company_id'],
                (int) $this->app->request->input('run_id', 0),
                $this->app->request->string('method'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/payments');
        }

        $this->app->flash->add('success', 'Payment batch prepared. Review it before release.');
        $this->app->redirect('/app/finance/payments/' . $batchId);
    }

    public function showPayment(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payment.release');
        $companyId = (int) $company['company_id'];
        $batch = $this->app->finance->payments->find($companyId, (int) ($params['id'] ?? 0));

        if ($batch === null) {
            $this->app->abort(404, 'That payment batch does not exist.');
        }

        echo $this->app->render('app/finance/payment.twig', [
            'batch' => $batch,
            'lines' => $this->app->finance->payments->lines($companyId, (int) $batch['id']),
            'can_release' => $this->app->can('payment.release'),
        ]);
    }

    public function releasePayment(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payment.release');

        try {
            $this->app->finance->service->releasePayment((int) $company['company_id'], (int) ($params['id'] ?? 0), (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/payments/' . (int) ($params['id'] ?? 0));
        }

        $this->app->flash->add('success', 'Payment released and the run marked paid.');
        $this->app->redirect('/app/finance/payments/' . (int) ($params['id'] ?? 0));
    }

    public function downloadPayment(array $params): never
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.export');
        $companyId = (int) $company['company_id'];
        $batch = $this->app->finance->payments->find($companyId, (int) ($params['id'] ?? 0));

        if ($batch === null) {
            $this->app->abort(404, 'That payment batch does not exist.');
        }

        $filename = 'payment-' . $batch['reference'] . '.csv';
        $this->app->db->prepare('UPDATE payment_batches SET file_ref = ? WHERE id = ? AND company_id = ?')
            ->execute([$filename, (int) $batch['id'], $companyId]);
        $this->app->db->prepare(
            'INSERT INTO payroll_exports (company_id, run_id, type, file_ref, generated_by)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([$companyId, (int) $batch['run_id'], 'payment', $filename, (int) $this->app->user['id']]);
        $this->app->auditLogger->record($companyId, (int) $this->app->user['id'], 'payment.file_exported', 'payment_batch', (string) $batch['id'], ['method' => $batch['method']]);

        $rows = $this->app->finance->service->paymentFileRows($companyId, (int) $batch['id']);
        $this->csv($filename, ['Employee no.', 'Name', 'Amount', 'Bank', 'Branch', 'Account', 'M-Pesa', 'Reference'], $rows);
    }

    public function returns(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('returns.view');
        $companyId = (int) $company['company_id'];
        $runs = array_values(array_filter(
            $this->app->payroll->runs->listForCompany($companyId),
            static fn (array $run): bool => $run['status'] === 'approved',
        ));

        echo $this->app->render('app/finance/returns.twig', [
            'returns' => $this->app->finance->returns->all($companyId),
            'runs' => $runs,
            'types' => $this->app->finance->service->returnTypes(),
            'can_generate' => $this->app->can('returns.generate'),
        ]);
    }

    public function generateReturn(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('returns.generate');

        try {
            $this->app->finance->service->generateReturn(
                (int) $company['company_id'],
                (int) $this->app->request->input('run_id', 0),
                $this->app->request->string('type'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/returns');
        }

        $this->app->flash->add('success', 'Statutory return prepared.');
        $this->app->redirect('/app/finance/returns');
    }

    public function fileReturn(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('returns.generate');

        try {
            $this->app->finance->service->markReturnFiled(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                $this->app->request->string('reference'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/returns');
        }

        $this->app->flash->add('success', 'Return marked filed.');
        $this->app->redirect('/app/finance/returns');
    }

    public function downloadReturn(array $params): never
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('returns.view');
        $return = $this->app->finance->returns->find((int) $company['company_id'], (int) ($params['id'] ?? 0));

        if ($return === null) {
            $this->app->abort(404, 'That return does not exist.');
        }

        $payload = json_decode((string) $return['payload'], true);
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $this->csv('return-' . $return['type'] . '-' . $return['period'] . '.csv', [
            'Employee no.', 'Employee', 'KRA PIN', 'NSSF no.', 'SHIF no.', 'Period', 'Gross', 'Taxable', 'Amount',
        ], array_map(static fn (array $row): array => [
            $row['employee_no'] ?? '',
            $row['employee_name'] ?? '',
            $row['kra_pin'] ?? '',
            $row['nssf_no'] ?? '',
            $row['shif_no'] ?? '',
            $row['period'] ?? '',
            Money::format((int) ($row['gross_cents'] ?? 0)),
            Money::format((int) ($row['taxable_cents'] ?? 0)),
            Money::format((int) ($row['amount_cents'] ?? 0)),
        ], $rows));
    }

    public function reports(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('reports.view');
        $companyId = (int) $company['company_id'];
        $this->app->finance->repository->ensureGlDefaults($companyId);
        $period = (string) $this->app->request->query('period', date('Y-m'));

        echo $this->app->render('app/finance/reports.twig', [
            'period' => $period,
            'reports' => [
                'payroll-summary' => 'Payroll summary',
                'department-cost' => 'Cost by department',
                'variance' => 'Payroll variance',
                'statutory' => 'Statutory liability',
                'leave-liability' => 'Leave liability',
                'loan-balances' => 'Loan balances',
                'p9' => 'P9 annual tax',
                'p10' => 'P10 monthly PAYE',
                'gl-journal' => 'General ledger journal',
            ],
        ]);
    }

    public function report(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('reports.view');
        $companyId = (int) $company['company_id'];
        $key = (string) ($params['key'] ?? 'payroll-summary');
        $period = (string) $this->app->request->query('period', date('Y-m'));

        try {
            $report = $this->app->finance->service->report($companyId, $key, $period);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/reports');
        }

        echo $this->app->render('app/finance/report.twig', [
            'report' => $report,
            'key' => $key,
            'period' => $period,
            'can_export' => $this->app->can('reports.export'),
        ]);
    }

    public function exportReport(array $params): never
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('reports.export');
        $companyId = (int) $company['company_id'];
        $key = (string) ($params['key'] ?? 'payroll-summary');
        $period = (string) $this->app->request->query('period', date('Y-m'));

        try {
            $report = $this->app->finance->service->report($companyId, $key, $period);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/reports');
        }

        $headers = array_map(static fn (array $column): string => $column['label'], $report['columns']);
        $rows = array_map(static function (array $row) use ($report): array {
            $out = [];

            foreach ($report['columns'] as $column) {
                $value = $row[$column['key']] ?? '';
                $out[] = $column['type'] === 'money' ? Money::format((int) $value) : $value;
            }

            return $out;
        }, $report['rows']);

        $this->csv('report-' . $key . '-' . $period . '.csv', $headers, $rows);
    }

    public function loans(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('loans.view');
        $companyId = (int) $company['company_id'];

        echo $this->app->render('app/finance/loans.twig', [
            'loans' => $this->app->finance->loans->all($companyId),
            'advances' => $this->app->finance->loans->advances($companyId),
            'employees' => $this->app->employees->register->activeForSelect($companyId),
            'can_manage' => $this->app->can('loans.manage'),
        ]);
    }

    public function storeLoan(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('loans.manage');

        try {
            $this->app->finance->service->createLoan((int) $company['company_id'], $this->app->request->all(), (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/loans');
        }

        $this->app->flash->add('success', 'Loan created with a repayment schedule.');
        $this->app->redirect('/app/finance/loans');
    }

    public function storeAdvance(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('loans.manage');

        try {
            $this->app->finance->service->createAdvance((int) $company['company_id'], $this->app->request->all(), (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/loans');
        }

        $this->app->flash->add('success', 'Advance request created.');
        $this->app->redirect('/app/finance/loans');
    }

    public function decideAdvance(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('loans.manage');
        $decision = $this->app->request->string('decision') === 'approved' ? 'approved' : 'rejected';

        try {
            $this->app->finance->service->decideAdvance((int) $company['company_id'], (int) ($params['id'] ?? 0), $decision, (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/loans');
        }

        $this->app->flash->add('success', 'Advance ' . $decision . '.');
        $this->app->redirect('/app/finance/loans');
    }

    public function claims(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');
        $companyId = (int) $company['company_id'];

        echo $this->app->render('app/finance/claims.twig', [
            'expenses' => $this->app->finance->claims->expenses($companyId),
            'damage' => $this->app->finance->claims->damageClaims($companyId),
            'commissions' => $this->app->finance->claims->commissions($companyId),
            'pools' => $this->app->finance->claims->servicePools($companyId),
            'finals' => $this->app->finance->claims->finalDues($companyId),
            'employees' => $this->app->employees->register->activeForSelect($companyId),
        ]);
    }

    public function storeExpense(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');

        try {
            $this->app->finance->service->createExpense((int) $company['company_id'], $this->app->request->all(), (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/claims');
        }

        $this->app->flash->add('success', 'Expense claim created.');
        $this->app->redirect('/app/finance/claims');
    }

    public function decideExpense(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');
        $decision = $this->app->request->string('decision') === 'approved' ? 'approved' : 'rejected';
        $this->app->finance->service->decideExpense((int) $company['company_id'], (int) ($params['id'] ?? 0), $decision, (int) $this->app->user['id']);
        $this->app->flash->add('success', 'Expense claim ' . $decision . '.');
        $this->app->redirect('/app/finance/claims');
    }

    public function storeDamage(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');

        try {
            $this->app->finance->service->createDamage((int) $company['company_id'], $this->app->request->all(), (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/claims');
        }

        $this->app->flash->add('success', 'Loss or damage claim created.');
        $this->app->redirect('/app/finance/claims');
    }

    public function decideDamage(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');
        $decision = $this->app->request->string('decision') === 'approved' ? 'approved' : 'rejected';
        $this->app->finance->service->decideDamage((int) $company['company_id'], (int) ($params['id'] ?? 0), $decision, (int) $this->app->user['id']);
        $this->app->flash->add('success', 'Damage claim ' . $decision . '.');
        $this->app->redirect('/app/finance/claims');
    }

    public function storeCommission(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');

        try {
            $this->app->finance->service->createCommission((int) $company['company_id'], $this->app->request->all(), (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/claims');
        }

        $this->app->flash->add('success', 'Commission recorded for payroll.');
        $this->app->redirect('/app/finance/claims');
    }

    public function storeServiceCharge(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');

        try {
            $this->app->finance->service->createServicePool((int) $company['company_id'], $this->app->request->all(), (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/claims');
        }

        $this->app->flash->add('success', 'Service charge pool allocated equally.');
        $this->app->redirect('/app/finance/claims');
    }

    public function storeFinalDue(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');

        try {
            $this->app->finance->service->createFinalDue((int) $company['company_id'], $this->app->request->all(), (int) $this->app->user['id']);
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/finance/claims');
        }

        $this->app->flash->add('success', 'Final dues created.');
        $this->app->redirect('/app/finance/claims');
    }

    public function decideFinalDue(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');
        $decision = $this->app->request->string('decision') === 'approved' ? 'approved' : 'rejected';
        $this->app->finance->service->decideFinalDue((int) $company['company_id'], (int) ($params['id'] ?? 0), $decision, (int) $this->app->user['id']);
        $this->app->flash->add('success', 'Final dues ' . $decision . '.');
        $this->app->redirect('/app/finance/claims');
    }

    public function audit(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('audit.view');
        $companyId = (int) $company['company_id'];
        $filters = [
            'action' => trim((string) $this->app->request->query('action', '')),
            'entity' => trim((string) $this->app->request->query('entity', '')),
            'user' => trim((string) $this->app->request->query('user', '')),
            'from' => trim((string) $this->app->request->query('from', '')),
            'to' => trim((string) $this->app->request->query('to', '')),
        ];
        $total = $this->app->finance->audit->count($companyId, $filters);
        $pages = max(1, (int) ceil($total / 50));
        $page = min(max(1, (int) $this->app->request->query('page', 1)), $pages);

        echo $this->app->render('app/finance/audit.twig', [
            'entries' => $this->app->finance->audit->search($companyId, $filters, 50, ($page - 1) * 50),
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /**
     * @param list<string> $headers
     * @param list<array<int,string|int|float|null>> $rows
     */
    private function csv(string $filename, array $headers, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Cache-Control: private, no-store');

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            $this->app->abort(500, 'Could not create the export.');
        }

        fputcsv($out, $headers);

        foreach ($rows as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }
}
