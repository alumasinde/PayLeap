-- Append-only leave ledger. A batch is a grant of days; transactions are the
-- only source of a balance. Request allocations preserve exactly which batches
-- funded an approval so a cancellation can reverse those batches.

CREATE TABLE IF NOT EXISTS leave_batches (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NOT NULL,
  employee_id   BIGINT UNSIGNED NOT NULL,
  leave_type_id BIGINT UNSIGNED NOT NULL,
  period_start  DATE            NOT NULL,
  period_end    DATE            NOT NULL,
  days_earned   DECIMAL(7,2)    NOT NULL,
  expiry_date   DATE            NULL,
  source        VARCHAR(30)     NOT NULL DEFAULT 'opening',
  source_key    VARCHAR(120)    NOT NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY leave_batches_source (company_id, source_key),
  KEY leave_batches_employee_type (company_id, employee_id, leave_type_id, period_start),
  KEY leave_batches_expiry (company_id, expiry_date),
  CONSTRAINT leave_batches_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT leave_batches_employee_fk FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT leave_batches_type_fk FOREIGN KEY (leave_type_id) REFERENCES leave_types (id) ON DELETE CASCADE,
  CONSTRAINT leave_batches_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_transactions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     BIGINT UNSIGNED NOT NULL,
  batch_id       BIGINT UNSIGNED NOT NULL,
  type           ENUM('accrual','usage','expiry','adjustment','reversal') NOT NULL,
  days           DECIMAL(7,2)    NOT NULL,
  effective_date DATE            NOT NULL,
  ref_type       VARCHAR(40)     NULL,
  ref_id         BIGINT UNSIGNED NULL,
  reversal_of_id BIGINT UNSIGNED NULL,
  created_by     BIGINT UNSIGNED NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note           VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY leave_transactions_reversal (reversal_of_id),
  KEY leave_transactions_batch (batch_id, effective_date),
  KEY leave_transactions_ref (company_id, ref_type, ref_id),
  CONSTRAINT leave_transactions_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT leave_transactions_batch_fk FOREIGN KEY (batch_id) REFERENCES leave_batches (id) ON DELETE CASCADE,
  CONSTRAINT leave_transactions_reversal_fk FOREIGN KEY (reversal_of_id) REFERENCES leave_transactions (id),
  CONSTRAINT leave_transactions_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_request_allocations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  request_id  BIGINT UNSIGNED NOT NULL,
  batch_id    BIGINT UNSIGNED NOT NULL,
  days        DECIMAL(7,2)    NOT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY leave_allocations_request_batch (request_id, batch_id),
  KEY leave_allocations_batch (batch_id),
  CONSTRAINT leave_allocations_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT leave_allocations_request_fk FOREIGN KEY (request_id) REFERENCES leave_requests (id) ON DELETE CASCADE,
  CONSTRAINT leave_allocations_batch_fk FOREIGN KEY (batch_id) REFERENCES leave_batches (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE leave_requests
  ADD COLUMN cancelled_at DATETIME NULL AFTER decided_at,
  ADD COLUMN cancelled_by BIGINT UNSIGNED NULL AFTER cancelled_at,
  ADD CONSTRAINT leave_requests_canceller_fk FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL;