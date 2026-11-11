-- Leave: policy by type, optional balances, requests and the approval trail.
--
-- A request is the record of what was asked for and who decided it. Approved
-- requests are never edited; a cancellation or correction is a new decision on
-- a new request. Days are decimals so half-days are representable.

CREATE TABLE IF NOT EXISTS leave_types (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id        BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(80)     NOT NULL,
  days_per_year     DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  accrual_rule      VARCHAR(40)     NOT NULL DEFAULT 'fixed',
  paid              TINYINT(1)      NOT NULL DEFAULT 1,
  gender_restriction VARCHAR(10)    NOT NULL DEFAULT 'any',
  requires_document TINYINT(1)      NOT NULL DEFAULT 0,
  carry_over_max    DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  active            TINYINT(1)      NOT NULL DEFAULT 1,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY leave_types_company_name (company_id, name),
  CONSTRAINT leave_types_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_policies (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NOT NULL,
  leave_type_id BIGINT UNSIGNED NOT NULL,
  applies_to    VARCHAR(60)     NULL,
  entitlement   DECIMAL(5,2)    NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY leave_policies_type (leave_type_id),
  KEY leave_policies_company (company_id),
  CONSTRAINT leave_policies_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT leave_policies_type_fk FOREIGN KEY (leave_type_id) REFERENCES leave_types (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Balances exist for companies that want an explicit ledger. Today the app
-- derives entitlement, taken, pending and remaining from leave_types and
-- approved requests, so this table starts empty.
CREATE TABLE IF NOT EXISTS leave_balances (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NOT NULL,
  employee_id   BIGINT UNSIGNED NOT NULL,
  leave_type_id BIGINT UNSIGNED NOT NULL,
  `year`        SMALLINT UNSIGNED NOT NULL,
  entitled      DECIMAL(6,2)    NOT NULL DEFAULT 0.00,
  taken         DECIMAL(6,2)    NOT NULL DEFAULT 0.00,
  carried_over  DECIMAL(6,2)    NOT NULL DEFAULT 0.00,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY leave_balances_employee_type_year (employee_id, leave_type_id, `year`),
  KEY leave_balances_company (company_id),
  CONSTRAINT leave_balances_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT leave_balances_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT leave_balances_type_fk FOREIGN KEY (leave_type_id) REFERENCES leave_types (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_requests (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NOT NULL,
  employee_id   BIGINT UNSIGNED NOT NULL,
  leave_type_id BIGINT UNSIGNED NOT NULL,
  start_date    DATE            NOT NULL,
  end_date      DATE            NOT NULL,
  days          DECIMAL(5,2)    NOT NULL,
  reason        VARCHAR(255)    NULL,
  status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
  decided_by    BIGINT UNSIGNED NULL,
  decided_at    DATETIME        NULL,
  decision_note VARCHAR(255)    NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY leave_requests_company_status (company_id, status),
  KEY leave_requests_employee_dates (employee_id, start_date, end_date),
  KEY leave_requests_type (leave_type_id),
  CONSTRAINT leave_requests_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT leave_requests_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT leave_requests_type_fk FOREIGN KEY (leave_type_id) REFERENCES leave_types (id),
  CONSTRAINT leave_requests_decider_fk FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_workflows (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id        BIGINT UNSIGNED NOT NULL,
  entity_type       VARCHAR(40)     NOT NULL,
  step_no           TINYINT UNSIGNED NOT NULL,
  approver_type     VARCHAR(20)     NOT NULL DEFAULT 'manager',
  approver_role_id  BIGINT UNSIGNED NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY approval_workflows_step (company_id, entity_type, step_no),
  KEY approval_workflows_role (approver_role_id),
  CONSTRAINT approval_workflows_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT approval_workflows_role_fk FOREIGN KEY (approver_role_id) REFERENCES roles (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approvals (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  entity_type  VARCHAR(40)     NOT NULL,
  entity_id    BIGINT UNSIGNED NOT NULL,
  step_no      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  approver_id  BIGINT UNSIGNED NULL,
  decision     VARCHAR(20)     NOT NULL,
  decided_at   DATETIME        NULL,
  note         VARCHAR(255)    NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY approvals_entity (company_id, entity_type, entity_id),
  KEY approvals_approver (approver_id),
  CONSTRAINT approvals_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT approvals_approver_fk FOREIGN KEY (approver_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Every company starts with the statutory leave kinds. A company may add its
-- own types later; the unique key keeps this provisioning safe to repeat.
INSERT INTO leave_types (company_id, name, days_per_year, paid, requires_document, gender_restriction)
SELECT c.id, d.name, d.days_per_year, d.paid, d.requires_document, d.gender_restriction
FROM companies c
JOIN (
  SELECT 'Annual leave' AS name, 21.00 AS days_per_year, 1 AS paid, 0 AS requires_document, 'any' AS gender_restriction
  UNION ALL SELECT 'Sick leave', 14.00, 1, 1, 'any'
  UNION ALL SELECT 'Maternity leave', 90.00, 1, 1, 'female'
  UNION ALL SELECT 'Paternity leave', 14.00, 1, 1, 'male'
  UNION ALL SELECT 'Compassionate leave', 5.00, 1, 0, 'any'
) d
WHERE NOT EXISTS (
  SELECT 1 FROM leave_types lt WHERE lt.company_id = c.id AND lt.name = d.name
);
