-- Dynamic RBAC: modules, permissions, roles, role_permissions, user_roles.
--
-- System role templates live with company_id NULL. Every company gets its own
-- copy on creation, so a company can change what its roles allow without a
-- deploy and without affecting another tenant.

CREATE TABLE IF NOT EXISTS modules (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`          VARCHAR(40)     NOT NULL,
  name           VARCHAR(80)     NOT NULL,
  description    VARCHAR(255)    NULL,
  status         VARCHAR(20)     NOT NULL DEFAULT 'Planned',
  permission_key VARCHAR(80)     NULL,
  sort_order     INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY modules_key_unique (`key`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`       VARCHAR(80)     NOT NULL,
  module_id   BIGINT UNSIGNED NULL,
  name        VARCHAR(120)    NOT NULL,
  description VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY permissions_key_unique (`key`),
  KEY permissions_module (module_id),
  CONSTRAINT permissions_module_fk FOREIGN KEY (module_id) REFERENCES modules (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NULL,
  `key`      VARCHAR(40)     NOT NULL,
  name       VARCHAR(80)     NOT NULL,
  is_system  TINYINT(1)      NOT NULL DEFAULT 0,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY roles_company_key (company_id, `key`),
  CONSTRAINT roles_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT role_permissions_role_fk FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT role_permissions_permission_fk FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id     BIGINT UNSIGNED NOT NULL,
  company_id  BIGINT UNSIGNED NOT NULL,
  role_id     BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, company_id, role_id),
  KEY user_roles_company (company_id, role_id),
  CONSTRAINT user_roles_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT user_roles_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT user_roles_role_fk FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ modules

INSERT INTO modules (`key`, name, description, status, permission_key, sort_order) VALUES
  ('employees', 'Employees', 'People, contracts, statutory numbers, documents.', 'Next', 'employees.view', 10),
  ('payroll', 'Payroll', 'Runs, payslips, statutory lines, employer cost.', 'Planned', 'payroll.run', 20),
  ('leave-management', 'Leave', 'Types, balances, approvals.', 'Planned', 'leave.view', 30),
  ('attendance', 'Attendance', 'Days, hours, overtime, sites.', 'Planned', 'attendance.view', 40),
  ('casual-management', 'Casual', 'Day rates, muster, weekly pay.', 'Planned', 'attendance.view', 50),
  ('loans', 'Loans', 'Loans, advances, deduction priority.', 'Planned', 'payroll.view', 60),
  ('reports', 'Reports', 'P9, P10, statutory returns, GL.', 'Planned', 'reports.view', 70),
  ('self-service', 'Self service', 'Payslips, leave requests, timesheets.', 'Planned', 'employees.view', 80);

-- -------------------------------------------------------------- permissions

INSERT INTO permissions (`key`, module_id, name, description) VALUES
  ('company.manage', NULL, 'Manage company', 'Company settings and details'),
  ('roles.manage', NULL, 'Manage roles', 'Create roles and change what they allow'),
  ('users.manage', NULL, 'Manage users', 'Change memberships and role assignments'),
  ('users.invite', NULL, 'Invite users', 'Send invitations to join the company'),
  ('audit.view', NULL, 'View audit trail', 'Read the company audit log'),
  ('employees.view', (SELECT id FROM modules WHERE `key` = 'employees'), 'View employees', 'See the employee register'),
  ('employees.edit', (SELECT id FROM modules WHERE `key` = 'employees'), 'Edit employees', 'Create and change employee records'),
  ('employees.salary.view', (SELECT id FROM modules WHERE `key` = 'employees'), 'View salaries', 'See salary amounts and structures'),
  ('employees.bank.view', (SELECT id FROM modules WHERE `key` = 'employees'), 'View bank details', 'See bank and payment details'),
  ('payroll.view', (SELECT id FROM modules WHERE `key` = 'payroll'), 'View payroll', 'See payroll runs and payslips'),
  ('payroll.run', (SELECT id FROM modules WHERE `key` = 'payroll'), 'Run payroll', 'Prepare and calculate a payroll run'),
  ('payroll.approve', (SELECT id FROM modules WHERE `key` = 'payroll'), 'Approve payroll', 'Approve a prepared payroll run'),
  ('payment.release', (SELECT id FROM modules WHERE `key` = 'payroll'), 'Release payments', 'Release approved payments'),
  ('leave.view', (SELECT id FROM modules WHERE `key` = 'leave-management'), 'View leave', 'See leave requests and balances'),
  ('leave.approve.team', (SELECT id FROM modules WHERE `key` = 'leave-management'), 'Approve team leave', 'Approve leave for your team'),
  ('attendance.view', (SELECT id FROM modules WHERE `key` = 'attendance'), 'View attendance', 'See days, hours and overtime'),
  ('reports.view', (SELECT id FROM modules WHERE `key` = 'reports'), 'View reports', 'Run and read company reports');

-- ------------------------------------------------------------ system roles

INSERT INTO roles (company_id, `key`, name, is_system) VALUES
  (NULL, 'owner', 'Owner', 1),
  (NULL, 'admin', 'Administrator', 1),
  (NULL, 'hr', 'HR', 1),
  (NULL, 'finance', 'Finance', 1),
  (NULL, 'hod', 'Head of department', 1),
  (NULL, 'employee', 'Employee', 1),
  (NULL, 'auditor', 'Auditor', 1);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.company_id IS NULL AND r.`key` = 'owner';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.`key` IN (
  'company.manage', 'users.manage', 'users.invite', 'audit.view',
  'employees.view', 'employees.edit', 'employees.salary.view', 'employees.bank.view',
  'payroll.view', 'payroll.run', 'payroll.approve', 'payment.release',
  'leave.view', 'leave.approve.team', 'attendance.view', 'reports.view'
)
WHERE r.company_id IS NULL AND r.`key` = 'admin';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.`key` IN (
  'users.invite', 'employees.view', 'employees.edit', 'employees.salary.view',
  'leave.view', 'leave.approve.team', 'attendance.view', 'reports.view'
)
WHERE r.company_id IS NULL AND r.`key` = 'hr';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.`key` IN (
  'employees.view', 'employees.salary.view', 'employees.bank.view',
  'payroll.view', 'payroll.run', 'payroll.approve', 'payment.release',
  'reports.view', 'audit.view'
)
WHERE r.company_id IS NULL AND r.`key` = 'finance';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.`key` IN (
  'employees.view', 'leave.view', 'leave.approve.team', 'attendance.view'
)
WHERE r.company_id IS NULL AND r.`key` = 'hod';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.`key` IN (
  'employees.view', 'employees.salary.view', 'payroll.view', 'reports.view', 'audit.view'
)
WHERE r.company_id IS NULL AND r.`key` = 'auditor';

-- ------------------------------------------------- give existing companies
-- their own copy of every template

INSERT INTO roles (company_id, `key`, name, is_system)
SELECT c.id, sr.`key`, sr.name, 0
FROM companies c
JOIN roles sr ON sr.company_id IS NULL;

INSERT INTO role_permissions (role_id, permission_id)
SELECT cr.id, rp.permission_id
FROM roles cr
JOIN roles sr ON sr.company_id IS NULL AND sr.`key` = cr.`key`
JOIN role_permissions rp ON rp.role_id = sr.id
WHERE cr.company_id IS NOT NULL;

-- ------------------------------------------------ invitations point at roles

ALTER TABLE invitations ADD COLUMN role_id BIGINT UNSIGNED NULL AFTER role;

UPDATE invitations i
JOIN roles r ON r.company_id = i.company_id AND r.`key` = i.role
SET i.role_id = r.id;

UPDATE invitations SET role_id = (
  SELECT MIN(id) FROM roles WHERE company_id = invitations.company_id
) WHERE role_id IS NULL;

ALTER TABLE invitations
  MODIFY role_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT invitations_role_fk FOREIGN KEY (role_id) REFERENCES roles (id);

ALTER TABLE invitations DROP COLUMN role;

-- ------------------------------------------------- memberships carry no role

INSERT INTO user_roles (user_id, company_id, role_id)
SELECT m.user_id, m.company_id, r.id
FROM memberships m
JOIN roles r ON r.company_id = m.company_id AND r.`key` = m.role;

ALTER TABLE memberships DROP COLUMN role;
