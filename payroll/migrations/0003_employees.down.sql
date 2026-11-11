-- departments.head_employee_id points at employees, so it must go first.
ALTER TABLE departments
  DROP FOREIGN KEY departments_head_fk,
  DROP KEY departments_head,
  DROP COLUMN head_employee_id;

DROP TABLE IF EXISTS employee_documents;
DROP TABLE IF EXISTS next_of_kin;
DROP TABLE IF EXISTS dependants;
DROP TABLE IF EXISTS employee_bank_accounts;
DROP TABLE IF EXISTS bank_branches;
DROP TABLE IF EXISTS bank_names;
DROP TABLE IF EXISTS employment_history;
DROP TABLE IF EXISTS employments;
DROP TABLE IF EXISTS employee_statutory;
DROP TABLE IF EXISTS employees;
