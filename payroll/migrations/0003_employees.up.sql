-- Employee register and the records that hang off it.
--
-- Personal identity stays on employees. Statutory numbers, the current
-- employment and primary bank account live in their own tables, so a person can
-- change bank or terms without rewriting the register row.

CREATE TABLE IF NOT EXISTS employees (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NULL,
  employee_no    VARCHAR(30)     NOT NULL,
  first_name     VARCHAR(80)     NOT NULL,
  middle_name    VARCHAR(80)     NULL,
  last_name      VARCHAR(80)     NOT NULL,
  dob            DATE            NULL,
  gender         VARCHAR(10)     NULL,
  national_id    VARCHAR(30)     NULL,
  phone          VARCHAR(40)     NULL,
  email          VARCHAR(190)    NULL,
  address        VARCHAR(255)    NULL,
  marital_status VARCHAR(20)     NULL,
  department_id  BIGINT UNSIGNED NULL,
  manager_id     BIGINT UNSIGNED NULL,
  status         VARCHAR(20)     NOT NULL DEFAULT 'active',
  exit_date      DATE            NULL,
  archived_at    DATETIME        NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY employees_company_no (company_id, employee_no),
  KEY employees_company_status (company_id, status),
  KEY employees_company_name (company_id, last_name, first_name),
  KEY employees_department (department_id),
  KEY employees_manager (manager_id),
  KEY employees_user (user_id),
  CONSTRAINT employees_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT employees_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT employees_department_fk FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
  CONSTRAINT employees_manager_fk FOREIGN KEY (manager_id) REFERENCES employees (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_statutory (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id              BIGINT UNSIGNED NOT NULL,
  employee_id             BIGINT UNSIGNED NOT NULL,
  kra_pin                 VARCHAR(20)     NULL,
  nssf_no                 VARCHAR(30)     NULL,
  sha_no                  VARCHAR(30)     NULL,
  is_resident             TINYINT(1)      NOT NULL DEFAULT 1,
  has_disability_exemption TINYINT(1)     NOT NULL DEFAULT 0,
  is_primary_employee     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY employee_statutory_employee (employee_id),
  KEY employee_statutory_company (company_id),
  CONSTRAINT employee_statutory_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT employee_statutory_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employments (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NOT NULL,
  employee_id   BIGINT UNSIGNED NOT NULL,
  type          VARCHAR(20)     NOT NULL DEFAULT 'permanent',
  start_date    DATE            NOT NULL,
  end_date      DATE            NULL,
  dept_id       BIGINT UNSIGNED NULL,
  branch_id     BIGINT UNSIGNED NULL,
  title_id      BIGINT UNSIGNED NULL,
  job_title     VARCHAR(80)     NULL,
  manager_id    BIGINT UNSIGNED NULL,
  probation_end DATE            NULL,
  pay_basis     VARCHAR(20)     NOT NULL DEFAULT 'monthly',
  salary_cents  BIGINT UNSIGNED NULL,
  salary_input  VARCHAR(20)     NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY employments_employee_start (employee_id, start_date),
  KEY employments_company (company_id),
  KEY employments_department (dept_id),
  KEY employments_branch (branch_id),
  KEY employments_title (title_id),
  KEY employments_manager (manager_id),
  CONSTRAINT employments_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT employments_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT employments_department_fk FOREIGN KEY (dept_id) REFERENCES departments (id) ON DELETE SET NULL,
  CONSTRAINT employments_branch_fk FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT employments_title_fk FOREIGN KEY (title_id) REFERENCES job_titles (id) ON DELETE SET NULL,
  CONSTRAINT employments_manager_fk FOREIGN KEY (manager_id) REFERENCES employees (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employment_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     BIGINT UNSIGNED NOT NULL,
  employee_id    BIGINT UNSIGNED NOT NULL,
  change_type    VARCHAR(40)     NOT NULL,
  from_value     JSON            NULL,
  to_value       JSON            NULL,
  effective_date DATE            NOT NULL,
  note           VARCHAR(255)    NULL,
  created_by     BIGINT UNSIGNED NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY employment_history_employee (employee_id, effective_date),
  KEY employment_history_company (company_id),
  CONSTRAINT employment_history_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT employment_history_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT employment_history_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_names (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bank_name        VARCHAR(80)     NOT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY bank_names_name (bank_name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_branches (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bank_id     BIGINT UNSIGNED NOT NULL,
  branch      VARCHAR(80)     NOT NULL,
  code        VARCHAR(20)     NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY bank_branches_bank (bank_id),
  CONSTRAINT bank_branches_bank_fk FOREIGN KEY (bank_id) REFERENCES bank_names (id) ON DELETE CASCADE,
  UNIQUE KEY bank_branches_bank_branch (bank_id, branch)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS employee_bank_accounts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NOT NULL,
  employee_id   BIGINT UNSIGNED NOT NULL,
  bank_id         BIGINT UNSIGNED NULL,
  branch_id     BIGINT UNSIGNED NULL,
  account_no    VARCHAR(40)     NULL,
  mpesa_no      VARCHAR(40)     NULL,
  is_primary    TINYINT(1)      NOT NULL DEFAULT 1,
  change_status VARCHAR(20)     NOT NULL DEFAULT 'active',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY employee_bank_employee (employee_id, is_primary),
  KEY employee_bank_company (company_id),
  KEY employee_bank_bank (bank_id),
  KEY employee_bank_branch (branch_id),
  CONSTRAINT employee_bank_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT employee_bank_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dependants (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(120)    NOT NULL,
  relationship VARCHAR(40)     NULL,
  dob          DATE            NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY dependants_employee (employee_id),
  KEY dependants_company (company_id),
  CONSTRAINT dependants_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT dependants_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS next_of_kin (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  employee_id  BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(120)    NOT NULL,
  relationship VARCHAR(40)     NULL,
  phone        VARCHAR(40)     NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY next_of_kin_employee (employee_id),
  KEY next_of_kin_company (company_id),
  CONSTRAINT next_of_kin_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT next_of_kin_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_documents (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  type        VARCHAR(40)     NOT NULL,
  file_ref    VARCHAR(255)    NOT NULL,
  issued      DATE            NULL,
  expires_at  DATE            NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY employee_documents_employee (employee_id, type),
  KEY employee_documents_company (company_id),
  CONSTRAINT employee_documents_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT employee_documents_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT employee_documents_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE departments
  ADD CONSTRAINT departments_head_fk FOREIGN KEY (head_employee_id) REFERENCES employees (id) ON DELETE SET NULL;
