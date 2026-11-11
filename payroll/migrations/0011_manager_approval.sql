-- A reporting line and approval scope.
--
-- manager_id is the direct supervisor who approves leave. A team approver
-- (leave.approve.team) sees direct reports and their own department; a company
-- approver (leave.approve.all) sees every request. Nobody decides their own
-- request; LeaveService enforces that for every role.

ALTER TABLE employees
  ADD COLUMN manager_id BIGINT UNSIGNED NULL AFTER department_id,
  ADD KEY employees_manager (manager_id),
  ADD CONSTRAINT employees_manager_fk FOREIGN KEY (manager_id) REFERENCES employees (id) ON DELETE SET NULL;

INSERT IGNORE INTO permissions (`key`, module_id, name, description)
VALUES (
  'leave.approve.all',
  (SELECT id FROM modules WHERE `key` = 'leave-management'),
  'Approve company leave',
  'Approve leave for any employee in the company'
);

-- Existing companies carry their own role copies, so this covers templates and
-- the copies in one pass. HOD deliberately keeps team scope only.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.`key` = 'leave.approve.all'
WHERE r.`key` IN ('owner', 'admin', 'hr');

UPDATE modules
SET description = 'Types, balances, approvals and public holidays.'
WHERE `key` = 'leave-management';
