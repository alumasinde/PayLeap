<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Authz;

/**
 * A portal is a workspace shell, not a security boundary. Permissions still
 * decide every page; the portal decides navigation, landing and language.
 */
enum Portal: string
{
    case HR = 'hr';
    case FINANCE = 'finance';
    case ESS = 'ess';

    public function label(): string
    {
        return match ($this) {
            self::HR => 'HR & administration',
            self::FINANCE => 'Finance & payroll',
            self::ESS => 'Employee self-service',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::HR => 'HR',
            self::FINANCE => 'Finance',
            self::ESS => 'ESS',
        };
    }

    public function path(): string
    {
        return '/' . $this->value;
    }
}
