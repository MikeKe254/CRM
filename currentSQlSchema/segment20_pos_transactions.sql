-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 20 — `pos_transactions` table
--
-- Canonical transaction record for all Patronr POS checkouts.
-- Replaces the legacy `mpesa_payments` table for new transactions.
-- mpesa_payments is kept as the raw Safaricom callback log and
-- linked here via mpesa_payment_id for reconciliation.
-- ─────────────────────────────────────────────────────────────────────────────

-- Companion sequence table — used by trigger to generate pos_id
-- AUTO_INCREMENT is advanced past existing seeded rows after migration.
CREATE TABLE IF NOT EXISTS `pos_transaction_seq` (
  `id` INT(8) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
) ENGINE=InnoDB AUTO_INCREMENT = 10000000;

CREATE TABLE IF NOT EXISTS `pos_transactions` (
  `id`                        INT(11)         NOT NULL AUTO_INCREMENT,
  `pos_id`                    INT(8) UNSIGNED NOT NULL UNIQUE COMMENT '8-digit human-readable transaction ID, starts at 10000000, set by trigger',
  `company_id`                INT(11)         NOT NULL,
  `branch_id`                 INT(11)         NOT NULL,
  `area_id`                   INT(11)         DEFAULT NULL  COMMENT 'FK → areas (is_transactional=1)',
  `terminal_identifier`       VARCHAR(100)    NOT NULL,
  `cashier_user_id`           INT(11)         DEFAULT NULL  COMMENT 'User who processed checkout',

  -- Payment method
  `payment_method_id`         INT(10) UNSIGNED NOT NULL     COMMENT 'FK → payment_methods',
  `mpesa_config_id`           INT(10) UNSIGNED DEFAULT NULL COMMENT 'FK → mpesa_configs if mpesa',
  `pesapal_config_id`         INT(10) UNSIGNED DEFAULT NULL COMMENT 'FK → pesapal_configs if pesapal',

  -- Transaction details
  `amount`                    DECIMAL(12,2)   NOT NULL,
  `description`               VARCHAR(255)    DEFAULT NULL  COMMENT 'Bill number / table / note',
  `mode`                      ENUM('manual','api') NOT NULL DEFAULT 'manual'
                                COMMENT 'manual = staff confirms; api = programmatic (STK push etc.)',

  -- API-specific (Mpesa STK, Pesapal, etc.)
  `api_checkout_request_id`   VARCHAR(255)    DEFAULT NULL,
  `api_merchant_request_id`   VARCHAR(255)    DEFAULT NULL,
  `api_receipt`               VARCHAR(255)    DEFAULT NULL,
  `api_raw_response`          JSON            DEFAULT NULL  COMMENT 'Raw API response stored for audit',

  -- Legacy link (Safaricom callback matching)
  `mpesa_payment_id`          INT(11)         DEFAULT NULL  COMMENT 'FK → mpesa_payments.id — set when callback arrives',

  -- Customer / loyalty
  `msisdn`                    VARCHAR(20)     DEFAULT NULL  COMMENT 'Captured phone — nullable (customer may skip)',
  `customer_id`               BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'FK → customers — set when msisdn is captured',
  `loyalty_account_id`        INT(11)         DEFAULT NULL,
  `loyalty_points_awarded`    INT(11)         NOT NULL DEFAULT 0,

  -- Status
  `status`                    ENUM('pending','processing','complete','failed','cancelled')
                                NOT NULL DEFAULT 'pending',
  `completed_at`              TIMESTAMP       NULL DEFAULT NULL,
  `created_at`                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_pt_company`          (`company_id`),
  KEY `idx_pt_branch`           (`branch_id`),
  KEY `idx_pt_terminal`         (`terminal_identifier`),
  KEY `idx_pt_msisdn`           (`msisdn`),
  KEY `idx_pt_status`           (`status`),
  KEY `idx_pt_created`          (`created_at`),
  KEY `idx_pt_area`             (`area_id`),
  KEY `idx_pt_customer`         (`customer_id`),
  KEY `idx_pt_checkout_req`     (`api_checkout_request_id`),

  CONSTRAINT `fk_pt_company`      FOREIGN KEY (`company_id`)     REFERENCES `companies`       (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_branch`       FOREIGN KEY (`branch_id`)      REFERENCES `branches`        (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_area`         FOREIGN KEY (`area_id`)        REFERENCES `areas`           (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pt_method`       FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`),
  CONSTRAINT `fk_pt_customer`     FOREIGN KEY (`customer_id`)    REFERENCES `customers`       (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pt_loyalty_acct` FOREIGN KEY (`loyalty_account_id`) REFERENCES `loyalty_accounts` (`id`) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Canonical POS transaction record. One row per checkout completion attempt.';
