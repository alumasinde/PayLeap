# Mshahara

One payroll engine, two products: a free public calculator that brings people
in, and a payroll system they can buy.

| Folder | What it is | Stack |
| --- | --- | --- |
| `tool/` | **Mshahara Check** — free take-home calculator, no login | PHP 8.2, htmx, Twig, MySQL |
| `engine/` | **mshahara/paye** — PAYE, NSSF, SHIF, Housing Levy, reliefs, employer cost, reverse solving | Plain PHP, no HTTP or database |
| `payroll/` | **Mshahara Payroll** — multi-company payroll, branding, employees, statutory returns | To be built on `engine/` |

`tool/` and `payroll/` must never reimplement tax: both call `engine/`, and every
payslip stores the `rate_version_id` that produced it.

## Run the free tool

```bash
cd tool
composer install
php -S 127.0.0.1:8080 -t public public/index.php
```

Rates come from MySQL (`rate_versions.payload`). See `tool/README.md` for the
database setup, the local instance on port 3307, and the API.

## Test

```bash
php tool/tests/engine_test.php
```
