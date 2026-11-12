<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

/**
 * Payroll runs. Preparing needs payroll.run; approving needs payroll.approve
 * and a different person, enforced in the service.
 */
final class PayrollController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.view');
        $companyId = (int) $company['company_id'];

        $suggested = $this->nextPeriod($companyId);
        $runs = $this->app->payroll->runs->listForCompany($companyId);
        $drafts = array_filter($runs, static fn (array $run): bool => $run['status'] === 'draft');

        echo $this->app->render('app/payroll/index.twig', [
            'runs' => $runs,
            'draft_count' => count($drafts),
            'suggested_period' => $suggested,
            'rates' => $this->app->payroll->rates->forDate($suggested . '-01'),
            'can_run' => $this->app->can('payroll.run'),
            'can_approve' => $this->app->can('payroll.approve'),
        ]);
    }

    /** Draft runs waiting for a second person. */
    public function approvals(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.approve');
        $companyId = (int) $company['company_id'];
        $runs = array_values(array_filter(
            $this->app->payroll->runs->listForCompany($companyId),
            static fn (array $run): bool => $run['status'] === 'draft',
        ));
        $people = 0;
        $net = 0;

        foreach ($runs as $run) {
            $people += (int) $run['employee_count'];
            $net += (int) $run['net_cents'];
        }

        echo $this->app->render('app/payroll/approvals.twig', [
            'runs' => $runs,
            'people_count' => $people,
            'net_cents' => $net,
        ]);
    }

    public function store(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');

        try {
            $runId = $this->app->payroll->service->prepare(
                (int) $company['company_id'],
                $this->app->request->string('period'),
                $this->app->request->string('label'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/payroll');
        }

        $this->app->flash->add('success', 'Payroll run prepared.');
        $this->app->redirect('/app/payroll/' . $runId);
    }

    public function show(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.view');

        $run = $this->app->payroll->runs->find((int) $company['company_id'], (int) ($params['id'] ?? 0));

        if ($run === null) {
            $this->app->abort(404, 'That payroll run does not exist.');
        }

        $lineItems = [];

        foreach ($this->app->payroll->runs->lineItems((int) $company['company_id'], (int) $run['id']) as $item) {
            $lineItems[(int) $item['employee_id']][] = $item;
        }

        echo $this->app->render('app/payroll/show.twig', [
            'run' => $run,
            'lines' => $this->app->payroll->runs->lines((int) $run['id']),
            'line_items' => $lineItems,
            'rates' => $this->app->payroll->rates->find((string) $run['rate_version_id']),
            'can_approve' => $this->app->can('payroll.approve'),
            'can_export' => $this->app->can('payroll.export'),
            'can_release' => $this->app->can('payment.release'),
            'can_deliver' => $this->app->can('payroll.export'),
            'can_adjust' => $this->app->can('payroll.run') && $run['status'] === 'approved',
            'is_preparer' => (int) $run['prepared_by'] === (int) $this->app->user['id'],
            'deliveries' => $this->app->finance->payslips->deliveries((int) $company['company_id'], (int) $run['id']),
        ]);
    }

    public function payslip(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.view');
        $companyId = (int) $company['company_id'];

        $run = $this->app->payroll->runs->find($companyId, (int) ($params['id'] ?? 0));

        if ($run === null) {
            $this->app->abort(404, 'That payroll run does not exist.');
        }

        $line = $this->app->payroll->runs->line($companyId, (int) $run['id'], (int) ($params['lineId'] ?? 0));

        if ($line === null) {
            $this->app->abort(404, 'That payslip does not exist.');
        }

        $breakdown = json_decode((string) $line['breakdown'], true);
        $slip = is_array($breakdown) && is_array($breakdown['payslip'] ?? null) ? $breakdown['payslip'] : null;
        $leave = is_array($breakdown) && is_array($breakdown['leave'] ?? null) ? $breakdown['leave'] : null;

        echo $this->app->render('app/payroll/payslip.twig', [
            'run' => $run,
            'line' => $line,
            'employee' => $this->app->employees->register->find($companyId, (int) $line['employee_id']),
            'slip' => $slip,
            'leave' => $leave,
            'base_salary_cents' => is_array($breakdown) && isset($breakdown['base_salary_cents'])
                ? (int) $breakdown['base_salary_cents']
                : (int) $line['gross_cents'],
            'can_bank' => $this->app->can('employees.bank.view'),
            'generated_at' => gmdate('Y-m-d H:i'),
            'items' => $this->app->payroll->runs->lineItems($companyId, (int) $run['id']),
            'pdf_href' => '/app/payroll/' . $run['id'] . '/lines/' . $line['id'] . '/payslip.pdf',
        ]);
    }

    public function adjust(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.run');
        $runId = (int) ($params['id'] ?? 0);

        try {
            $adjustmentId = $this->app->payroll->service->prepareAdjustment(
                (int) $company['company_id'],
                $runId,
                $this->app->request->string('reason'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/payroll/' . $runId);
        }

        $this->app->flash->add('success', 'Correction run prepared. It is separate from the original run.');
        $this->app->redirect('/app/payroll/' . $adjustmentId);
    }

    public function exportRun(array $params): never
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.export');
        $companyId = (int) $company['company_id'];
        $run = $this->app->payroll->runs->find($companyId, (int) ($params['id'] ?? 0));

        if ($run === null) {
            $this->app->abort(404, 'That payroll run does not exist.');
        }

        $filename = 'payroll-' . $run['period'] . '-run-' . $run['run_no'] . '.csv';
        $this->app->db->prepare(
            'INSERT INTO payroll_exports (company_id, run_id, type, file_ref, generated_by)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([$companyId, (int) $run['id'], 'register', $filename, (int) $this->app->user['id']]);
        $this->app->auditLogger->record($companyId, (int) $this->app->user['id'], 'payroll.exported', 'payroll_run', (string) $run['id'], ['type' => 'register']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            $this->app->abort(500, 'Could not create the export.');
        }

        fputcsv($out, [
            'Employee no.', 'Employee', 'Gross', 'Taxable', 'PAYE', 'NSSF employee', 'SHIF',
            'Housing employee', 'Other deductions', 'Loan', 'Advance', 'Reimbursements', 'Net',
            'NSSF employer', 'Housing employer', 'NITA', 'Employer cost',
        ]);

        foreach ($this->app->payroll->runs->lines((int) $run['id']) as $line) {
            fputcsv($out, [
                $line['employee_no'],
                $line['employee_name'],
                number_format((int) $line['gross_cents'] / 100, 2, '.', ''),
                number_format((int) $line['taxable_cents'] / 100, 2, '.', ''),
                number_format((int) $line['paye_cents'] / 100, 2, '.', ''),
                number_format((int) $line['nssf_employee_cents'] / 100, 2, '.', ''),
                number_format((int) $line['shif_cents'] / 100, 2, '.', ''),
                number_format((int) $line['housing_levy_employee_cents'] / 100, 2, '.', ''),
                number_format((int) $line['other_deduction_cents'] / 100, 2, '.', ''),
                number_format((int) $line['loan_deduction_cents'] / 100, 2, '.', ''),
                number_format((int) $line['advance_deduction_cents'] / 100, 2, '.', ''),
                number_format((int) $line['reimbursements_cents'] / 100, 2, '.', ''),
                number_format((int) $line['net_cents'] / 100, 2, '.', ''),
                number_format((int) $line['nssf_employer_cents'] / 100, 2, '.', ''),
                number_format((int) $line['housing_levy_employer_cents'] / 100, 2, '.', ''),
                number_format((int) $line['nita_cents'] / 100, 2, '.', ''),
                number_format((int) $line['employer_cost_cents'] / 100, 2, '.', ''),
            ]);
        }

        fclose($out);
        exit;
    }

    public function payslipPdf(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.view');
        $runId = (int) ($params['id'] ?? 0);
        $lineId = (int) ($params['lineId'] ?? 0);

        try {
            $slip = $this->app->finance->payslips->generate((int) $company['company_id'], $runId, $lineId);
        } catch (HttpException $error) {
            $this->app->abort($error->status, $error->getMessage());
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $slip['name'] . '"');
        header('Content-Length: ' . (string) filesize($slip['path']));
        header('Cache-Control: private, no-store');
        readfile($slip['path']);
        exit;
    }

    public function emailPayslip(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.export');
        $runId = (int) ($params['id'] ?? 0);
        $lineId = (int) ($params['lineId'] ?? 0);
        $result = $this->app->finance->payslips->email((int) $company['company_id'], $runId, $lineId, (int) $this->app->user['id']);

        $this->app->flash->add(
            $result['status'] === 'sent' ? 'success' : 'error',
            $result['status'] === 'sent' ? 'Payslip emailed.' : (string) $result['error'],
        );
        $this->app->redirect('/app/payroll/' . $runId);
    }

    public function emailPayslips(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.export');
        $runId = (int) ($params['id'] ?? 0);
        $counts = $this->app->finance->payslips->emailRun((int) $company['company_id'], $runId, (int) $this->app->user['id']);

        $message = $counts['sent'] . ' payslip(s) emailed';

        if ($counts['failed'] > 0) {
            $message .= ', ' . $counts['failed'] . ' failed';
        }

        if ($counts['skipped'] > 0) {
            $message .= ', ' . $counts['skipped'] . ' without an email address';
        }

        $this->app->flash->add($counts['failed'] > 0 ? 'error' : 'success', $message . '.');
        $this->app->redirect('/app/payroll/' . $runId);
    }

    public function approve(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.approve');

        $runId = (int) ($params['id'] ?? 0);
        $returnTo = $this->app->request->string('return_to') === 'approvals'
            ? '/app/payroll/approvals'
            : '/app/payroll/' . $runId;

        try {
            $this->app->payroll->service->approve(
                (int) $company['company_id'],
                $runId,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect($returnTo);
        }

        $this->app->flash->add('success', 'Payroll run approved.');
        $this->app->redirect($returnTo);
    }

    /** The first month from now that has no run yet. */
    private function nextPeriod(int $companyId): string
    {
        $period = date('Y-m');
        $guard = 0;

        while ($this->app->payroll->runs->existsForPeriod($companyId, $period) && $guard++ < 24) {
            $period = date('Y-m', strtotime($period . '-01 +1 month'));
        }

        return $period;
    }
}
