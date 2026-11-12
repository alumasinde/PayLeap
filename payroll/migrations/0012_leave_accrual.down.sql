UPDATE leave_types SET accrual_rule = 'fixed' WHERE accrual_rule = 'monthly';

ALTER TABLE leave_types
  DROP COLUMN accrual_start_months;