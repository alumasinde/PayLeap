-- Database-backed sessions: survive restarts and work across app servers.
-- The id column is binary-collated because PHP session ids are case-sensitive.

CREATE TABLE IF NOT EXISTS sessions (
  id            VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  data          MEDIUMTEXT   NOT NULL,
  last_activity INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY sessions_last_activity (last_activity)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
