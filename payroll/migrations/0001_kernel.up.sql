-- Platform kernel: identity, tenancy, RBAC, audit, settings and queues.
-- All timestamps are UTC; the app sets time_zone = '+00:00' on connect.

CREATE TABLE IF NOT EXISTS users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name        VARCHAR(80)     NOT NULL,
  last_name         VARCHAR(80)     NOT NULL,
  email             VARCHAR(190)    NOT NULL,
  password_hash     VARCHAR(255)    NULL,
  email_verified_at DATETIME        NULL,
  session_epoch     INT UNSIGNED    NOT NULL DEFAULT 0,
  mfa_secret        VARCHAR(64)     NULL,
  status            VARCHAR(16)     NOT NULL DEFAULT 'invited',
  last_login_at     DATETIME        NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(160)    NOT NULL,
  slug             VARCHAR(80)     NOT NULL,
  kra_pin          VARCHAR(20)     NULL,
  nssf_no          VARCHAR(30)     NULL,
  sha_no           VARCHAR(30)     NULL,
  nita_no          VARCHAR(30)     NULL,
  logo_path        VARCHAR(255)    NULL,
  pay_day          TINYINT UNSIGNED NOT NULL DEFAULT 25,
  default_currency CHAR(3)         NOT NULL DEFAULT 'KES',
  status           VARCHAR(16)     NOT NULL DEFAULT 'active',
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY companies_slug_unique (slug)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

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
  module_key  VARCHAR(40)     NULL,
  name        VARCHAR(120)    NOT NULL,
  description VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY permissions_key_unique (`key`),
  KEY permissions_module (module_key),
  CONSTRAINT permissions_module_fk FOREIGN KEY (module_key) REFERENCES modules (`key`) ON DELETE SET NULL ON UPDATE CASCADE
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

CREATE TABLE IF NOT EXISTS memberships (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  status     VARCHAR(16)     NOT NULL DEFAULT 'active',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY memberships_unique (company_id, user_id),
  KEY memberships_user (user_id),
  CONSTRAINT memberships_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT memberships_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  membership_id BIGINT UNSIGNED NOT NULL,
  role_id       BIGINT UNSIGNED NOT NULL,
  assigned_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (membership_id, role_id),
  KEY user_roles_role (role_id),
  CONSTRAINT user_roles_membership_fk FOREIGN KEY (membership_id) REFERENCES memberships (id) ON DELETE CASCADE,
  CONSTRAINT user_roles_role_fk FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  email       VARCHAR(190)    NOT NULL,
  role_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64)        NOT NULL,
  invited_by  BIGINT UNSIGNED NULL,
  expires_at  DATETIME        NOT NULL,
  accepted_at DATETIME        NULL,
  revoked_at  DATETIME        NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY invitations_token_unique (token_hash),
  KEY invitations_email (company_id, email),
  KEY invitations_role (role_id),
  CONSTRAINT invitations_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT invitations_role_fk FOREIGN KEY (role_id) REFERENCES roles (id),
  CONSTRAINT invitations_user_fk FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64)        NOT NULL,
  expires_at DATETIME        NOT NULL,
  used_at    DATETIME        NULL,
  ip         VARCHAR(45)     NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY password_resets_token_unique (token_hash),
  KEY password_resets_user (user_id),
  CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id            VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  data          MEDIUMTEXT   NOT NULL,
  last_activity INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY sessions_user (user_id),
  KEY sessions_last_activity (last_activity),
  CONSTRAINT sessions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NULL,
  user_id     BIGINT UNSIGNED NULL,
  action      VARCHAR(80)     NOT NULL,
  entity      VARCHAR(60)     NULL,
  entity_id   VARCHAR(60)     NULL,
  before_json JSON            NULL,
  after_json  JSON            NULL,
  meta        JSON            NULL,
  ip          VARCHAR(45)     NULL,
  user_agent  VARCHAR(255)    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY audit_company (company_id, created_at),
  KEY audit_entity (entity, entity_id),
  KEY audit_action (action, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key   CHAR(64)      NOT NULL,
  window_start BIGINT        NOT NULL,
  hits         INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  `key`      VARCHAR(80)     NOT NULL,
  value      MEDIUMTEXT      NULL,
  `type`     VARCHAR(20)     NOT NULL DEFAULT 'string',
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY settings_company_key (company_id, `key`),
  CONSTRAINT settings_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NULL,
  `type`     VARCHAR(60)     NOT NULL,
  payload    JSON            NULL,
  read_at    DATETIME        NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY notifications_user (company_id, user_id, read_at),
  CONSTRAINT notifications_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_queue (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_email       VARCHAR(190)    NOT NULL,
  subject        VARCHAR(255)    NOT NULL,
  body           MEDIUMTEXT      NOT NULL,
  attachment_ref VARCHAR(255)    NULL,
  status         VARCHAR(20)     NOT NULL DEFAULT 'queued',
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error     VARCHAR(255)    NULL,
  send_after     DATETIME        NULL,
  sent_at        DATETIME        NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY mail_queue_status (status, send_after)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_modules (
  company_id BIGINT UNSIGNED NOT NULL,
  module_key VARCHAR(40)     NOT NULL,
  enabled    TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (company_id, module_key),
  KEY company_modules_module (module_key),
  CONSTRAINT company_modules_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT company_modules_module_fk FOREIGN KEY (module_key) REFERENCES modules (`key`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ modules

INSERT INTO modules (`key`, name, description, status, permission_key, sort_order) VALUES
  ('employees', 'Employees', 'People, contracts, statutory numbers, documents.', 'Live', 'employees.view', 10),
  ('payroll', 'Payroll', 'Runs, payslips, statutory lines, employer cost.', 'Live', 'payroll.run', 20),
  ('leave-management', 'Leave', 'Types, balances, approvals and public holidays.', 'Live', 'leave.view', 30),
  ('attendance', 'Attendance', 'Register, timesheet, shift roster, holidays and clock devices.', 'Live', 'attendance.view', 40),
  ('contracts', 'Contracts', 'Templates, generated contracts and employee documents.', 'Planned', 'contracts.view', 50),
  ('casual-management', 'Casual', 'Day rates, muster, weekly pay.', 'Planned', 'attendance.view', 60),
  ('loans', 'Loans', 'Loans, advances, deduction priority.', 'Planned', 'loans.view', 70),
  ('returns', 'Statutory returns', 'PAYE, NSSF, SHIF, Housing Levy and NITA returns.', 'Planned', 'returns.view', 80),
  ('reports', 'Reports', 'P9, P10, statutory returns, GL.', 'Planned', 'reports.view', 90),
  ('self-service', 'Self service', 'Payslips, leave requests, timesheets.', 'Planned', 'employees.view', 100);

-- -------------------------------------------------------------- permissions

INSERT INTO permissions (`key`, module_key, name, description) VALUES
  ('company.manage', NULL, 'Manage company', 'Company settings and details'),
  ('roles.manage', NULL, 'Manage roles', 'Create roles and change what they allow'),
  ('users.manage', NULL, 'Manage users', 'Change memberships and role assignments'),
  ('users.invite', NULL, 'Invite users', 'Send invitations to join the company'),
  ('settings.manage', NULL, 'Manage settings', 'Company, leave, holiday, shift and device settings'),
  ('audit.view', NULL, 'View audit trail', 'Read the company audit log'),
  ('employees.view', 'employees', 'View employees', 'See the employee register'),
  ('employees.edit', 'employees', 'Edit employees', 'Create and change employee records'),
  ('employees.salary.view', 'employees', 'View salaries', 'See salary amounts and structures'),
  ('employees.bank.view', 'employees', 'View bank details', 'See bank and payment details'),
  ('employees.archive', 'employees', 'Archive employees', 'Archive and reinstate people'),
  ('contracts.view', 'contracts', 'View contracts', 'See contract templates and issued contracts'),
  ('contracts.manage', 'contracts', 'Manage contracts', 'Create templates and issue contracts'),
  ('leave.view', 'leave-management', 'View leave', 'See leave requests and balances'),
  ('leave.approve.team', 'leave-management', 'Approve team leave', 'Approve leave for direct reports and your department'),
  ('leave.approve.all', 'leave-management', 'Approve company leave', 'Approve leave for any employee in the company'),
  ('leave.manage_types', 'leave-management', 'Manage leave types', 'Change leave entitlements and eligibility'),
  ('attendance.view', 'attendance', 'View attendance', 'See days, hours and overtime'),
  ('attendance.edit', 'attendance', 'Edit attendance', 'Mark and correct daily attendance'),
  ('overtime.approve', 'attendance', 'Approve overtime', 'Approve overtime claims before payroll'),
  ('payroll.view', 'payroll', 'View payroll', 'See payroll runs and payslips'),
  ('payroll.run', 'payroll', 'Run payroll', 'Prepare and calculate a payroll run'),
  ('payroll.approve', 'payroll', 'Approve payroll', 'Approve a prepared payroll run'),
  ('payroll.export', 'payroll', 'Export payroll', 'Export payroll lines and payment files'),
  ('payment.release', 'payroll', 'Release payments', 'Release approved payments'),
  ('loans.view', 'loans', 'View loans', 'See loans and repayment schedules'),
  ('loans.manage', 'loans', 'Manage loans', 'Approve loans and advances'),
  ('returns.view', 'returns', 'View statutory returns', 'See prepared statutory returns'),
  ('returns.generate', 'returns', 'Generate statutory returns', 'Prepare and file statutory returns'),
  ('reports.view', 'reports', 'View reports', 'Run and read company reports'),
  ('reports.export', 'reports', 'Export reports', 'Export reports and accounting files');

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
  'company.manage', 'roles.manage', 'users.manage', 'users.invite', 'settings.manage', 'audit.view',
  'employees.view', 'employees.edit', 'employees.salary.view', 'employees.bank.view', 'employees.archive',
  'contracts.view', 'contracts.manage',
  'leave.view', 'leave.approve.team', 'leave.approve.all', 'leave.manage_types',
  'attendance.view', 'attendance.edit', 'overtime.approve',
  'payroll.view', 'payroll.run', 'payroll.approve', 'payroll.export', 'payment.release',
  'loans.view', 'loans.manage', 'returns.view', 'returns.generate',
  'reports.view', 'reports.export'
)
WHERE r.company_id IS NULL AND r.`key` = 'admin';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.`key` IN (
  'users.invite', 'settings.manage', 'audit.view',
  'employees.view', 'employees.edit', 'employees.salary.view', 'employees.archive',
  'contracts.view', 'contracts.manage',
  'leave.view', 'leave.approve.team', 'leave.approve.all', 'leave.manage_types',
  'attendance.view', 'attendance.edit', 'overtime.approve',
  'reports.view'
)
WHERE r.company_id IS NULL AND r.`key` = 'hr';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.`key` IN (
  'employees.view', 'employees.salary.view', 'employees.bank.view',
  'contracts.view',
  'payroll.view', 'payroll.run', 'payroll.approve', 'payroll.export', 'payment.release',
  'loans.view', 'loans.manage', 'returns.view', 'returns.generate',
  'reports.view', 'reports.export', 'audit.view'
)
WHERE r.company_id IS NULL AND r.`key` = 'finance';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.`key` IN (
  'employees.view', 'leave.view', 'leave.approve.team',
  'attendance.view', 'overtime.approve', 'reports.view'
)
WHERE r.company_id IS NULL AND r.`key` = 'hod';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.`key` IN (
  'employees.view', 'employees.salary.view', 'contracts.view',
  'payroll.view', 'loans.view', 'returns.view', 'reports.view', 'audit.view'
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
