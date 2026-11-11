-- Contracts, onboarding and exit, discipline, and issued assets.

CREATE TABLE IF NOT EXISTS contract_templates (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(120)    NOT NULL,
  body       MEDIUMTEXT      NOT NULL,
  variables  JSON            NULL,
  active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY contract_templates_company_name (company_id, name),
  CONSTRAINT contract_templates_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contracts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NOT NULL,
  employee_id   BIGINT UNSIGNED NOT NULL,
  template_id   BIGINT UNSIGNED NULL,
  rendered_file VARCHAR(255)    NULL,
  signed_at     DATETIME        NULL,
  start_date    DATE            NULL,
  end_date      DATE            NULL,
  status        VARCHAR(20)     NOT NULL DEFAULT 'draft',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY contracts_employee (employee_id),
  KEY contracts_company (company_id),
  KEY contracts_template (template_id),
  CONSTRAINT contracts_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT contracts_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT contracts_template_fk FOREIGN KEY (template_id) REFERENCES contract_templates (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS onboarding_tasks (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  task        VARCHAR(255)    NOT NULL,
  done_by     BIGINT UNSIGNED NULL,
  done_at     DATETIME        NULL,
  status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY onboarding_tasks_employee (employee_id, status),
  KEY onboarding_tasks_company (company_id),
  CONSTRAINT onboarding_tasks_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT onboarding_tasks_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT onboarding_tasks_done_by_fk FOREIGN KEY (done_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exit_checklists (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  task        VARCHAR(255)    NOT NULL,
  done_by     BIGINT UNSIGNED NULL,
  done_at     DATETIME        NULL,
  status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY exit_checklists_employee (employee_id, status),
  KEY exit_checklists_company (company_id),
  CONSTRAINT exit_checklists_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT exit_checklists_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT exit_checklists_done_by_fk FOREIGN KEY (done_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disciplinary_cases (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  type         VARCHAR(60)     NOT NULL,
  case_date    DATE            NOT NULL,
  outcome      VARCHAR(255)    NULL,
  document_ref VARCHAR(255)    NULL,
  status       VARCHAR(20)     NOT NULL DEFAULT 'open',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY disciplinary_cases_employee (employee_id, case_date),
  KEY disciplinary_cases_company (company_id),
  CONSTRAINT disciplinary_cases_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT disciplinary_cases_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_issues (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  item         VARCHAR(120)    NOT NULL,
  issued_on    DATE            NOT NULL,
  returned_on  DATE            NULL,
  value_cents  BIGINT UNSIGNED NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY asset_issues_employee (employee_id),
  KEY asset_issues_company (company_id),
  CONSTRAINT asset_issues_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT asset_issues_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
