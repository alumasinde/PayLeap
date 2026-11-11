-- Reverse the kernel. Child tables go first so foreign keys never block a drop.

DROP TABLE IF EXISTS mail_queue;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS company_modules;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS invitations;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS modules;
DROP TABLE IF EXISTS memberships;
DROP TABLE IF EXISTS companies;
DROP TABLE IF EXISTS users;
