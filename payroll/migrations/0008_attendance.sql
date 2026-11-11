-- Attendance: one row per person per day.
--
-- The unique key is the idempotency rule: marking the same day twice corrects
-- the existing row instead of creating a second one. Approved leave lives in
-- leave_requests; an attendance row with status 'leave' is the clock record for
-- that day, not a second source of leave entitlement.

CREATE TABLE IF NOT EXISTS attendance_records (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  work_date   DATE            NOT NULL,
  clock_in    TIME            NULL,
  clock_out   TIME            NULL,
  status      VARCHAR(20)     NOT NULL DEFAULT 'present',
  source      VARCHAR(20)     NOT NULL DEFAULT 'manual',
  note        VARCHAR(255)    NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY attendance_employee_day (employee_id, work_date),
  KEY attendance_company_day_status (company_id, work_date, status),
  CONSTRAINT attendance_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT attendance_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT attendance_recorder_fk FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (`key`, module_id, name, description)
SELECT 'attendance.edit', id, 'Edit attendance', 'Mark and correct daily attendance'
FROM modules
WHERE `key` = 'attendance';

-- Owner, administrator and HR mark attendance. The grant covers both the
-- system templates and every company's copy of those roles.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.`key` = 'attendance.edit'
WHERE r.`key` IN ('owner', 'admin', 'hr');

UPDATE modules
SET status = 'Live',
    description = 'Daily attendance: present, late, absent and approved leave.'
WHERE `key` = 'attendance';
