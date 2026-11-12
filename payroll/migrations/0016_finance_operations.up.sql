-- Finance operations: itemised pay, correction runs, payments, returns and
-- claim recovery. The payroll tables remain the single source of truth for
-- what was calculated; these tables record how it was paid and filed.

ALTER TABLE pay_items
  ADD COLUMN code VARCHAR(40) NULL AFTER company_id,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER active,
  ADD UNIQUE KEY pay_items_company_code (company_id, code);

ALTER TABLE payroll_runs
  DROP INDEX payroll_runs_company_period,
  ADD COLUMN run_type VARCHAR(20) NOT NULL DEFAULT 'regular' AFTER label,
  ADD COLUMN run_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER run_type,
  ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid' AFTER status,
  ADD COLUMN paid_at DATETIME NULL AFTER locked_at,
  ADD COLUMN reimbursements_cents BIGINT NOT NULL DEFAULT 0 AFTER deductions_cents,
  ADD COLUMN loan_deductions_cents BIGINT NOT NULL DEFAULT 0 AFTER reimbursements_cents,
  ADD COLUMN other_deductions_cents BIGINT NOT NULL DEFAULT 0 AFTER loan_deductions_cents,
  ADD COLUMN commission_cents BIGINT NOT NULL DEFAULT 0 AFTER other_deductions_cents,
  ADD COLUMN service_charge_cents BIGINT NOT NULL DEFAULT 0 AFTER commission_cents,
  ADD COLUMN final_dues_cents BIGINT NOT NULL DEFAULT 0 AFTER service_charge_cents,
  ADD COLUMN adjustment_reason VARCHAR(255) NULL AFTER final_dues_cents,
  ADD UNIQUE KEY payroll_runs_company_period_no (company_id, period, run_no);

ALTER TABLE payroll_runs
  MODIFY gross_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY deductions_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY net_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY employer_nssf_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY employer_housing_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY employer_nita_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY employer_cost_cents BIGINT NOT NULL DEFAULT 0;

ALTER TABLE payroll_lines
  ADD COLUMN reimbursements_cents BIGINT NOT NULL DEFAULT 0 AFTER net_cents,
  ADD COLUMN loan_deduction_cents BIGINT NOT NULL DEFAULT 0 AFTER reimbursements_cents,
  ADD COLUMN advance_deduction_cents BIGINT NOT NULL DEFAULT 0 AFTER loan_deduction_cents,
  ADD COLUMN other_deduction_cents BIGINT NOT NULL DEFAULT 0 AFTER advance_deduction_cents,
  ADD COLUMN commission_cents BIGINT NOT NULL DEFAULT 0 AFTER other_deduction_cents,
  ADD COLUMN service_charge_cents BIGINT NOT NULL DEFAULT 0 AFTER commission_cents,
  ADD COLUMN final_dues_cents BIGINT NOT NULL DEFAULT 0 AFTER service_charge_cents,
  ADD COLUMN proration_bp INT UNSIGNED NOT NULL DEFAULT 10000 AFTER final_dues_cents;

ALTER TABLE payroll_lines
  MODIFY gross_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY taxable_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY paye_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY nssf_employee_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY nssf_employer_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY shif_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY housing_levy_employee_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY housing_levy_employer_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY nita_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY deductions_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY net_cents BIGINT NOT NULL DEFAULT 0,
  MODIFY employer_cost_cents BIGINT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS payment_batches (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  run_id      BIGINT UNSIGNED NOT NULL,
  reference   VARCHAR(80)     NOT NULL,
  method      VARCHAR(20)     NOT NULL,
  total_cents BIGINT          NOT NULL DEFAULT 0,
  status      VARCHAR(20)     NOT NULL DEFAULT 'prepared',
  file_ref    VARCHAR(255)    NULL,
  prepared_by BIGINT UNSIGNED NULL,
  released_by BIGINT UNSIGNED NULL,
  released_at DATETIME        NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY payment_batches_company_reference (company_id, reference),
  KEY payment_batches_run (run_id),
  KEY payment_batches_company_status (company_id, status),
  CONSTRAINT payment_batches_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT payment_batches_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE,
  CONSTRAINT payment_batches_preparer_fk FOREIGN KEY (prepared_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT payment_batches_releaser_fk FOREIGN KEY (released_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_batch_lines (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id       BIGINT UNSIGNED NOT NULL,
  batch_id         BIGINT UNSIGNED NOT NULL,
  line_id          BIGINT UNSIGNED NOT NULL,
  employee_id      BIGINT UNSIGNED NOT NULL,
  amount_cents     BIGINT          NOT NULL,
  bank_name        VARCHAR(80)     NULL,
  bank_branch      VARCHAR(80)     NULL,
  account_no       VARCHAR(40)     NULL,
  mpesa_no         VARCHAR(40)     NULL,
  status           VARCHAR(20)     NOT NULL DEFAULT 'pending',
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY payment_batch_lines_batch_employee (batch_id, employee_id),
  KEY payment_batch_lines_company (company_id),
  CONSTRAINT payment_batch_lines_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT payment_batch_lines_batch_fk FOREIGN KEY (batch_id) REFERENCES payment_batches (id) ON DELETE CASCADE,
  CONSTRAINT payment_batch_lines_line_fk FOREIGN KEY (line_id) REFERENCES payroll_lines (id) ON DELETE CASCADE,
  CONSTRAINT payment_batch_lines_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE payslip_deliveries
  ADD COLUMN email VARCHAR(190) NULL AFTER channel,
  ADD COLUMN file_ref VARCHAR(255) NULL AFTER email,
  ADD COLUMN error VARCHAR(255) NULL AFTER status;

ALTER TABLE statutory_returns
  ADD COLUMN amount_cents BIGINT NOT NULL DEFAULT 0 AFTER run_id,
  ADD COLUMN due_date DATE NULL AFTER amount_cents,
  ADD COLUMN payload JSON NULL AFTER due_date,
  ADD COLUMN filed_by BIGINT UNSIGNED NULL AFTER reference_no;

ALTER TABLE loans
  ADD COLUMN reference VARCHAR(80) NULL AFTER employee_id,
  ADD COLUMN notes VARCHAR(255) NULL AFTER status,
  ADD UNIQUE KEY loans_company_reference (company_id, reference);

ALTER TABLE loan_schedule
  ADD COLUMN paid_cents BIGINT NOT NULL DEFAULT 0 AFTER paid;

ALTER TABLE advance_requests
  ADD COLUMN loan_id BIGINT UNSIGNED NULL AFTER employee_id,
  ADD COLUMN reason VARCHAR(255) NULL AFTER amount_cents,
  ADD COLUMN start_period CHAR(7) NULL AFTER reason,
  ADD KEY advance_requests_loan (loan_id),
  ADD CONSTRAINT advance_requests_loan_fk FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE SET NULL;

ALTER TABLE commission_entries
  ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER amount_cents,
  ADD COLUMN paid_at DATETIME NULL AFTER run_id,
  ADD KEY commission_entries_run (run_id),
  ADD CONSTRAINT commission_entries_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE SET NULL;

ALTER TABLE service_charge_shares
  ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER amount_cents,
  ADD COLUMN paid_at DATETIME NULL AFTER run_id,
  ADD KEY service_charge_shares_run (run_id),
  ADD CONSTRAINT service_charge_shares_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE SET NULL;

ALTER TABLE final_dues
  ADD COLUMN period CHAR(7) NULL AFTER employee_id,
  ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER total_cents,
  ADD COLUMN paid_at DATETIME NULL AFTER run_id,
  ADD COLUMN notes VARCHAR(255) NULL AFTER paid_at,
  ADD KEY final_dues_run (run_id),
  ADD CONSTRAINT final_dues_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE SET NULL;

ALTER TABLE expense_claims
  ADD COLUMN `period` CHAR(7) NULL AFTER category,
  ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER status,
  ADD COLUMN paid_at DATETIME NULL AFTER run_id,
  ADD KEY expense_claims_run (run_id),
  ADD CONSTRAINT expense_claims_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE SET NULL;

ALTER TABLE loss_damage_claims
  ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER status,
  ADD COLUMN recovered_at DATETIME NULL AFTER run_id,
  ADD KEY loss_damage_claims_run (run_id),
  ADD CONSTRAINT loss_damage_claims_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE SET NULL;

INSERT INTO modules (`key`, name, description, status, permission_key, sort_order) VALUES
  ('finance', 'Finance', 'Pay structures, payments, returns, reports and controls.', 'Live', 'payroll.view', 15),
  ('payments', 'Payments', 'Payment batches, bank and M-Pesa files, release and reconciliation.', 'Live', 'payment.release', 25),
  ('audit', 'Audit', 'Append-only record of financial and administrative actions.', 'Live', 'audit.view', 95)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  status = VALUES(status),
  permission_key = VALUES(permission_key),
  sort_order = VALUES(sort_order);

UPDATE modules SET status = 'Live' WHERE `key` IN ('loans', 'returns', 'reports', 'self-service');
UPDATE permissions SET module_key = 'payments' WHERE `key` = 'payment.release';
UPDATE permissions SET module_key = 'audit' WHERE `key` = 'audit.view';
UPDATE permissions SET module_key = 'finance' WHERE `key` IN ('payroll.export', 'reports.view', 'reports.export');
