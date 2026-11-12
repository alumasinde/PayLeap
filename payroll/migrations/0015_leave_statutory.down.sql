DROP TABLE IF EXISTS leave_exceptions;
DROP TABLE IF EXISTS leave_approval_steps;
DROP TABLE IF EXISTS leave_encashments;
DROP TABLE IF EXISTS leave_pay_tiers;
DROP TABLE IF EXISTS toil_entries;
DROP TABLE IF EXISTS leave_settings;
DROP TABLE IF EXISTS statutory_rules;

ALTER TABLE payroll_lines
  DROP COLUMN leave_deduction_cents,
  DROP COLUMN holiday_premium_cents,
  DROP COLUMN toil_days;

ALTER TABLE attendance_records
  DROP COLUMN night_minutes;

ALTER TABLE public_holidays
  DROP COLUMN is_gazetted,
  DROP COLUMN observed_date,
  DROP COLUMN source;

ALTER TABLE leave_requests
  DROP COLUMN current_step,
  DROP COLUMN total_steps;

ALTER TABLE leave_types
  DROP COLUMN system_code,
  DROP COLUMN expiry_months,
  DROP COLUMN daily_pay_percent;

ALTER TABLE leave_transactions
  DROP INDEX leave_transactions_once;
