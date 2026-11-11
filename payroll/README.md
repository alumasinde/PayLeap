# Mshahara Payroll

The paid product. Multi-company payroll for Kenyan employers, built on
`../engine` (the same `Mshahara\Paye\Calculator` the free tool uses).

Status: not built. This folder reserves the name and the scope.

## Scope

- **Companies and branding** — many companies per account, roles (owner, payroll
  admin, approver, auditor, employee), logo, colours, payslip header and
  signatory, pay cycle and pay date.
- **Employees** — KRA PIN, NSSF, SHA, bank and M-Pesa details, department, job
  title, effective-dated salary and allowances, benefits in kind, loans, SACCO,
  HELB, documents, onboarding and exit.
- **Payroll runs** — draft → checked → approved → paid → locked; variance
  reports, exception checks, immutable payslips, adjustment runs.
- **Statutory** — PAYE, NSSF tiers, SHIF, Housing Levy, NITA; P10, P9, NSSF,
  SHA and Housing Levy returns; deadlines to the 9th.
- **Payments** — bank bulk files and M-Pesa disbursement.
- **Later modules** — self-service portal, leave and attendance, loans,
  benefits, expenses, filing automation, reporting and benchmarking.

## Hard rules

1. Call `Mshahara\Paye\Calculator`; never copy the tax arithmetic.
2. Store `rate_version_id` and the computed lines with every payslip; never
   recompute history.
3. Every table carries `company_id`; every query is scoped by it.
4. Money is `DECIMAL`/integer cents, never float.
5. Approved runs are immutable; corrections are adjustment runs.
