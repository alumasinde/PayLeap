ALTER TABLE leave_requests
  DROP FOREIGN KEY leave_requests_canceller_fk,
  DROP COLUMN cancelled_by,
  DROP COLUMN cancelled_at;

DROP TABLE IF EXISTS leave_request_allocations;
DROP TABLE IF EXISTS leave_transactions;
DROP TABLE IF EXISTS leave_batches;