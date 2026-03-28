-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 17 — New `customers` table
--
-- Replaces the analytics-heavy `customer_profiles` table with a clean
-- identity-only record. A customer is identified by msisdn per company only.
-- All analytics live in separate linked tables (see segment 18).
-- The legacy `customer_profiles` table is retained for backward compatibility
-- but deprecated — new code must use `customers`.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `customers` (
  `id`           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id`   INT(11)             NOT NULL,
  `msisdn`       VARCHAR(20)         NOT NULL  COMMENT 'Primary identifier — E.164 format e.g. 254712345678',
  `first_name`   VARCHAR(120)        DEFAULT NULL,
  `gender`       ENUM('male','female','unknown') NOT NULL DEFAULT 'unknown',
  `enrolled_at`  TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this customer first appeared in the system',
  `last_seen_at` TIMESTAMP           NULL DEFAULT NULL                  COMMENT 'Updated on each new transaction',
  `created_at`   TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP           NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_company_msisdn`    (`company_id`, `msisdn`),
  KEY           `idx_customers_msisdn`   (`msisdn`),
  KEY           `idx_customers_company`  (`company_id`),

  CONSTRAINT `fk_customers_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Canonical customer identity table. One row per customer per company, keyed by msisdn.';
