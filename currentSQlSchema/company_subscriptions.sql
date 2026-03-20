-- =============================================================================
-- Angavu CRM — Company Subscriptions
-- Replaces: company_plan_history + companies.trial_ends_at/plan_expires_at
--
-- company_subscriptions IS the source of truth for a tenant's plan lifecycle.
-- Each row = one subscription period. Rows accumulate — old ones remain with
-- status = 'expired'|'cancelled' giving you full history for free.
--
-- companies.plan_id stays as a read shortcut (UI, reporting) but is NOT relied
-- on for correctness. Feature access queries join through this table.
--
-- Run AFTER plans_seed.sql.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `company_subscriptions`;
DROP TABLE IF EXISTS `company_plan_history`;   -- absorbed into this table
SET FOREIGN_KEY_CHECKS = 1;


-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: company_subscriptions
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `company_subscriptions` (
  `id`                   INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `company_id`           INT(11)        NOT NULL,   -- matches companies.id int(11)
  `plan_id`              INT UNSIGNED   NOT NULL,

  -- Lifecycle
  `status`               ENUM(
                           'trial',      -- in free trial, not yet billed
                           'active',     -- paid and current
                           'past_due',   -- payment failed, grace period
                           'cancelled',  -- cancelled, access until ends_at
                           'expired'     -- access window closed
                         ) NOT NULL DEFAULT 'trial',

  `billing_cycle`        ENUM(
                           'monthly',
                           'annual',
                           'lifetime',   -- one-time, no expiry
                           'custom'      -- manually managed by platform admin
                         ) NOT NULL DEFAULT 'monthly',

  -- Timeline
  `started_at`           DATETIME       NOT NULL                    COMMENT 'When this subscription period began',
  `ends_at`              DATETIME       NULL                        COMMENT 'When access ends. NULL = lifetime/no expiry',
  `trial_ends_at`        DATETIME       NULL                        COMMENT 'Populated only when status = trial',
  `grace_ends_at`        DATETIME       NULL                        COMMENT 'Set when status → past_due. Access allowed until this datetime.',
  `cancelled_at`         DATETIME       NULL,
  `cancellation_reason`  VARCHAR(255)   NULL,
  `renewed_at`           DATETIME       NULL                        COMMENT 'Last renewal timestamp',

  -- Billing integration
  `external_ref`         VARCHAR(255)   NULL                        COMMENT 'Stripe / PesaPal / PayHere subscription ID',
  `amount_paid`          DECIMAL(10,2)  NULL                        COMMENT 'Actual amount collected for this period',

  -- Admin tracking
  `changed_by_admin_id`  INT(11)        NULL                        COMMENT 'platform_admins.id — who created/changed this subscription',
  `notes`                TEXT           NULL                        COMMENT 'Internal notes from platform admin',

  `created_at`           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Most frequent lookup: "get the active subscription for company X"
  KEY `idx_company_status`     (`company_id`, `status`),

  -- Cron jobs: find subscriptions expiring soon / already expired
  KEY `idx_status_ends`        (`status`, `ends_at`),

  -- History queries ordered by time
  KEY `idx_company_created`    (`company_id`, `created_at`),

  CONSTRAINT `fk_sub_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,

  CONSTRAINT `fk_sub_plan`
    FOREIGN KEY (`plan_id`)    REFERENCES `plans` (`id`)    ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- ALTER: companies
-- Drop the fields now owned by company_subscriptions.
-- Keep plan_id as a denormalized read shortcut (UI / reporting only).
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `companies`
  DROP COLUMN IF EXISTS `trial_ends_at`,
  DROP COLUMN IF EXISTS `plan_expires_at`;

-- Note: companies.plan_id stays. Update it in the same transaction whenever
-- you write a new active subscription row — keep them in sync.
-- The service never relies on it for correctness; it joins this table instead.
