ALTER TABLE loss_damage_claims
  DROP FOREIGN KEY loss_damage_claims_run_fk,
  DROP INDEX loss_damage_claims_run,
  DROP COLUMN recovered_at,
  DROP COLUMN run_id;

ALTER TABLE expense_claims
  DROP FOREIGN KEY expense_claims_run_fk,
  DROP INDEX expense_claims_run,
  DROP COLUMN paid_at,
  DROP COLUMN run_id,
  DROP COLUMN `period`;

ALTER TABLE final_dues
  DROP FOREIGN KEY final_dues_run_fk,
  DROP INDEX final_dues_run,
  DROP COLUMN notes,
  DROP COLUMN paid_at,
  DROP COLUMN run_id,
  DROP COLUMN period;

ALTER TABLE service_charge_shares
  DROP FOREIGN KEY service_charge_shares_run_fk,
  DROP INDEX service_charge_shares_run,
  DROP COLUMN paid_at,
  DROP COLUMN run_id;

ALTER TABLE commission_entries
  DROP FOREIGN KEY commission_entries_run_fk,
  DROP INDEX commission_entries_run,
  DROP COLUMN paid_at,
  DROP COLUMN run_id;

ALTER TABLE advance_requests
  DROP FOREIGN KEY advance_requests_loan_fk,
  DROP INDEX advance_requests_loan,
  DROP COLUMN start_period,
  DROP COLUMN reason,
  DROP COLUMN loan_id;

ALTER TABLE loan_schedule DROP COLUMN paid_cents;

ALTER TABLE loans
  DROP INDEX loans_company_reference,
  DROP COLUMN notes,
  DROP COLUMN reference;

ALTER TABLE statutory_returns
  DROP COLUMN filed_by,
  DROP COLUMN payload,
  DROP COLUMN due_date,
  DROP COLUMN amount_cents;

ALTER TABLE payslip_deliveries
  DROP COLUMN error,
  DROP COLUMN file_ref,
  DROP COLUMN email;

DROP TABLE IF EXISTS payment_batch_lines;
DROP TABLE IF EXISTS payment_batches;

ALTER TABLE payroll_lines
  DROP COLUMN proration_bp,
  DROP COLUMN final_dues_cents,
  DROP COLUMN service_charge_cents,
  DROP COLUMN commission_cents,
  DROP COLUMN other_deduction_cents,
  DROP COLUMN advance_deduction_cents,
  DROP COLUMN loan_deduction_cents,
  DROP COLUMN reimbursements_cents;

ALTER TABLE payroll_runs
  DROP INDEX payroll_runs_company_period_no,
  DROP COLUMN adjustment_reason,
  DROP COLUMN final_dues_cents,
  DROP COLUMN service_charge_cents,
  DROP COLUMN commission_cents,
  DROP COLUMN other_deductions_cents,
  DROP COLUMN loan_deductions_cents,
  DROP COLUMN reimbursements_cents,
  DROP COLUMN paid_at,
  DROP COLUMN payment_status,
  DROP COLUMN run_no,
  DROP COLUMN run_type,
  ADD UNIQUE KEY payroll_runs_company_period (company_id, period);

ALTER TABLE pay_items
  DROP INDEX pay_items_company_code,
  DROP COLUMN sort_order,
  DROP COLUMN code;

DELETE FROM modules WHERE `key` IN ('finance', 'payments', 'audit');
UPDATE modules SET status = 'Planned' WHERE `key` IN ('loans', 'returns', 'reports', 'self-service');
UPDATE permissions SET module_key = 'payroll' WHERE `key` = 'payment.release';
UPDATE permissions SET module_key = NULL WHERE `key` = 'audit.view';
UPDATE permissions SET module_key = 'payroll' WHERE `key` = 'payroll.export';
UPDATE permissions SET module_key = 'reports' WHERE `key` IN ('reports.view', 'reports.export');
