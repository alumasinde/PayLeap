# Mshahara Payroll

The paid product. Multi-company payroll for Kenyan employers, built on
`../engine` (the same `Mshahara\Paye\Calculator` the free tool uses).

## Status

The vanilla PHP **auth kernel** is built and tested: accounts, invitations,
password resets, sessions, company memberships, roles/permissions, audit and
rate limiting. Employee records are the next module; nothing here runs payroll
yet, and `config/modules.php` says so honestly.

No framework. Twig + PDO + PHP 8.2, Composer for autoload and Twig only.

## Run

```bash
cd payroll
composer install
php bin/migrate.php
php bin/seed.php "Acme Ltd" owner@acme.test
php -S 127.0.0.1:8090 -t public public/index.php
```

The seed prints a one-time invite URL. Open it to set the owner password.
Development email is written to `storage/mail/*.eml`; swap `FileMailer` for an
SMTP/API driver when a provider is chosen.

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
| `GET /app` | Workspace: company, team, modules for the current role |
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
- **Permissions, not roles.** `config/permissions.php` maps roles to permissions,
  including field-level ones (`employees.salary.view`, `employees.bank.view`).
  HODs never see money; Finance sees bank details but cannot edit people.
- **Audit trail** for invites, acceptance, logins, resets and company switches.
- **Security headers** on every response: CSP, `X-Frame-Options: DENY`,
  `nosniff`, `Referrer-Policy: same-origin`.
- **Rate limits** are MySQL-backed, so they hold across PHP workers.

## Tests

```bash
php tests/auth_test.php     # services: tokens, resets, CSRF, rate limits, gate
bash tests/http_smoke.sh    # HTTP: CSRF 419, invite, session rotation, reset
```

`auth_test.php` recreates `mshahara_payroll_test` from scratch on every run.

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

## Next

Employees: the registry every other module reads. Then payroll components and
the first run. Modules live in `src/Modules/` with a manifest; see the build
contract in the project notes.
