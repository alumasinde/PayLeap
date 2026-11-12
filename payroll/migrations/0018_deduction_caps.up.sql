-- Deduction cap support: a damage recovery can be partly applied when the
-- one-third net-pay floor limits this month's deductions. The unpaid balance
-- stays on the claim and is recovered by a later payroll run.

ALTER TABLE loss_damage_claims
  ADD COLUMN recovered_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER amount_cents;

UPDATE loss_damage_claims
SET recovered_cents = amount_cents
WHERE run_id IS NOT NULL AND recovered_at IS NOT NULL;
