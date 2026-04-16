-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 40 — pos_transaction_catalog_items (multi-select junction)
--
-- A single transaction can now be tagged with more than one Services & Items
-- entry (e.g. "Massage" + "Facial" in the same visit).
--
-- pos_transactions.revenue_source_id keeps the first / primary item for
-- backward compatibility with existing reports. This table holds the full set.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `pos_transaction_catalog_items` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id`  INT(11)      NOT NULL,
  `catalog_item_id` INT(11)      NOT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_txn_item` (`transaction_id`, `catalog_item_id`),
  KEY `idx_ptci_item` (`catalog_item_id`),

  CONSTRAINT `fk_ptci_txn`  FOREIGN KEY (`transaction_id`)  REFERENCES `pos_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptci_item` FOREIGN KEY (`catalog_item_id`) REFERENCES `catalog_items`     (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Junction table for multi-item revenue classification per transaction.';
