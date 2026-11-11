-- Payroll: statutory rate versions, runs and their lines.
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

CREATE TABLE IF NOT EXISTS payroll_runs (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id           BIGINT UNSIGNED NOT NULL,
  period               CHAR(7)         NOT NULL,
  label                VARCHAR(120)    NOT NULL,
  status               VARCHAR(20)     NOT NULL DEFAULT 'draft',
  rate_version_id      VARCHAR(24)     NOT NULL,
  employee_count       INT UNSIGNED    NOT NULL DEFAULT 0,
  gross_cents          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  deductions_cents     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  net_cents            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_nssf_cents  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_housing_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_nita_cents  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_cost_cents  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  prepared_by          BIGINT UNSIGNED NULL,
  approved_by          BIGINT UNSIGNED NULL,
  approved_at          DATETIME        NULL,
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY payroll_runs_company_period (company_id, period),
  KEY payroll_runs_company_status (company_id, status),
  CONSTRAINT payroll_runs_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT payroll_runs_rate_fk FOREIGN KEY (rate_version_id) REFERENCES rate_versions (id),
  CONSTRAINT payroll_runs_preparer_fk FOREIGN KEY (prepared_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT payroll_runs_approver_fk FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_lines (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id                BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  employee_no           VARCHAR(30)     NOT NULL,
  employee_name         VARCHAR(160)    NOT NULL,
  gross_cents           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  taxable_cents         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  nssf_cents            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  shif_cents            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  housing_levy_cents    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  paye_cents            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  deductions_cents      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  net_cents             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_nssf_cents   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_housing_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_nita_cents   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  employer_cost_cents   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  breakdown             JSON            NULL,
  PRIMARY KEY (id),
  UNIQUE KEY payroll_lines_run_employee (run_id, employee_id),
  KEY payroll_lines_employee (employee_id),
  CONSTRAINT payroll_lines_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE,
  CONSTRAINT payroll_lines_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
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

UPDATE modules
SET status = 'Live',
    description = 'Monthly runs, statutory deductions, employer cost and approval.'
WHERE `key` = 'payroll';
