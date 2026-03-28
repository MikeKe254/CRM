-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 19 — Loyalty System
--
-- Tables:
--   loyalty_programs  — one program per company (later per branch)
--   loyalty_tiers     — tier definitions per program (Bronze/Silver/Gold etc.)
--   loyalty_accounts  — one account per customer per program
--   loyalty_ledger    — every points movement (earn/redeem/adjust/bonus)
-- ─────────────────────────────────────────────────────────────────────────────

-- ── loyalty_programs ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `loyalty_programs` (
  `id`                  INT(11)         NOT NULL AUTO_INCREMENT,
  `company_id`          INT(11)         NOT NULL,
  `branch_id`           INT(11)         DEFAULT NULL COMMENT 'NULL = company-wide program',

  `program_name`        VARCHAR(100)    NOT NULL DEFAULT 'Loyalty Program'
                          COMMENT 'e.g. Koma Rewards',
  `points_name`         VARCHAR(50)     NOT NULL DEFAULT 'Points'
                          COMMENT 'Branded name e.g. Stars, Coins, Gems',
  `points_symbol`       VARCHAR(10)     DEFAULT NULL
                          COMMENT 'Short symbol e.g. pts, ⭐',

  -- Earning rule: how many points per unit amount
  `points_per_unit`     INT(11)         NOT NULL DEFAULT 1
                          COMMENT 'Points awarded per unit_amount spent',
  `unit_amount`         DECIMAL(10,2)   NOT NULL DEFAULT 100.00
                          COMMENT 'KES amount per points_per_unit. Default: KES 100 = 1 point',

  -- Enrollment bonus
  `enroll_bonus_points` INT(11)         NOT NULL DEFAULT 0
                          COMMENT 'Bonus points on first enrollment',

  `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_lp_company`  (`company_id`),
  KEY `idx_lp_branch`   (`branch_id`),

  CONSTRAINT `fk_lp_company`  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lp_branch`   FOREIGN KEY (`branch_id`)  REFERENCES `branches`  (`id`) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Loyalty program configuration per company. Defines point earning rate and branding.';


-- ── loyalty_tiers ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `loyalty_tiers` (
  `id`                  INT(11)         NOT NULL AUTO_INCREMENT,
  `loyalty_program_id`  INT(11)         NOT NULL,
  `company_id`          INT(11)         NOT NULL,

  `name`                VARCHAR(50)     NOT NULL COMMENT 'e.g. Bronze, Silver, Gold, Platinum',
  `min_points`          INT(11)         NOT NULL DEFAULT 0
                          COMMENT 'Minimum points balance to qualify for this tier',
  `color`               VARCHAR(20)     DEFAULT NULL
                          COMMENT 'Tailwind color key or hex e.g. amber-600 or #D97706',
  `perks_description`   TEXT            DEFAULT NULL,
  `sort_order`          INT(11)         NOT NULL DEFAULT 0,
  `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_lt_program`  (`loyalty_program_id`),

  CONSTRAINT `fk_lt_program`  FOREIGN KEY (`loyalty_program_id`) REFERENCES `loyalty_programs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lt_company`  FOREIGN KEY (`company_id`)         REFERENCES `companies`        (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tier definitions per loyalty program. Tiers are ranked by min_points ascending.';


-- ── loyalty_accounts ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `loyalty_accounts` (
  `id`                    INT(11)         NOT NULL AUTO_INCREMENT,
  `company_id`            INT(11)         NOT NULL,
  `loyalty_program_id`    INT(11)         NOT NULL,
  `customer_id`           BIGINT(20) UNSIGNED NOT NULL,
  `msisdn`                VARCHAR(20)     NOT NULL COMMENT 'Denormalised for fast lookup without join',

  `points_balance`        INT(11)         NOT NULL DEFAULT 0,
  `total_points_earned`   INT(11)         NOT NULL DEFAULT 0,
  `total_points_redeemed` INT(11)         NOT NULL DEFAULT 0,

  `loyalty_tier_id`       INT(11)         DEFAULT NULL,

  `enrolled_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_la_customer_program`  (`customer_id`, `loyalty_program_id`),
  UNIQUE KEY `uk_la_msisdn_program`    (`msisdn`, `loyalty_program_id`),
  KEY        `idx_la_company`          (`company_id`),
  KEY        `idx_la_tier`             (`loyalty_tier_id`),

  CONSTRAINT `fk_la_program`   FOREIGN KEY (`loyalty_program_id`) REFERENCES `loyalty_programs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_la_customer`  FOREIGN KEY (`customer_id`)        REFERENCES `customers`        (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_la_tier`      FOREIGN KEY (`loyalty_tier_id`)    REFERENCES `loyalty_tiers`    (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_la_company`   FOREIGN KEY (`company_id`)         REFERENCES `companies`        (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One loyalty account per customer per program. Tracks balance and current tier.';


-- ── loyalty_ledger ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `loyalty_ledger` (
  `id`                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id`          INT(11)             NOT NULL,
  `loyalty_account_id`  INT(11)             NOT NULL,
  `pos_transaction_id`  INT(11)             DEFAULT NULL
                          COMMENT 'FK to pos_transactions — NULL for manual adjustments',

  `type`                ENUM('earn','redeem','adjust','enroll_bonus','expiry')
                          NOT NULL DEFAULT 'earn',
  `points`              INT(11)             NOT NULL
                          COMMENT 'Positive = earn/bonus, negative = redeem/expiry/adjust',
  `balance_after`       INT(11)             NOT NULL
                          COMMENT 'Account balance after this entry — for audit trail',

  `note`                VARCHAR(255)        DEFAULT NULL,
  `created_by_user_id`  INT(11)             DEFAULT NULL
                          COMMENT 'Cashier/staff who triggered this entry — NULL = system',
  `created_at`          TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_ll_account`      (`loyalty_account_id`),
  KEY `idx_ll_transaction`  (`pos_transaction_id`),
  KEY `idx_ll_company`      (`company_id`),

  CONSTRAINT `fk_ll_account`  FOREIGN KEY (`loyalty_account_id`) REFERENCES `loyalty_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ll_company`  FOREIGN KEY (`company_id`)         REFERENCES `companies`        (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable ledger of every points movement per loyalty account.';


-- ── Seed default loyalty program + tiers for company 1 ───────────────────────

INSERT IGNORE INTO `loyalty_programs`
  (`company_id`, `branch_id`, `program_name`, `points_name`, `points_symbol`,
   `points_per_unit`, `unit_amount`, `enroll_bonus_points`, `is_active`)
VALUES
  (1, NULL, 'Koma Rewards', 'Points', 'pts', 1, 100.00, 0, 1);

-- Seed default tiers for that program
-- The program just inserted gets id=1 (or use last_insert_id if already exists)
INSERT IGNORE INTO `loyalty_tiers`
  (`loyalty_program_id`, `company_id`, `name`, `min_points`, `color`, `sort_order`)
SELECT lp.id, 1, tier.name, tier.min_points, tier.color, tier.sort_order
FROM `loyalty_programs` lp
CROSS JOIN (
  SELECT 'Bronze'   AS name,    0 AS min_points, 'amber-700'   AS color, 1 AS sort_order UNION ALL
  SELECT 'Silver',            200,               'gray-400',             2               UNION ALL
  SELECT 'Gold',              500,               'yellow-500',           3               UNION ALL
  SELECT 'Platinum',         1000,               'cyan-400',             4
) tier
WHERE lp.company_id = 1 AND lp.is_active = 1
LIMIT 4;
