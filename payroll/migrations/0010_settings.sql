-- Settings and eligibility.
--
-- Employees gain the one field leave eligibility needs. Leave types become
-- configurable policy (who may take them, how many days, carry-over, active)
-- rather than a fixed list. Public holidays and attendance devices get their
-- own tables so the timesheet and the register can read them per company.

ALTER TABLE employees
  ADD COLUMN gender VARCHAR(10) NULL AFTER last_name;

ALTER TABLE leave_types
  ADD COLUMN gender_restriction VARCHAR(10)  NOT NULL DEFAULT 'any' AFTER paid,
  ADD COLUMN carry_over_max     DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER requires_document,
  ADD COLUMN active             TINYINT(1)   NOT NULL DEFAULT 1 AFTER carry_over_max;

UPDATE leave_types SET gender_restriction = 'female' WHERE name = 'Maternity leave';
UPDATE leave_types SET gender_restriction = 'male'   WHERE name = 'Paternity leave';

-- One holiday per company per day. Movable feasts (Easter, Eid) are added by
-- the company for the year they fall in; fixed statutory days are provisioned.
CREATE TABLE IF NOT EXISTS public_holidays (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  holiday_date DATE            NOT NULL,
  name         VARCHAR(100)    NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY public_holidays_company_day (company_id, holiday_date),
  CONSTRAINT public_holidays_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Clock and meal terminals. A serial is the device identity; leave it blank
-- until the terminal is installed.
CREATE TABLE IF NOT EXISTS attendance_devices (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(80)     NOT NULL,
  kind       VARCHAR(20)     NOT NULL DEFAULT 'biometric',
  purpose    VARCHAR(20)     NOT NULL DEFAULT 'attendance',
  serial     VARCHAR(80)     NULL,
  location   VARCHAR(80)     NULL,
  active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY attendance_devices_company_serial (company_id, serial),
  CONSTRAINT attendance_devices_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Settings are a company responsibility. HR may configure leave, holidays,
-- shifts and terminals; owner and administrator inherit the same grant.
INSERT IGNORE INTO permissions (`key`, module_id, name, description)
VALUES ('settings.manage', NULL, 'Manage settings', 'Company, leave, holiday, shift and device settings');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.`key` = 'settings.manage'
WHERE r.`key` IN ('owner', 'admin', 'hr');

-- Fixed statutory holidays for this year and next. A company may add the
-- movable ones and its own days in Settings.
INSERT INTO public_holidays (company_id, holiday_date, name)
SELECT c.id, d.holiday_date, d.name
FROM companies c
JOIN (
  SELECT DATE(CONCAT(YEAR(CURDATE()), '-01-01')) AS holiday_date, 'New Year''s Day' AS name
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()), '-05-01')), 'Labour Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()), '-06-01')), 'Madaraka Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()), '-10-20')), 'Mashujaa Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()), '-12-12')), 'Jamhuri Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()), '-12-25')), 'Christmas Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()), '-12-26')), 'Boxing Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()) + 1, '-01-01')), 'New Year''s Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()) + 1, '-05-01')), 'Labour Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()) + 1, '-06-01')), 'Madaraka Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()) + 1, '-10-20')), 'Mashujaa Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()) + 1, '-12-12')), 'Jamhuri Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()) + 1, '-12-25')), 'Christmas Day'
  UNION ALL SELECT DATE(CONCAT(YEAR(CURDATE()) + 1, '-12-26')), 'Boxing Day'
) d
WHERE NOT EXISTS (
  SELECT 1 FROM public_holidays h
  WHERE h.company_id = c.id AND h.holiday_date = d.holiday_date
);

UPDATE modules
SET description = 'Leave policy, requests, balances and public holidays.'
WHERE `key` = 'leave-management';

UPDATE modules
SET description = 'Register, timesheet, shift roster, holidays and clock devices.'
WHERE `key` = 'attendance';
