<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

final class RoleController
{
    public function __construct(private readonly App $app)
    {
    }

    public function edit(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('roles.manage');

        $role = $this->app->roleRepository->find((int) ($params['id'] ?? 0));

        if ($role === null || (int) $role['company_id'] !== (int) $company['company_id']) {
            $this->app->abort(404, 'Role not found.');
        }

        if ($role['key'] === 'owner') {
            $this->app->abort(403, 'The owner role is fixed.');
        }

        echo $this->app->render('app/role.twig', [
            'role' => $role,
            'catalog' => $this->app->permissionRepository->catalog(),
            'selected' => $this->app->roleRepository->permissionsFor((int) $role['id']),
        ]);
    }

    public function update(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('roles.manage');

        $roleId = (int) ($params['id'] ?? 0);
        $role = $this->app->roleRepository->find($roleId);

        if ($role === null || (int) $role['company_id'] !== (int) $company['company_id']) {
            $this->app->abort(404, 'Role not found.');
        }

        $permissions = $this->app->request->input('permissions', []);
        $permissions = is_array($permissions) ? $permissions : [];

        try {
            $this->app->roleService->update(
                (int) $company['company_id'],
                $roleId,
                $this->app->request->string('name'),
                $permissions,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/access/roles/' . $roleId);
        }

        $this->app->flash->add('success', 'Role updated.');
        $this->app->redirect('/app/access');
    }
}
