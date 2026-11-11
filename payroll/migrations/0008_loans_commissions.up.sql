-- Loans and advances, commissions, service charge, final dues and claims.

CREATE TABLE IF NOT EXISTS currencies (
  code       CHAR(3)         NOT NULL,
  name       VARCHAR(80)     NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO currencies (code, name) VALUES
  ('KES', 'Kenyan shilling'),
  ('USD', 'US dollar'),
  ('EUR', 'Euro'),
  ('GBP', 'Pound sterling'),
  ('UGX', 'Ugandan shilling'),
  ('TZS', 'Tanzanian shilling')
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE IF NOT EXISTS exchange_rates (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     BIGINT UNSIGNED NOT NULL,
  currency_code  CHAR(3)         NOT NULL,
  rate           DECIMAL(18,8)   NOT NULL,
  effective_date DATE            NOT NULL,
  run_id         BIGINT UNSIGNED NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY exchange_rates_run_currency_date (run_id, currency_code, effective_date),
  KEY exchange_rates_company (company_id),
  KEY exchange_rates_currency (currency_code),
  CONSTRAINT exchange_rates_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT exchange_rates_currency_fk FOREIGN KEY (currency_code) REFERENCES currencies (code),
  CONSTRAINT exchange_rates_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loans (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  employee_id     BIGINT UNSIGNED NOT NULL,
  type            VARCHAR(20)     NOT NULL DEFAULT 'company',
  principal_cents BIGINT UNSIGNED NOT NULL,
  rate_bp         INT UNSIGNED    NOT NULL DEFAULT 0,
  installments    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  start_period    CHAR(7)         NOT NULL,
  status          VARCHAR(20)     NOT NULL DEFAULT 'active',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY loans_employee_status (employee_id, status),
  KEY loans_company (company_id),
  CONSTRAINT loans_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT loans_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_schedule (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  loan_id         BIGINT UNSIGNED NOT NULL,
  due_period      CHAR(7)         NOT NULL,
  principal_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  interest_cents  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  paid            TINYINT(1)      NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY loan_schedule_loan_period (loan_id, due_period),
  KEY loan_schedule_company (company_id),
  CONSTRAINT loan_schedule_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT loan_schedule_loan_fk FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advance_requests (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  amount_cents BIGINT UNSIGNED NOT NULL,
  installments SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status       VARCHAR(20)     NOT NULL DEFAULT 'pending',
  decided_by   BIGINT UNSIGNED NULL,
  decided_at   DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY advance_requests_employee (employee_id, status),
  KEY advance_requests_company (company_id),
  CONSTRAINT advance_requests_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT advance_requests_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT advance_requests_decider_fk FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_rules (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(120)    NOT NULL,
  type       VARCHAR(20)     NOT NULL DEFAULT 'percent',
  definition JSON            NULL,
  active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY commission_rules_company_name (company_id, name),
  CONSTRAINT commission_rules_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_entries (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  rule_id      BIGINT UNSIGNED NULL,
  period       CHAR(7)         NOT NULL,
  base_cents   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY commission_entries_employee (employee_id, period),
  KEY commission_entries_company (company_id),
  CONSTRAINT commission_entries_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT commission_entries_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT commission_entries_rule_fk FOREIGN KEY (rule_id) REFERENCES commission_rules (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_charge_pools (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  period      CHAR(7)         NOT NULL,
  total_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rule        JSON            NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY service_charge_pools_company_period (company_id, period),
  CONSTRAINT service_charge_pools_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_charge_shares (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  pool_id      BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  points       DECIMAL(8,2)    NOT NULL DEFAULT 0.00,
  amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY service_charge_shares_pool_employee (pool_id, employee_id),
  KEY service_charge_shares_company (company_id),
  CONSTRAINT service_charge_shares_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT service_charge_shares_pool_fk FOREIGN KEY (pool_id) REFERENCES service_charge_pools (id) ON DELETE CASCADE,
  CONSTRAINT service_charge_shares_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS final_dues (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id          BIGINT UNSIGNED NOT NULL,
  employee_id         BIGINT UNSIGNED NOT NULL,
  leave_payout_cents  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  notice_cents        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  severance_cents     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  service_pay_cents   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  other_cents         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_cents         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status              VARCHAR(20)     NOT NULL DEFAULT 'draft',
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY final_dues_employee (employee_id),
  KEY final_dues_company (company_id),
  CONSTRAINT final_dues_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT final_dues_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_claims (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  category     VARCHAR(60)     NOT NULL,
  amount_cents BIGINT UNSIGNED NOT NULL,
  receipt_ref  VARCHAR(255)    NULL,
  status       VARCHAR(20)     NOT NULL DEFAULT 'pending',
  decided_by   BIGINT UNSIGNED NULL,
  decided_at   DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY expense_claims_employee (employee_id, status),
  KEY expense_claims_company (company_id),
  CONSTRAINT expense_claims_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT expense_claims_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT expense_claims_decider_fk FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loss_damage_claims (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id           BIGINT UNSIGNED NOT NULL,
  employee_id          BIGINT UNSIGNED NOT NULL,
  description          VARCHAR(255)    NOT NULL,
  amount_cents         BIGINT UNSIGNED NOT NULL,
  recover_via_payroll  TINYINT(1)      NOT NULL DEFAULT 1,
  status               VARCHAR(20)     NOT NULL DEFAULT 'pending',
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY loss_damage_claims_employee (employee_id, status),
  KEY loss_damage_claims_company (company_id),
  CONSTRAINT loss_damage_claims_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT loss_damage_claims_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
