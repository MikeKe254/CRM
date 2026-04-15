-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 30 — POS Terminal Settings
--
-- Branch-scoped terminal configuration table.
-- One row per branch (UNIQUE on company_id + branch_id).
-- Branch is the primary operational context — settings may differ per branch.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `pos_terminal_settings` (
  `id`                          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `company_id`                  INT(11)         NOT NULL,
  `branch_id`                   INT(11)         NOT NULL,

  -- M-Pesa quick feed
  `show_mpesa_feed`             TINYINT(1)      NOT NULL DEFAULT 0
    COMMENT 'Show the M-Pesa callbacks quick-view panel on the terminal dashboard',
  `mpesa_feed_refresh_seconds`  SMALLINT UNSIGNED NOT NULL DEFAULT 5
    COMMENT 'Auto-refresh interval in seconds (5 or 10)',
  `mpesa_feed_max_hours`        SMALLINT UNSIGNED NOT NULL DEFAULT 24
    COMMENT 'How many hours back to show payments',
  `mpesa_feed_max_visible`      SMALLINT UNSIGNED NOT NULL DEFAULT 50
    COMMENT 'Maximum number of payments to display',

  -- Quick STK prompt
  `show_quick_stk`              TINYINT(1)      NOT NULL DEFAULT 0
    COMMENT 'Show the Quick STK Prompt shortcut on the terminal dashboard',

  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_branch` (`company_id`, `branch_id`),
  CONSTRAINT `fk_pts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pts_branch`  FOREIGN KEY (`branch_id`)  REFERENCES `branches`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
