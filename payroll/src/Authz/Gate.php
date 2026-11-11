<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Authz;

/**
 * Permission checks. Roles are bundles from config/permissions.php;
 * nothing in the app should branch on a role name.
 */
final class Gate
{
    /** @param array{roles:array<string,list<string>>,labels:array<string,string>} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function allows(?string $role, string $permission): bool
    {
        if ($role === null || $role === '') {
            return false;
        }

        $granted = $this->config['roles'][$role] ?? [];

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_keys($this->config['roles']);
    }

    public function label(string $role): string
    {
        return $this->config['labels'][$role] ?? ucfirst($role);
    }
}
