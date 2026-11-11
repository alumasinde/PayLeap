-- Attendance: public holidays, shifts, rosters, terminals and daily records.
--
-- The unique key on attendance is the idempotency rule: marking the same day
-- twice corrects the existing row instead of creating a second one. Approved
-- leave lives in leave_requests; an attendance row with status 'leave' is the
-- clock record for that day, not a second source of leave entitlement.

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

CREATE TABLE IF NOT EXISTS shifts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(80)     NOT NULL,
  start_time    TIME            NOT NULL,
  end_time      TIME            NOT NULL,
  break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active        TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY shifts_company_name (company_id, name),
  CONSTRAINT shifts_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_entries (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  work_date   DATE            NOT NULL,
  shift_id    BIGINT UNSIGNED NULL,
  status      VARCHAR(20)     NOT NULL DEFAULT 'shift',
  note        VARCHAR(255)    NULL,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY roster_employee_day (employee_id, work_date),
  KEY roster_company_day (company_id, work_date),
  CONSTRAINT roster_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT roster_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT roster_shift_fk FOREIGN KEY (shift_id) REFERENCES shifts (id) ON DELETE SET NULL,
  CONSTRAINT roster_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS attendance_records (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  work_date   DATE            NOT NULL,
  clock_in    TIME            NULL,
  clock_out   TIME            NULL,
  status      VARCHAR(20)     NOT NULL DEFAULT 'present',
  source      VARCHAR(20)     NOT NULL DEFAULT 'manual',
  device_id   BIGINT UNSIGNED NULL,
  note        VARCHAR(255)    NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY attendance_employee_day (employee_id, work_date),
  KEY attendance_company_day_status (company_id, work_date, status),
  KEY attendance_device (device_id),
  CONSTRAINT attendance_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT attendance_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT attendance_device_fk FOREIGN KEY (device_id) REFERENCES attendance_devices (id) ON DELETE SET NULL,
  CONSTRAINT attendance_recorder_fk FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS overtime_claims (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  employee_id     BIGINT UNSIGNED NOT NULL,
  claim_date      DATE            NOT NULL,
  hours           DECIMAL(5,2)    NOT NULL,
  day_type        VARCHAR(20)     NOT NULL DEFAULT 'normal',
  rate_multiplier DECIMAL(4,2)    NOT NULL DEFAULT 1.50,
  status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
  approved_by     BIGINT UNSIGNED NULL,
  approved_at     DATETIME        NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY overtime_claims_employee (employee_id, claim_date),
  KEY overtime_claims_company_status (company_id, status),
  CONSTRAINT overtime_claims_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT overtime_claims_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT overtime_claims_approver_fk FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- New and existing companies start with a day, early and late shift.
INSERT INTO shifts (company_id, name, start_time, end_time, break_minutes)
SELECT c.id, d.name, d.start_time, d.end_time, d.break_minutes
FROM companies c
JOIN (
  SELECT 'Day' AS name, '08:00:00' AS start_time, '17:00:00' AS end_time, 60 AS break_minutes
  UNION ALL SELECT 'Early', '06:30:00', '14:30:00', 30
  UNION ALL SELECT 'Late', '14:00:00', '22:00:00', 30
) d
WHERE NOT EXISTS (
  SELECT 1 FROM shifts s WHERE s.company_id = c.id AND s.name = d.name
);

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
