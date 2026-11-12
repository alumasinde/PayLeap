<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

/**
 * Departments group the register and later carry cost reporting. Viewing is
 * part of the employee register; adding one needs employees.edit.
 */
final class DepartmentController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.view');
        $companyId = (int) $company['company_id'];
        $departments = $this->app->employees->departments->all($companyId);
        $assigned = 0;
        $largest = null;

        foreach ($departments as $department) {
            $count = (int) $department['employee_count'];
            $assigned += $count;

            if ($largest === null || $count > (int) $largest['employee_count']) {
                $largest = $department;
            }
        }

        echo $this->app->render('app/departments/index.twig', [
            'departments' => $departments,
            'assigned_count' => $assigned,
            'largest_name' => $largest === null ? null : $largest['name'],
            'can_edit' => $this->app->can('employees.edit'),
        ]);
    }

    public function store(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.edit');

        try {
            $this->app->employees->service->addDepartment(
                (int) $company['company_id'],
                $this->app->request->string('name'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/departments');
        }

        $this->app->flash->add('success', 'Department added.');
        $this->app->redirect('/app/departments');
    }
}
