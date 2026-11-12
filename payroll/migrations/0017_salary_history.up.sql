-- Effective-dated pay rates. A salary change in the middle of a month must
-- pay the old rate up to the change and the new rate from the change, instead
-- of overwriting the amount used by an earlier payroll period.

CREATE TABLE IF NOT EXISTS employment_pay_rates (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     BIGINT UNSIGNED NOT NULL,
  employment_id  BIGINT UNSIGNED NOT NULL,
  salary_cents   BIGINT          NOT NULL,
  pay_basis      VARCHAR(20)     NOT NULL DEFAULT 'monthly',
  effective_from DATE            NOT NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY employment_pay_rates_effective (employment_id, effective_from),
  KEY employment_pay_rates_company (company_id, effective_from),
  CONSTRAINT employment_pay_rates_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT employment_pay_rates_employment_fk FOREIGN KEY (employment_id) REFERENCES employments (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO employment_pay_rates (company_id, employment_id, salary_cents, pay_basis, effective_from)
SELECT em.company_id, em.id, em.salary_cents, COALESCE(em.pay_basis, 'monthly'), em.start_date
FROM employments em
WHERE em.salary_cents IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM employment_pay_rates r
      WHERE r.employment_id = em.id AND r.effective_from = em.start_date
  );
