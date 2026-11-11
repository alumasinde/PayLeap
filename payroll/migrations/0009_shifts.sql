-- Shifts and rosters: what a day is supposed to look like, before anyone
-- clocks in. Attendance stays the record of what actually happened; the
-- roster supplies the target hours that late, lost and overtime are measured
-- against.

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

UPDATE modules
SET description = 'Daily register, shifts and rosters. Clock times can come from manual entry or a terminal.'
WHERE `key` = 'attendance';
