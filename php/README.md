# Mshahara Check — PHP

Same calculator as the Next app, rebuilt as PHP 8.2 + htmx + Twig with the
statutory rates stored in MySQL.

## Requirements

PHP 8.2+ with `pdo_mysql`, Composer, MySQL or MariaDB.

## Install

```bash
cd php
composer install
```

## Database

```bash
cp .env.example .env
```

Put your MySQL password in `.env`, then create the schema and the two rulesets:

```bash
mysql -u root -p < migrations/001_init.sql
```

Without a database the app still runs: rates come from `config/rates.php` and
the page shows a demo-mode banner. Saved payslips need MySQL.

Changing a rate means updating `rate_versions.payload` — no deploy:

```sql
UPDATE rate_versions
SET payload = JSON_SET(payload, '$.nssf.upper_earnings_limit', 120000)
WHERE id = 'ke-2026.02';
```

## Run

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

## Test

```bash
php tests/engine_test.php
```

## Routes

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/` | Calculator page |
| POST | `/preview` | Payslip partial for htmx |
| POST | `/save` | Store a payslip snapshot (MySQL) |
| GET | `/p/{id}` | Saved payslip |
| GET | `/api/v1/rates` | Rate versions and selectable periods |
| POST | `/api/v1/payslip` | `{gross, year, month, options}` → payslip + employer cost |

`api/v1` returns the same numbers the page renders because both call
`Mshahara\Paye\Calculator`.
