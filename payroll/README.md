# Mshahara Payroll

The paid product. Multi-company payroll for Kenyan employers, built on
`../engine` (the same `Mshahara\Paye\Calculator` the free tool uses).

## Status

The vanilla PHP **auth kernel** is built and tested: accounts, invitations,
password resets, sessions, company memberships, roles/permissions, audit and
rate limiting. The **employee register** is built on top of it: people,
employment terms, Kenyan statutory numbers, salary and bank details, departments
and archiving. **Payroll runs** are built next: a month is prepared from the
register with the shared statutory engine, then approved by a second person.
A **dashboard** built from those tables shows headcount, today's attendance,
pending leave, the latest run and payroll cost. **Leave** requests and balances,
the **daily attendance** register, a **monthly timesheet**, and a **shift roster**
are live alongside it. Attendance records carry a source, so a biometric reader
or face scanner can post clock times into the same rows and audit trail later.
**Settings** holds the company policy those screens read: leave types with
gender eligibility, public holidays, shift times and breaks, and the clock or
meal devices on site. The `modules` table says exactly what is live.

No framework. Twig + PDO + PHP 8.2, Composer for autoload and Twig only.

## Naming

- `*Repository` — SQL and row mapping only; no mail, tokens or HTTP.
- `*Service` — one use case: transactions, tokens, mail, audit.
- `*Controller` — HTTP input/output, then a service call.
- `Support\*` — framework-free helpers (`SessionManager`, `CsrfGuard`,
  `FlashBag`, `AuditLogger`, `PasswordHasher`).

`App` is the request context and response facade; `Foundation\Bootstrap` is the
composition root that builds it.

## Run

```bash
cd payroll
composer install
php bin/migrate.php
php bin/seed.php "Acme Ltd" owner@acme.test
php bin/seed_demo.php          # optional: departments, staff, leave, attendance, a draft run
php -S 127.0.0.1:8090 -t public public/index.php
```

The seed prints a one-time invite URL. Open it to set the owner password.
Development email is written to `storage/mail/*.eml`. Set `MAIL_DRIVER=smtp` and
`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`
(`tls`, `ssl` or `none`) to send through a real relay instead.

Copy `.env.example` to `.env` first. `APP_KEY` signs future signed URLs. Sessions
are stored in MySQL by default (`SESSION_DRIVER=database`); set
`SESSION_DRIVER=file` to use PHP's file handler for local work.

## Routes

| Route | Purpose |
| --- | --- |
| `GET/POST /login` | Sign in (rate limited per IP and per account) |
| `POST /logout` | Destroy the session |
| `GET/POST /forgot` | Request a reset link (same answer for unknown emails) |
| `GET/POST /reset/{token}` | Choose a new password (single use, one hour) |
| `GET/POST /invite/{token}` | Accept an invitation, set the first password |
| `GET /app` | Dashboard: headcount, attendance, leave, payroll and quick actions for the current role |
| `GET /app/access` | Members, roles and pending invitations (`users.manage`/`users.invite`) |
| `POST /app/access/invite` | Send an invitation with a role |
| `POST /app/access/members/{id}/role` | Change a member's role |
| `POST /app/access/members/{id}/remove` | Remove a member's company access |
| `POST /app/access/invites/{id}/revoke` | Revoke a pending invitation |
| `GET/POST /app/access/roles/{id}` | Edit a role's name and permissions (`roles.manage`) |
| `GET /app/employees` | Employee register with search, status and department filters (`employees.view`) |
| `GET/POST /app/employees/new` | Add an employee (`employees.edit`) |
| `GET/POST /app/employees/{id}` | Open or change an employee record |
| `POST /app/employees/{id}/archive` | Archive a leaver with an exit date; the record is kept |
| `POST /app/employees/{id}/reinstate` | Bring an archived person back onto the register |
| `POST /app/employees/departments` | Add a department (`employees.edit`) |
| `GET /app/payroll` | Payroll runs and the prepare form (`payroll.view`) |
| `POST /app/payroll` | Prepare a month from active salaried staff (`payroll.run`) |
| `GET /app/payroll/{id}` | Run totals, lines and approval state |
| `GET /app/payroll/{id}/lines/{line}/payslip` | Printable payslip built from the stored breakdown |
| `POST /app/payroll/{id}/approve` | Approve a draft run (`payroll.approve`, different person) |
| `GET /app/attendance` | Daily register with a day picker and summary (`attendance.view`); `?view=timesheet` shows one person's month |
| `POST /app/attendance` | Mark or correct one person's day, with a clock source (`attendance.edit`) |
| `GET /app/roster` | Month calendar of shifts, rest days and approved leave (`attendance.view`) |
| `POST /app/roster` | Assign a shift or a rest day to one person's date (`attendance.edit`) |
| `GET /app/leave` | Leave requests filtered by status (`leave.view`); `?view=balances` shows entitlement, taken, pending and balance |
| `POST /app/leave/{id}/decide` | Approve or reject a pending request (`leave.approve.team` for the team, `leave.approve.all` for the company); nobody decides their own, and restricted types check gender |
| `GET /app/settings` | Company policy tabs: organisation, leave and holidays, shifts, devices (`settings.manage`) |
| `POST /app/settings/company` | Rename the company |
| `POST /app/settings/leave-types` | Add or update a leave type, its days, eligibility and carry-over |
| `POST /app/settings/leave-types/{id}/toggle` | Enable or retire a leave type |
| `POST /app/settings/holidays` | Add a public holiday for the company |
| `POST /app/settings/holidays/{id}/delete` | Remove a public holiday |
| `POST /app/settings/shifts` | Add or update a shift's start, end and break |
| `POST /app/settings/shifts/{id}/toggle` | Enable or retire a shift |
| `POST /app/settings/devices` | Register a clock or meal device |
| `POST /app/settings/devices/{id}/toggle` | Enable or retire a device |
| `POST /me/profile` | Change your own first and last name |
| `POST /me/password` | Change your password; other sessions are revoked |
| `GET /me` | The signed-in person's own account (self-service shell) |
| `POST /me/sign-out-everywhere` | End every session for the account, including the current one |
| `POST /app/company` | Switch company (membership checked, session rotated) |

Roles are never in the URL. `/app` is the role context, `/me` is the personal
context, and the guard resolves both from the request.

## Auth model

- **Invite-only.** There is no public signup. An invitation carries the company,
  email and role; those are read from the stored row, never from the form.
- **Tokens are hashed at rest.** 32 random bytes, SHA-256 in the database,
  single use, expiring (invites 7 days, resets 60 minutes).
- **Sessions rotate.** New session id on login and on company switch; idle
  (30 min) and absolute (12 h) timeouts enforced server-side.
- **Sessions live in MySQL** (`sessions` table), so a restart or a second app
  server keeps people signed in. The handler implements PHP's
  `SessionHandlerInterface`, and stale rows are garbage-collected. Each row
  records its user, so "sign out everywhere" deletes that person's rows
  immediately; the epoch bump still rejects any request already in flight.
- **`session_epoch` revokes access.** A password reset or "sign out everywhere"
  increments it; stale sessions are rejected on their next request.
- **CSRF on every POST**, rotated on login/logout, compared with `hash_equals`.
- **Roles and permissions live in MySQL.** `modules`, `permissions`, `roles`,
  `role_permissions` and `user_roles`. Every company gets its own copy of the
  system roles when it is created; a company can rename them or change what
  they allow without a deploy. Code asks for permissions
  (`employees.salary.view`, `employees.bank.view`), never role names. HODs
  never see money; Finance sees bank details but cannot edit people.
- **Audit trail** for invites, acceptance, logins, resets and company switches.
- **Security headers** on every response: CSP, `X-Frame-Options: DENY`,
  `nosniff`, `Referrer-Policy: same-origin`.
- **Rate limits** are MySQL-backed, so they hold across PHP workers.

## Tests

```bash
php tests/auth_test.php       # services: tokens, resets, CSRF, rate limits, gate
php tests/employee_test.php   # register: numbers, validation, scope, archive
php tests/leave_test.php      # approvals: manager scope, self-approval, linking
php tests/payroll_test.php    # runs: engine parity, totals, approval, scope
php tests/smtp_test.php       # SMTP wire format against a local stub
bash tests/http_smoke.sh      # HTTP: CSRF 419, invite, register, payroll, reset
```

`auth_test.php`, `employee_test.php` and `payroll_test.php` recreate
`mshahara_payroll_test` from scratch on every run.

## Decisions

1. One app origin; the company comes from membership, not the URL. Branded
   subdomains can alias the same app later.
2. `/app` for staff roles, `/me` for self-service. Roles are not paths.
3. Web only. No desktop install: payroll data stays in one place and statutory
   changes ship as one deploy.
4. Statutory logic stays in `engine/`; modules never copy it.
5. Every table carries `company_id` (or is scoped through a membership); every
   query is scoped by it. Money will be integer cents, never float.
6. Approved payroll runs will be immutable; corrections are adjustment runs.

## Employees

- **Salary is integer cents.** Forms type shillings; `Support\Money` converts and
  formats. No float is stored or summed.
- **Archive, never delete.** A leaver keeps their record and exit date so payroll
  history stays readable. Archived people are hidden from the default list.
- **Fields follow the permission.** `employees.salary.view` and
  `employees.bank.view` decide what is shown, and the controller only reads those
  fields from the POST when the role holds the permission — a crafted form cannot
  set a salary the person cannot see.
- **Company scoped.** Every query carries `company_id`; departments and employee
  numbers are unique per company.

## Payroll

- **One engine.** `Mshahara\Paye\Calculator` does every calculation. The module
  never re-implements a statutory rule, so the paid product and the free
  calculator cannot drift apart.
- **Rates are data.** `rate_versions` holds the JSON payload keyed by effective
  date. A run picks the version effective at the start of its month and stores
  that id, so a later gazetted change never rewrites an old run. A new rate is
  a new row: `INSERT INTO rate_versions ...`, no deploy.
- **Cents and a stored breakdown.** Every line keeps integer cents for each
  statutory figure and the engine's full result as JSON, so a payslip or a
  dispute can be explained without recalculating against today's rates.
- **One run per company per month.** A duplicate is a 409, not a second draft.
  Approved runs are immutable; a correction will be a new adjustment run.
- **Two people.** The preparer can never approve their own run, and approval
  also needs the `payroll.approve` permission. Approving locks the run.
- **Scope.** Runs join their lines and totals through `company_id`; another
  company can never read a run, even with its id.
- **Payslips are documents.** Each line has a printable payslip: earnings,
  each statutory deduction, taxable pay, reliefs, PAYE bands, net pay and the
  employer contributions. It reads the stored engine breakdown, so it shows
  what was calculated, not what today's rates would calculate. Bank details
  appear only for roles holding `employees.bank.view`.

## Attendance, shifts and leave

- **A shift is planned, a clock is observed.** `shifts` hold start, end and an
  unpaid break; `roster_entries` say which shift a person works on a date (or
  that the day is a rest day). The timesheet measures the observed clock against
  that plan.
- **Timesheet math is one place.** `TimesheetService` sums only rostered,
  non-future days: worked is clock-out minus clock-in (wrapping past midnight),
  target is the shift span less its break, lost is target minus worked (or the
  full shift for an absence), overtime is worked beyond target. Approved leave
  overrides the roster and is counted separately, not as lost time. A public
  holiday has no target or lost hours; work clocked on it is reported as holiday
  hours. A day with only one clock is flagged, not guessed.
- **Every record names its source.** `attendance_records.source` is `manual`,
  `biometric`, `face` or `import`; the register shows it and the audit entry
  records it. A terminal integration writes the same row through
  `AttendanceService::mark()` and gets the same validation, tenancy and audit.
- **Leave balances are derived, never stored.** Entitlement comes from
  `leave_types.days_per_year`; taken is approved days in the year and pending is
  requested days, so a decision immediately moves the balance.
- **Approval follows the reporting line.** `employees.manager_id` is the direct
  supervisor and `user_id` links that person to a login (both set on the employee
  form). A team approver (`leave.approve.team`, the HOD) sees direct reports and
  their own department; a company approver (`leave.approve.all`, HR, owner,
  admin) sees every request. An approver's own pending request is visible but
  never decidable, and the dashboard badge counts only actionable requests.
  HOD leave therefore waits for their manager or HR; HR leave waits for the
  owner or another company approver.
- **Leave policy is data.** A leave type carries its days, paid flag, document
  rule, carry-over limit, active flag and a gender restriction (`any`, `female`,
  `male`). Balances hide restricted types from the other gender. Approval of a
  restricted type is refused when the employee's gender does not match, and an
  employee with no recorded gender must be completed first.
- **A holiday is a date, not a balance.** `public_holidays` is company-scoped
  and managed with the leave policy in Settings. Fixed statutory days are
  provisioned for the current and next year, and movable feasts are added
  there. A public holiday is a paid non-working day, not an entitlement: no
  leave balance is consumed and no request is needed. The roster marks the
  date, and the timesheet drops the target and lost hours for it. Hours clocked
  on a holiday are summed into a separate `Holiday hrs` column so payroll can
  price them; the current payroll run does not yet apply a holiday premium.
- **Devices are registered, then feed the same rows.** `attendance_devices`
  records a terminal's kind (biometric, face, terminal), purpose (clock in/out
  or meal), serial and location. The meal break itself is the shift's
  `break_minutes`; a meal device records the lunch clock.
- **Permissions gate the pages.** Viewing a register or timesheet needs
  `attendance.view`; assigning a shift needs `attendance.edit`; team leave
  decisions need `leave.approve.team` and company leave decisions
  `leave.approve.all`; company policy lives behind
  `settings.manage`, granted to owner, administrator and HR.

## Next

Self-service payslips, contract and document storage, and an adapter for
terminal clock files. The module catalogue lives in the `modules` table; feature
code lives in `src/Modules/`.
