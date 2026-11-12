-- Company-wide HR notices shown on the employee dashboard. Announcements are
-- authored by HR and are intentionally separate from reading documents.
CREATE TABLE IF NOT EXISTS company_announcements (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  title        VARCHAR(160)    NOT NULL,
  body         TEXT            NOT NULL,
  published_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by   BIGINT UNSIGNED NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY company_announcements_company_published (company_id, published_at),
  CONSTRAINT company_announcements_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT company_announcements_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;