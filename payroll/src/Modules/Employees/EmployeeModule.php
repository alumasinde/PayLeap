<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Employees;

use Mshahara\Payroll\Support\FileStore;

/**
 * One module's collaborators, built once per request. Keeping them together
 * stops App from growing a property per repository as modules are added.
 */
final class EmployeeModule
{
    public function __construct(
        public readonly EmployeeRepository $register,
        public readonly DepartmentRepository $departments,
        public readonly EmployeeService $service,
        public readonly FileStore $files,
    ) {
    }
}
