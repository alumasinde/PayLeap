-- Statutory leave rules, policy settings and the controls needed by phases 2-10.
-- Values are data. The service reads these rows; citations are displayed next
-- to the configured amounts so a change is auditable.

ALTER TABLE leave_types
  ADD COLUMN system_code VARCHAR(40) NULL AFTER name,
  ADD COLUMN expiry_months SMALLINT UNSIGNED NOT NULL DEFAULT 18 AFTER carry_over_max,
  ADD COLUMN daily_pay_percent DECIMAL(5,2) NOT NULL DEFAULT 100.00 AFTER expiry_months;

UPDATE leave_types
SET system_code = CASE
  WHEN name = 'Annual leave' THEN 'annual'
  WHEN name = 'Sick leave' THEN 'sick'
  WHEN name = 'Maternity leave' THEN 'maternity'
  WHEN name = 'Paternity leave' THEN 'paternity'
  WHEN name = 'Compassionate leave' THEN 'compassionate'
  ELSE system_code
END
WHERE system_code IS NULL;

INSERT INTO leave_types
  (company_id, name, system_code, days_per_year, accrual_rule, accrual_start_months,
   day_basis, paid, gender_restriction, requires_document, carry_over_max, expiry_months,
   daily_pay_percent)
SELECT c.id, 'Pre-adoptive leave', 'pre_adoptive', 30.00, 'fixed', 0,
       'calendar', 1, 'any', 1, 0.00, 18, 100.00
FROM companies c
WHERE NOT EXISTS (
  SELECT 1 FROM leave_types lt WHERE lt.company_id = c.id AND lt.name = 'Pre-adoptive leave'
);

CREATE TABLE IF NOT EXISTS statutory_rules (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  country_code   CHAR(2)         NOT NULL DEFAULT 'KE',
  code           VARCHAR(80)     NOT NULL,
  category       VARCHAR(40)     NOT NULL,
  value_decimal  DECIMAL(10,4)   NULL,
  value_text     VARCHAR(255)    NULL,
  effective_from DATE            NOT NULL,
  effective_to   DATE            NULL,
  citation       VARCHAR(160)    NOT NULL,
  source_url     VARCHAR(255)    NULL,
  notes          VARCHAR(500)    NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY statutory_rules_code_effective (country_code, code, effective_from),
  KEY statutory_rules_category (country_code, category, effective_from)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO statutory_rules
  (country_code, code, category, value_decimal, value_text, effective_from, citation, source_url, notes)
VALUES
  ('KE', 'annual_leave_days', 'annual_leave', 21.0000, NULL, '2008-06-02',
   'Employment Act 2007, s.28(1)(a)', 'https://new.kenyalaw.org/akn/ke/act/2007/11',
   'Not less than twenty-one working days with full pay after twelve consecutive months of service.'),
  ('KE', 'annual_leave_monthly_rate', 'annual_leave', 1.7500, NULL, '2008-06-02',
   'Employment Act 2007, s.28(1)(a)', 'https://new.kenyalaw.org/akn/ke/act/2007/11',
   'Twenty-one days divided by twelve months.'),
  ('KE', 'annual_leave_carry_months', 'annual_leave', 18.0000, NULL, '2008-06-02',
   'Employment Act 2007, s.28(4)', 'https://new.kenyalaw.org/akn/ke/act/2007/11',
   'Leave not taken within eighteen months after the end of the leave-earning period is flagged/forfeited according to company policy.'),
  ('KE', 'sick_leave_full_days', 'sick_leave', 7.0000, NULL, '2008-06-02',
   'Employment Act 2007, s.30(1)', 'https://new.kenyalaw.org/akn/ke/act/2007/11',
   'First seven days of sick leave in a twelve-month period with full pay.'),
  ('KE', 'sick_leave_half_days', 'sick_leave', 7.0000, NULL, '2008-06-02',
   'Employment Act 2007, s.30(1)', 'https://new.kenyalaw.org/akn/ke/act/2007/11',
   'Next seven days of sick leave in the same twelve-month period with half pay.'),
  ('KE', 'sick_leave_waiting_months', 'sick_leave', 2.0000, NULL, '2008-06-02',
   'Employment Act 2007, s.30(1)', 'https://new.kenyalaw.org/akn/ke/act/2007/11',
   'After two consecutive months of service.'),
  ('KE', 'maternity_leave_days', 'maternity', 90.0000, NULL, '2008-06-02',
   'Employment Act 2007, s.29(1)', 'https://new.kenyalaw.org/akn/ke/act/2007/11',
   'Three months maternity leave with full pay.'),
  ('KE', 'paternity_leave_days', 'paternity', 14.0000, NULL, '2008-06-02',
   'Employment Act 2007, s.29(8)', 'https://new.kenyalaw.org/akn/ke/act/2007/11',
   'Two weeks paternity leave with full pay.'),
  ('KE', 'pre_adoptive_leave_days', 'pre_adoptive', 30.0000, NULL, '2021-04-01',
   'Employment Act 2007, s.29A', 'https://new.kenyalaw.org/akn/ke/act/2007/11',
   'One month pre-adoptive leave with full pay from the date of placement.'),
  ('KE', 'public_holiday_work_multiplier', 'holiday_work', 2.0000, NULL, '1982-08-01',
   'Regulation of Wages (General) Order 1982, r.6(1)(b)', 'https://new.kenyalaw.org/akn/ke/act/ln/1982/120',
   'Time worked on a normal rest day or public holiday is paid at twice the normal hourly rate.'),
  ('KE', 'public_holiday_pay_days', 'holiday_work', 1.0000, NULL, '1982-08-01',
   'Regulation of Wages (General) Order 1982, r.8', 'https://new.kenyalaw.org/akn/ke/act/ln/1982/120',
   'Gazetted public holidays are holidays with full pay.')
ON DUPLICATE KEY UPDATE
  category = VALUES(category),
  value_decimal = VALUES(value_decimal),
  value_text = VALUES(value_text),
  citation = VALUES(citation),
  source_url = VALUES(source_url),
  notes = VALUES(notes);

CREATE TABLE IF NOT EXISTS leave_settings (
  company_id                 BIGINT UNSIGNED NOT NULL,
  carry_over_expiry_months   SMALLINT UNSIGNED NOT NULL DEFAULT 18,
  forfeit_excess             TINYINT(1)      NOT NULL DEFAULT 0,
  multi_level_approval       TINYINT(1)      NOT NULL DEFAULT 0,
  daily_rate_divisor         SMALLINT UNSIGNED NOT NULL DEFAULT 26,
  holiday_work_multiplier    DECIMAL(4,2)    NOT NULL DEFAULT 2.00,
  planner_enabled            TINYINT(1)      NOT NULL DEFAULT 1,
  encashment_enabled         TINYINT(1)      NOT NULL DEFAULT 1,
  updated_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id),
  CONSTRAINT leave_settings_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO leave_settings (company_id)
SELECT c.id FROM companies c
ON DUPLICATE KEY UPDATE company_id = VALUES(company_id);

CREATE TABLE IF NOT EXISTS leave_exceptions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  batch_id    BIGINT UNSIGNED NOT NULL,
  leave_type_id BIGINT UNSIGNED NOT NULL,
  kind        VARCHAR(30)     NOT NULL,
  days        DECIMAL(7,2)    NOT NULL,
  status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
  note        VARCHAR(255)    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME        NULL,
  resolved_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY leave_exceptions_batch_kind (company_id, batch_id, kind),
  KEY leave_exceptions_company_status (company_id, status),
  CONSTRAINT leave_exceptions_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT leave_exceptions_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT leave_exceptions_batch_fk FOREIGN KEY (batch_id) REFERENCES leave_batches (id) ON DELETE CASCADE,
  CONSTRAINT leave_exceptions_type_fk FOREIGN KEY (leave_type_id) REFERENCES leave_types (id) ON DELETE CASCADE,
  CONSTRAINT leave_exceptions_resolver_fk FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE leave_requests
  ADD COLUMN current_step TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
  ADD COLUMN total_steps TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER current_step;

CREATE TABLE IF NOT EXISTS leave_approval_steps (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id           BIGINT UNSIGNED NOT NULL,
  request_id           BIGINT UNSIGNED NOT NULL,
  step_no              TINYINT UNSIGNED NOT NULL,
  approver_employee_id BIGINT UNSIGNED NULL,
  approver_permission  VARCHAR(60)     NULL,
  status               VARCHAR(20)     NOT NULL DEFAULT 'pending',
  decided_by           BIGINT UNSIGNED NULL,
  decided_at           DATETIME        NULL,
  note                 VARCHAR(255)    NULL,
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY leave_approval_steps_request_step (request_id, step_no),
  KEY leave_approval_steps_company (company_id, status),
  CONSTRAINT leave_approval_steps_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT leave_approval_steps_request_fk FOREIGN KEY (request_id) REFERENCES leave_requests (id) ON DELETE CASCADE,
  CONSTRAINT leave_approval_steps_employee_fk FOREIGN KEY (approver_employee_id) REFERENCES employees (id) ON DELETE SET NULL,
  CONSTRAINT leave_approval_steps_decider_fk FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_encashments (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id       BIGINT UNSIGNED NOT NULL,
  employee_id      BIGINT UNSIGNED NOT NULL,
  leave_type_id    BIGINT UNSIGNED NOT NULL,
  days             DECIMAL(7,2)    NOT NULL,
  daily_rate_cents BIGINT UNSIGNED NOT NULL,
  amount_cents     BIGINT UNSIGNED NOT NULL,
  encashed_on      DATE            NOT NULL,
  status           VARCHAR(20)     NOT NULL DEFAULT 'pending',
  payroll_run_id   BIGINT UNSIGNED NULL,
  created_by       BIGINT UNSIGNED NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY leave_encashments_employee_date (company_id, employee_id, encashed_on),
  KEY leave_encashments_status (company_id, status),
  CONSTRAINT leave_encashments_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT leave_encashments_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT leave_encashments_type_fk FOREIGN KEY (leave_type_id) REFERENCES leave_types (id) ON DELETE CASCADE,
  CONSTRAINT leave_encashments_run_fk FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs (id) ON DELETE SET NULL,
  CONSTRAINT leave_encashments_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_pay_tiers (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  country_code CHAR(2)         NOT NULL DEFAULT 'KE',
  system_code  VARCHAR(40)     NOT NULL,
  from_day     SMALLINT UNSIGNED NOT NULL,
  to_day       SMALLINT UNSIGNED NULL,
  pay_percent  DECIMAL(5,2)    NOT NULL,
  citation     VARCHAR(160)    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY leave_pay_tiers_system_from (country_code, system_code, from_day)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO leave_pay_tiers (country_code, system_code, from_day, to_day, pay_percent, citation)
VALUES
  ('KE', 'sick', 1, 7, 100.00, 'Employment Act 2007, s.30(1)'),
  ('KE', 'sick', 8, 14, 50.00, 'Employment Act 2007, s.30(1)')
ON DUPLICATE KEY UPDATE
  to_day = VALUES(to_day),
  pay_percent = VALUES(pay_percent),
  citation = VALUES(citation);

CREATE TABLE IF NOT EXISTS toil_entries (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  work_date    DATE            NOT NULL,
  hours        DECIMAL(5,2)    NOT NULL,
  days         DECIMAL(5,2)    NOT NULL,
  status       VARCHAR(20)     NOT NULL DEFAULT 'pending',
  approved_by  BIGINT UNSIGNED NULL,
  approved_at  DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY toil_entries_employee_date (company_id, employee_id, work_date),
  KEY toil_entries_status (company_id, status),
  CONSTRAINT toil_entries_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT toil_entries_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT toil_entries_approver_fk FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE attendance_records
  ADD COLUMN night_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER clock_out;

ALTER TABLE public_holidays
  ADD COLUMN is_gazetted TINYINT(1) NOT NULL DEFAULT 1 AFTER name,
  ADD COLUMN observed_date DATE NULL AFTER holiday_date,
  ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'company' AFTER is_gazetted;

ALTER TABLE payroll_lines
  ADD COLUMN leave_deduction_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER employer_cost_cents,
  ADD COLUMN holiday_premium_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER leave_deduction_cents,
  ADD COLUMN toil_days DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER holiday_premium_cents;

-- An expiry can only be posted once for a batch. NULL ref values are allowed
-- more than once by MySQL, which keeps ordinary accrual/usage rows untouched.
ALTER TABLE leave_transactions
  ADD UNIQUE KEY leave_transactions_once (batch_id, type, ref_type, ref_id);
