<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

final class EmployeeDepartmentController
{
    public function __construct(private readonly App $app)
    {
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
            $this->app->redirect('/app/employees');
        }

        $this->app->flash->add('success', 'Department added.');
        $this->app->redirect('/app/employees');
    }
}
