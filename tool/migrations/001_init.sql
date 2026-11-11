-- Mshahara Check — MySQL schema and seed.
--   mysql -u root -p < migrations/001_init.sql
-- Safe to re-run: tables are IF NOT EXISTS and seed rows upsert by id.

CREATE DATABASE IF NOT EXISTS mshahara
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mshahara;

CREATE TABLE IF NOT EXISTS rate_versions (
  id             VARCHAR(24)  NOT NULL PRIMARY KEY,
  label          VARCHAR(64)  NOT NULL,
  effective_from DATE         NOT NULL,
  verified_on    DATE         NOT NULL,
  payload        JSON         NOT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY rate_versions_effective (effective_from)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS saved_payslips (
  id           CHAR(12)      NOT NULL PRIMARY KEY,
  rate_version VARCHAR(24)   NOT NULL,
  gross        DECIMAL(12,2) NOT NULL,
  slip         JSON          NOT NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY saved_payslips_version (rate_version),
  CONSTRAINT saved_payslips_rate FOREIGN KEY (rate_version)
    REFERENCES rate_versions (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

INSERT INTO rate_versions (id, label, effective_from, verified_on, payload) VALUES
(
  'ke-2025.02', 'February 2025', '2025-02-01', '2025-11-01',
  '{
    "paye": {
      "bands": [
        {"up_to": 24000, "rate": 0.10},
        {"up_to": 32333, "rate": 0.25},
        {"up_to": 500000, "rate": 0.30},
        {"up_to": 800000, "rate": 0.325},
        {"up_to": null, "rate": 0.35}
      ],
      "personal_relief_monthly": 2400,
      "non_cash_benefit_threshold_monthly": 5000
    },
    "nssf": {"employee_rate": 0.06, "employer_rate": 0.06, "tier1_pay_ceiling": 8000, "upper_earnings_limit": 72000},
    "shif": {"employee_rate": 0.0275, "minimum_monthly": 300},
    "housing_levy": {"employee_rate": 0.015, "employer_rate": 0.015},
    "nita": {"employer_monthly": 50},
    "reliefs": {
      "pension_cap_monthly": 30000,
      "mortgage_interest_cap_monthly": 30000,
      "insurance_relief_rate": 0.15,
      "insurance_relief_cap_monthly": 5000
    }
  }'
),
(
  'ke-2026.02', 'February 2026', '2026-02-01', '2026-11-11',
  '{
    "paye": {
      "bands": [
        {"up_to": 24000, "rate": 0.10},
        {"up_to": 32333, "rate": 0.25},
        {"up_to": 500000, "rate": 0.30},
        {"up_to": 800000, "rate": 0.325},
        {"up_to": null, "rate": 0.35}
      ],
      "personal_relief_monthly": 2400,
      "non_cash_benefit_threshold_monthly": 5000
    },
    "nssf": {"employee_rate": 0.06, "employer_rate": 0.06, "tier1_pay_ceiling": 9000, "upper_earnings_limit": 108000},
    "shif": {"employee_rate": 0.0275, "minimum_monthly": 300},
    "housing_levy": {"employee_rate": 0.015, "employer_rate": 0.015},
    "nita": {"employer_monthly": 50},
    "reliefs": {
      "pension_cap_monthly": 30000,
      "mortgage_interest_cap_monthly": 30000,
      "insurance_relief_rate": 0.15,
      "insurance_relief_cap_monthly": 5000
    }
  }'
)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  effective_from = VALUES(effective_from),
  verified_on = VALUES(verified_on),
  payload = VALUES(payload);
