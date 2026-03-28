-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 21 — `pos_checkout_drafts` table
--
-- Persists multi-step checkout state per terminal.
-- One active draft per terminal at a time (UNIQUE on terminal_identifier + company_id).
-- The draft is created at step 1, updated through steps 2–4, then deleted on
-- step 5 completion (or on cancellation / expiry).
-- pos_transaction_id is set at step 3 when the transaction record is created.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `pos_checkout_drafts` (
  `id`                    INT(11)         NOT NULL AUTO_INCREMENT,
  `company_id`            INT(11)         NOT NULL,
  `branch_id`             INT(11)         NOT NULL,
  `terminal_identifier`   VARCHAR(100)    NOT NULL,
  `cashier_user_id`       INT(11)         DEFAULT NULL COMMENT 'Set after POS unlock',

  `step`                  TINYINT(3)      NOT NULL DEFAULT 1
                            COMMENT '1=info, 2=payment method, 3=process, 4=loyalty, 5=success',

  -- Live state — JSON payload grows as user advances through steps
  -- Keys: area_id, amount, description, payment_method_id, mpesa_config_id,
  --       pos_transaction_id, msisdn
  `payload`               JSON            NOT NULL DEFAULT (JSON_OBJECT()),

  -- Set at step 3 when pos_transaction row is created
  `pos_transaction_id`    INT(11)         DEFAULT NULL,

  `expires_at`            TIMESTAMP       NOT NULL
                            COMMENT 'Draft auto-expires after 30 minutes of inactivity',
  `created_at`            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- Only one active draft per terminal per company
  UNIQUE KEY `uk_draft_terminal`  (`terminal_identifier`, `company_id`),
  KEY        `idx_draft_branch`   (`branch_id`),
  KEY        `idx_draft_expires`  (`expires_at`),

  CONSTRAINT `fk_draft_company`       FOREIGN KEY (`company_id`)  REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_draft_branch`        FOREIGN KEY (`branch_id`)   REFERENCES `branches`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_draft_transaction`   FOREIGN KEY (`pos_transaction_id`) REFERENCES `pos_transactions` (`id`) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Multi-step checkout state per terminal. One active draft per terminal at a time.';
