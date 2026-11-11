-- Pay structure, statutory rate versions, payroll runs and their output.
--
-- Rates are data, never code. A new gazetted rate is a new rate_versions row,
-- effective from a date, and no deploy is needed. Runs keep the version they
-- were calculated with, so an old payslip still explains itself.

CREATE TABLE IF NOT EXISTS rate_versions (
  id             VARCHAR(24)  NOT NULL,
  label          VARCHAR(64)  NOT NULL,
  effective_from DATE         NOT NULL,
  verified_on    DATE         NOT NULL,
  payload        JSON         NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY rate_versions_effective (effective_from)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pay_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120)    NOT NULL,
  kind        VARCHAR(20)     NOT NULL,
  taxable     TINYINT(1)      NOT NULL DEFAULT 1,
  pensionable TINYINT(1)      NOT NULL DEFAULT 0,
  cash        TINYINT(1)      NOT NULL DEFAULT 1,
  calc_type   VARCHAR(20)     NOT NULL DEFAULT 'fixed',
  active      TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY pay_items_company_name (company_id, name),
  CONSTRAINT pay_items_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_pay_items (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  pay_item_id  BIGINT UNSIGNED NOT NULL,
  amount_cents BIGINT UNSIGNED NULL,
  percent      DECIMAL(7,4)    NULL,
  start_date   DATE            NULL,
  end_date     DATE            NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY employee_pay_items_employee (employee_id, start_date),
  KEY employee_pay_items_item (pay_item_id),
  KEY employee_pay_items_company (company_id),
  CONSTRAINT employee_pay_items_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT employee_pay_items_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT employee_pay_items_item_fk FOREIGN KEY (pay_item_id) REFERENCES pay_items (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_runs (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id             BIGINT UNSIGNED NOT NULL,
  period                 CHAR(7)         NOT NULL,
  label                  VARCHAR(120)    NOT NULL,
  status                 VARCHAR(20)     NOT NULL DEFAULT 'draft',
  rate_version_id        VARCHAR(24)     NOT NULL,
  parent_run_id          BIGINT UNSIGNED NULL,
  employee_count         INT UNSIGNED    NOT NULL DEFAULT 0,
  gross_cents            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  deductions_cents       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  net_cents              BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_nssf_cents    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_housing_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_nita_cents    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_cost_cents    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  prepared_by            BIGINT UNSIGNED NULL,
  approved_by            BIGINT UNSIGNED NULL,
  approved_at            DATETIME        NULL,
  locked_at              DATETIME        NULL,
  created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY payroll_runs_company_period (company_id, period),
  KEY payroll_runs_company_status (company_id, status),
  KEY payroll_runs_parent (parent_run_id),
  CONSTRAINT payroll_runs_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT payroll_runs_rate_fk FOREIGN KEY (rate_version_id) REFERENCES rate_versions (id),
  CONSTRAINT payroll_runs_parent_fk FOREIGN KEY (parent_run_id) REFERENCES payroll_runs (id) ON DELETE SET NULL,
  CONSTRAINT payroll_runs_preparer_fk FOREIGN KEY (prepared_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT payroll_runs_approver_fk FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_lines (
  id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id                       BIGINT UNSIGNED NOT NULL,
  employee_id                  BIGINT UNSIGNED NOT NULL,
  employee_no                  VARCHAR(30)     NOT NULL,
  employee_name                VARCHAR(160)    NOT NULL,
  gross_cents                  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  taxable_cents                BIGINT UNSIGNED NOT NULL DEFAULT 0,
  paye_cents                   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  nssf_employee_cents          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  nssf_employer_cents          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  shif_cents                   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  housing_levy_employee_cents  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  housing_levy_employer_cents  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  nita_cents                   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  deductions_cents             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  net_cents                    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_cost_cents          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  breakdown                    JSON            NULL,
  PRIMARY KEY (id),
  UNIQUE KEY payroll_lines_run_employee (run_id, employee_id),
  KEY payroll_lines_employee (employee_id),
  CONSTRAINT payroll_lines_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE,
  CONSTRAINT payroll_lines_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_line_items (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  line_id      BIGINT UNSIGNED NOT NULL,
  pay_item_id  BIGINT UNSIGNED NULL,
  label        VARCHAR(120)    NOT NULL,
  kind         VARCHAR(20)     NOT NULL,
  amount_cents BIGINT          NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY payroll_line_items_line (line_id),
  KEY payroll_line_items_item (pay_item_id),
  KEY payroll_line_items_company (company_id),
  CONSTRAINT payroll_line_items_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT payroll_line_items_line_fk FOREIGN KEY (line_id) REFERENCES payroll_lines (id) ON DELETE CASCADE,
  CONSTRAINT payroll_line_items_item_fk FOREIGN KEY (pay_item_id) REFERENCES pay_items (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_exports (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  run_id       BIGINT UNSIGNED NOT NULL,
  type         VARCHAR(40)     NOT NULL,
  file_ref     VARCHAR(255)    NOT NULL,
  generated_by BIGINT UNSIGNED NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY payroll_exports_run (run_id, type),
  KEY payroll_exports_company (company_id),
  CONSTRAINT payroll_exports_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT payroll_exports_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE,
  CONSTRAINT payroll_exports_generator_fk FOREIGN KEY (generated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payslip_deliveries (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  line_id    BIGINT UNSIGNED NOT NULL,
  channel    VARCHAR(20)     NOT NULL,
  sent_at    DATETIME        NULL,
  status     VARCHAR(20)     NOT NULL DEFAULT 'queued',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY payslip_deliveries_line (line_id),
  KEY payslip_deliveries_company (company_id),
  CONSTRAINT payslip_deliveries_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT payslip_deliveries_line_fk FOREIGN KEY (line_id) REFERENCES payroll_lines (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO rate_versions (id, label, effective_from, verified_on, payload) VALUES
(
  'ke-2025.02', 'February 2025', '2025-02-01', '2025-11-01',
  '{
    "paye": {
      "bands": [
        {"up_to": 24000, "rate": 0.10},
        {"up_to": 32333, "rate": 0.25},
        {"up_to": 500000, "rate": 0.30},
        {"up_to": 800000, "rate": 0.325},
        {"up_to": null, "rate": 0.35}
      ],
      "personal_relief_monthly": 2400,
      "non_cash_benefit_threshold_monthly": 5000
    },
    "nssf": {"employee_rate": 0.06, "employer_rate": 0.06, "tier1_pay_ceiling": 8000, "upper_earnings_limit": 72000},
    "shif": {"employee_rate": 0.0275, "minimum_monthly": 300},
    "housing_levy": {"employee_rate": 0.015, "employer_rate": 0.015},
    "nita": {"employer_monthly": 50},
    "reliefs": {
      "pension_cap_monthly": 30000,
      "mortgage_interest_cap_monthly": 30000,
      "insurance_relief_rate": 0.15,
      "insurance_relief_cap_monthly": 5000
    }
  }'
),
(
  'ke-2026.02', 'February 2026', '2026-02-01', '2026-11-11',
  '{
    "paye": {
      "bands": [
        {"up_to": 24000, "rate": 0.10},
        {"up_to": 32333, "rate": 0.25},
        {"up_to": 500000, "rate": 0.30},
        {"up_to": 800000, "rate": 0.325},
        {"up_to": null, "rate": 0.35}
      ],
      "personal_relief_monthly": 2400,
      "non_cash_benefit_threshold_monthly": 5000
    },
    "nssf": {"employee_rate": 0.06, "employer_rate": 0.06, "tier1_pay_ceiling": 9000, "upper_earnings_limit": 108000},
    "shif": {"employee_rate": 0.0275, "minimum_monthly": 300},
    "housing_levy": {"employee_rate": 0.015, "employer_rate": 0.015},
    "nita": {"employer_monthly": 50},
    "reliefs": {
      "pension_cap_monthly": 30000,
      "mortgage_interest_cap_monthly": 30000,
      "insurance_relief_rate": 0.15,
      "insurance_relief_cap_monthly": 5000
    }
  }'
)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  effective_from = VALUES(effective_from),
  verified_on = VALUES(verified_on),
  payload = VALUES(payload);
