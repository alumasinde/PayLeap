-- Leave balances distinguish the yearly entitlement from days accrued so far.
-- Annual leave accrues monthly; sick leave is available in full after its
-- statutory waiting period. Companies may choose either rule per type.

ALTER TABLE leave_types
  ADD COLUMN accrual_start_months TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER accrual_rule;

UPDATE leave_types
SET accrual_rule = 'monthly', accrual_start_months = 0
WHERE name = 'Annual leave';

UPDATE leave_types
SET accrual_rule = 'fixed', accrual_start_months = 2
WHERE name = 'Sick leave';

UPDATE leave_types
SET accrual_rule = 'fixed', accrual_start_months = 0
WHERE name IN ('Maternity leave', 'Paternity leave', 'Compassionate leave');

UPDATE leave_types
SET accrual_rule = 'fixed'
WHERE accrual_rule NOT IN ('monthly', 'fixed');