-- Leave: types and requests.
--
-- A request is the record of what was asked for and who decided it. Approved
-- requests are never edited; a cancellation or correction is a new decision on
-- a new request. Days are decimals so half-days are representable.

CREATE TABLE IF NOT EXISTS leave_types (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id        BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(80)     NOT NULL,
  days_per_year     DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  paid              TINYINT(1)      NOT NULL DEFAULT 1,
  requires_document TINYINT(1)      NOT NULL DEFAULT 0,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY leave_types_company_name (company_id, name),
  CONSTRAINT leave_types_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
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

-- Every company starts with the statutory leave kinds. A company may add its
-- own types later; the unique key keeps this provisioning safe to repeat.
INSERT INTO leave_types (company_id, name, days_per_year, paid, requires_document)
SELECT c.id, d.name, d.days_per_year, d.paid, d.requires_document
FROM companies c
JOIN (
  SELECT 'Annual leave' AS name, 21.00 AS days_per_year, 1 AS paid, 0 AS requires_document
  UNION ALL SELECT 'Sick leave', 14.00, 1, 1
  UNION ALL SELECT 'Maternity leave', 90.00, 1, 1
  UNION ALL SELECT 'Paternity leave', 14.00, 1, 1
  UNION ALL SELECT 'Compassionate leave', 5.00, 1, 0
) d
WHERE NOT EXISTS (
  SELECT 1 FROM leave_types lt WHERE lt.company_id = c.id AND lt.name = d.name
);

UPDATE modules
SET status = 'Live',
    description = 'Leave types, requests and the approval decision.'
WHERE `key` = 'leave-management';
