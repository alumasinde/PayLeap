-- Statutory returns, general ledger mapping and saved report definitions.

CREATE TABLE IF NOT EXISTS statutory_returns (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  type         VARCHAR(20)     NOT NULL,
  period       CHAR(7)         NOT NULL,
  run_id       BIGINT UNSIGNED NULL,
  file_ref     VARCHAR(255)    NULL,
  filed_at     DATETIME        NULL,
  reference_no VARCHAR(120)    NULL,
  status       VARCHAR(20)     NOT NULL DEFAULT 'pending',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY statutory_returns_company_period (company_id, type, period),
  KEY statutory_returns_run (run_id),
  CONSTRAINT statutory_returns_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT statutory_returns_run_fk FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gl_accounts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  code       VARCHAR(20)     NOT NULL,
  name       VARCHAR(120)    NOT NULL,
  type       VARCHAR(20)     NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY gl_accounts_company_code (company_id, code),
  CONSTRAINT gl_accounts_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gl_mappings (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id       BIGINT UNSIGNED NOT NULL,
  pay_item_key     VARCHAR(80)     NOT NULL,
  debit_account    VARCHAR(20)     NOT NULL,
  credit_account   VARCHAR(20)     NOT NULL,
  department_split JSON            NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY gl_mappings_company_item (company_id, pay_item_key),
  CONSTRAINT gl_mappings_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_definitions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  `key`      VARCHAR(80)     NOT NULL,
  name       VARCHAR(120)    NOT NULL,
  filters    JSON            NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY report_definitions_company_key (company_id, `key`),
  CONSTRAINT report_definitions_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
