-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 21 — `pos_transaction_splits` table
--
-- Records each individual leg of a split-payment checkout.
-- A non-split checkout produces one row (split_index = 0).
-- All legs share the same pos_transaction_id (FK → pos_transactions).
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `pos_transaction_splits` (
  `id`                  INT(11)          NOT NULL AUTO_INCREMENT,
  `pos_transaction_id`  INT(11)          NOT NULL  COMMENT 'FK → pos_transactions.id',
  `split_index`         TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-based position in the split array',
  `payment_method_id`   INT(10) UNSIGNED NOT NULL  COMMENT 'FK → payment_methods',
  `method_key`          VARCHAR(50)      NOT NULL,
  `amount`              DECIMAL(12,2)    NOT NULL,
  `mpesa_config_id`     INT(10) UNSIGNED DEFAULT NULL,
  `api_receipt`         VARCHAR(255)     DEFAULT NULL COMMENT 'M-Pesa receipt / API code for this leg',
  `mpesa_payment_id`    INT(11)          DEFAULT NULL COMMENT 'FK → mpesa_payments.id',
  `created_at`          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_pts_txn`    (`pos_transaction_id`),
  KEY `idx_pts_method` (`payment_method_id`),

  CONSTRAINT `fk_pts_txn`    FOREIGN KEY (`pos_transaction_id`) REFERENCES `pos_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pts_method` FOREIGN KEY (`payment_method_id`)  REFERENCES `payment_methods`  (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per split payment leg. Single-method checkouts also get one row here.';
