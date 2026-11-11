CREATE TABLE IF NOT EXISTS leads (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  email      VARCHAR(190) NOT NULL,
  company    VARCHAR(160) NULL,
  employees  VARCHAR(20)  NULL,
  module     VARCHAR(40)  NOT NULL DEFAULT 'payroll',
  message    TEXT         NULL,
  source     VARCHAR(40)  NOT NULL DEFAULT 'site',
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY leads_module (module),
  KEY leads_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
