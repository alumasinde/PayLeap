-- Employees: the register every other module reads.
--
-- Salary is stored in cents. Kenyan statutory identifiers are optional here
-- because casual staff are often onboarded before their papers arrive.

CREATE TABLE IF NOT EXISTS departments (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(80)     NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY departments_company_name (company_id, name),
  CONSTRAINT departments_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id           BIGINT UNSIGNED NOT NULL,
  user_id              BIGINT UNSIGNED NULL,
  employee_no          VARCHAR(30)     NOT NULL,
  first_name           VARCHAR(80)     NOT NULL,
  middle_name          VARCHAR(80)     NULL,
  last_name            VARCHAR(80)     NOT NULL,
  email                VARCHAR(190)    NULL,
  phone                VARCHAR(40)     NULL,
  national_id          VARCHAR(30)     NULL,
  kra_pin              VARCHAR(20)     NULL,
  nssf_number          VARCHAR(30)     NULL,
  shif_number          VARCHAR(30)     NULL,
  bank_name            VARCHAR(80)     NULL,
  bank_branch          VARCHAR(80)     NULL,
  bank_account         VARCHAR(40)     NULL,
  department_id        BIGINT UNSIGNED NULL,
  job_title            VARCHAR(80)     NULL,
  employment_type      VARCHAR(20)     NOT NULL DEFAULT 'permanent',
  monthly_salary_cents BIGINT UNSIGNED NULL,
  start_date           DATE            NOT NULL,
  exit_date            DATE            NULL,
  status               VARCHAR(20)     NOT NULL DEFAULT 'active',
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY employees_company_no (company_id, employee_no),
  KEY employees_company_status (company_id, status),
  KEY employees_company_name (company_id, last_name, first_name),
  CONSTRAINT employees_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT employees_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT employees_department_fk FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

UPDATE modules
SET status = 'Live',
    description = 'The register: people, statutory numbers, pay and bank details.'
WHERE `key` = 'employees';
