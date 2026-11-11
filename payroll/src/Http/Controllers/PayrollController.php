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

        echo $this->app->render('app/payroll/index.twig', [
            'runs' => $this->app->payroll->runs->listForCompany($companyId),
            'suggested_period' => $suggested,
            'rates' => $this->app->payroll->rates->forDate($suggested . '-01'),
            'can_run' => $this->app->can('payroll.run'),
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

        echo $this->app->render('app/payroll/show.twig', [
            'run' => $run,
            'lines' => $this->app->payroll->runs->lines((int) $run['id']),
            'rates' => $this->app->payroll->rates->find((string) $run['rate_version_id']),
            'can_approve' => $this->app->can('payroll.approve'),
            'is_preparer' => (int) $run['prepared_by'] === (int) $this->app->user['id'],
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

        echo $this->app->render('app/payroll/payslip.twig', [
            'run' => $run,
            'line' => $line,
            'employee' => $this->app->employees->register->find($companyId, (int) $line['employee_id']),
            'slip' => $slip,
            'can_bank' => $this->app->can('employees.bank.view'),
            'generated_at' => gmdate('Y-m-d H:i'),
        ]);
    }

    public function approve(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('payroll.approve');

        $runId = (int) ($params['id'] ?? 0);

        try {
            $this->app->payroll->service->approve(
                (int) $company['company_id'],
                $runId,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/payroll/' . $runId);
        }

        $this->app->flash->add('success', 'Payroll run approved.');
        $this->app->redirect('/app/payroll/' . $runId);
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
