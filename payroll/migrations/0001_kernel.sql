-- Kernel: identity, tenancy, invitations, resets, audit, rate limits.
-- All timestamps are UTC; the app sets time_zone = '+00:00' on connect.

CREATE TABLE IF NOT EXISTS users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(120)    NOT NULL,
  email             VARCHAR(190)    NOT NULL,
  password_hash     VARCHAR(255)    NULL,
  email_verified_at DATETIME        NULL,
  session_epoch     INT UNSIGNED    NOT NULL DEFAULT 0,
  status            VARCHAR(16)     NOT NULL DEFAULT 'invited',
  last_login_at     DATETIME        NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(160)    NOT NULL,
  slug       VARCHAR(80)     NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY companies_slug_unique (slug)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memberships (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  role       VARCHAR(32)     NOT NULL,
  status     VARCHAR(16)     NOT NULL DEFAULT 'active',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY memberships_unique (company_id, user_id),
  KEY memberships_user (user_id),
  CONSTRAINT memberships_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT memberships_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  email       VARCHAR(190)    NOT NULL,
  role        VARCHAR(32)     NOT NULL,
  token_hash  CHAR(64)        NOT NULL,
  invited_by  BIGINT UNSIGNED NULL,
  expires_at  DATETIME        NOT NULL,
  accepted_at DATETIME        NULL,
  revoked_at  DATETIME        NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY invitations_token_unique (token_hash),
  KEY invitations_email (company_id, email),
  CONSTRAINT invitations_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT invitations_user_fk FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64)        NOT NULL,
  expires_at DATETIME        NOT NULL,
  used_at    DATETIME        NULL,
  ip         VARCHAR(45)     NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY password_resets_token_unique (token_hash),
  KEY password_resets_user (user_id),
  CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  action        VARCHAR(80)     NOT NULL,
  subject_type  VARCHAR(60)     NULL,
  subject_id    VARCHAR(60)     NULL,
  meta          JSON            NULL,
  ip            VARCHAR(45)     NULL,
  user_agent    VARCHAR(255)    NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY audit_company (company_id, created_at),
  KEY audit_action (action, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key   CHAR(64)      NOT NULL,
  window_start BIGINT        NOT NULL,
  hits         INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
