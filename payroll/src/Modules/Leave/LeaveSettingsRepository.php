<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use PDO;

/**
 * Company leave policy. The defaults are returned without touching the
 * database, so opening a balance page never writes a policy row.
 */
final class LeaveSettingsRepository
{
    public const DEFAULTS = [
        'carry_over_expiry_months' => 18,
        'forfeit_excess' => 0,
        'multi_level_approval' => 0,
        'daily_rate_divisor' => 26,
        'holiday_work_multiplier' => 2.0,
        'planner_enabled' => 1,
        'encashment_enabled' => 1,
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed> */
    public function forCompany(int $companyId): array
    {
        $statement = $this->db->prepare('SELECT * FROM leave_settings WHERE company_id = ? LIMIT 1');
        $statement->execute([$companyId]);
        $row = $statement->fetch();

        return $row === false ? ['company_id' => $companyId] + self::DEFAULTS : $row;
    }

    /** @param array<string,mixed> $input */
    public function save(int $companyId, array $input): void
    {
        $current = $this->forCompany($companyId);
        $expiry = max(0, min(60, (int) ($input['carry_over_expiry_months'] ?? $current['carry_over_expiry_months'])));
        $divisor = max(1, min(31, (int) ($input['daily_rate_divisor'] ?? $current['daily_rate_divisor'])));
        $multiplier = round(max(1.0, min(4.0, (float) ($input['holiday_work_multiplier'] ?? $current['holiday_work_multiplier']))), 2);

        $this->db->prepare(
            'INSERT INTO leave_settings
                (company_id, carry_over_expiry_months, forfeit_excess, multi_level_approval,
                 daily_rate_divisor, holiday_work_multiplier, planner_enabled, encashment_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                carry_over_expiry_months = VALUES(carry_over_expiry_months),
                forfeit_excess = VALUES(forfeit_excess),
                multi_level_approval = VALUES(multi_level_approval),
                daily_rate_divisor = VALUES(daily_rate_divisor),
                holiday_work_multiplier = VALUES(holiday_work_multiplier),
                planner_enabled = VALUES(planner_enabled),
                encashment_enabled = VALUES(encashment_enabled)',
        )->execute([
            $companyId,
            $expiry,
            !empty($input['forfeit_excess']) ? 1 : 0,
            !empty($input['multi_level_approval']) ? 1 : 0,
            $divisor,
            $multiplier,
            !empty($input['planner_enabled']) ? 1 : 0,
            !empty($input['encashment_enabled']) ? 1 : 0,
        ]);
    }
}
