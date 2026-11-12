<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Authz;

/**
 * Which portals a person can enter, decided from permissions rather than role
 * names. HODs are deliberately not HR: their team-leave rights put them in ESS,
 * where they approve their department without the HR workspace.
 */
final class PortalResolver
{
    /** Running HR, not merely reading a directory. */
    private const HR = [
        'employees.edit',
        'employees.archive',
        'contracts.manage',
        'leave.manage_types',
        'leave.approve.all',
        'attendance.edit',
        'users.manage',
        'users.invite',
        'roles.manage',
        'settings.manage',
        'company.manage',
    ];

    /** Running money, not merely reading a report. */
    private const FINANCE = [
        'payroll.view',
        'payroll.run',
        'payroll.approve',
        'payroll.export',
        'payment.release',
        'returns.view',
        'returns.generate',
        'loans.view',
        'loans.manage',
        'reports.export',
    ];

    /** @param callable(string):bool $can */
    public function resolve(callable $can, bool $hasEmployee): Portal
    {
        if ($this->holdsAny($can, self::HR)) {
            return Portal::HR;
        }

        if ($this->holdsAny($can, self::FINANCE)) {
            return Portal::FINANCE;
        }

        return Portal::ESS;
    }

    /**
     * ESS is open to every member: it is the only place they can see their own
     * record, even when they also run HR or finance.
     *
     * @param callable(string):bool $can
     * @return list<Portal>
     */
    public function available(callable $can, bool $hasEmployee): array
    {
        $portals = [];

        if ($this->holdsAny($can, self::HR)) {
            $portals[] = Portal::HR;
        }

        if ($this->holdsAny($can, self::FINANCE)) {
            $portals[] = Portal::FINANCE;
        }

        $portals[] = Portal::ESS;

        return $portals;
    }

    /**
     * Keep the shell consistent with the URL. Shared employee pages follow the
     * portal the person came from, so Finance opening the register stays in
     * Finance instead of switching to an HR shell it cannot enter.
     */
    public function forPath(string $path, Portal $default, ?Portal $preferred = null): Portal
    {
        if (str_starts_with($path, '/hr')) {
            return Portal::HR;
        }

        if (str_starts_with($path, '/finance') || str_starts_with($path, '/app/payroll')) {
            return Portal::FINANCE;
        }

        if (str_starts_with($path, '/ess') || str_starts_with($path, '/me')) {
            return Portal::ESS;
        }

        if ((str_starts_with($path, '/app/employees') || str_starts_with($path, '/app/departments'))
            && $preferred !== null) {
            return $preferred;
        }

        return $default;
    }

    /**
     * @param callable(string):bool $can
     * @param list<string> $permissions
     */
    private function holdsAny(callable $can, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($can($permission)) {
                return true;
            }
        }

        return false;
    }
}
