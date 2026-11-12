<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;

/**
 * A person's own payslips. The employee is always resolved from the login, and
 * the query requires an approved run, so another line id returns 404 no matter
 * what is typed in the URL.
 */
final class SelfServicePayslipController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
        $employee = $this->employee($companyId);

        echo $this->app->render('me/payslips.twig', [
            'employee' => $employee,
            'payslips' => $this->app->payroll->runs->payslipsForEmployee(
                $companyId,
                (int) $this->app->user['id'],
            ),
        ]);
    }

    public function show(array $params): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
        $employee = $this->employee($companyId);

        $line = $this->app->payroll->runs->payslipForEmployee(
            $companyId,
            (int) $this->app->user['id'],
            (int) ($params['line'] ?? 0),
        );

        if ($line === null) {
            $this->app->abort(404, 'That payslip does not exist.');
        }

        $run = $this->app->payroll->runs->find($companyId, (int) $line['run_id']);

        if ($run === null) {
            $this->app->abort(404, 'That payslip does not exist.');
        }

        $breakdown = json_decode((string) $line['breakdown'], true);
        $slip = is_array($breakdown) && is_array($breakdown['payslip'] ?? null) ? $breakdown['payslip'] : null;

        echo $this->app->render('app/payroll/payslip.twig', [
            'run' => $run,
            'line' => $line,
            'employee' => $employee,
            'slip' => $slip,
            'can_bank' => $this->app->can('employees.bank.view'),
            'generated_at' => gmdate('Y-m-d H:i'),
            'payslip_back' => '/ess/payslips',
            'payslip_back_label' => 'Back to my payslips',
        ]);
    }

    /** @return array<string,mixed> */
    private function employee(int $companyId): array
    {
        $linked = $this->app->employees->register->forUser($companyId, (int) $this->app->user['id']);

        if ($linked === null) {
            $this->app->abort(403, 'Your login is not linked to an employee record yet. Ask HR to link it.');
        }

        $employee = $this->app->employees->register->find($companyId, (int) $linked['id']);

        if ($employee === null) {
            $this->app->abort(404, 'That employee record does not exist.');
        }

        return $employee;
    }
}
