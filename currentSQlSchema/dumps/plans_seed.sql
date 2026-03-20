-- =============================================================================
-- Angavu CRM — Plans, Plan Features, Plan Limits & Tenant Overrides
-- Run AFTER modules_seed.sql (depends on module_features existing).
-- =============================================================================

-- Drop order: dependants first
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `tenant_feature_overrides`;
DROP TABLE IF EXISTS `company_plan_history`;
DROP TABLE IF EXISTS `plan_limits`;
DROP TABLE IF EXISTS `plan_features`;
DROP TABLE IF EXISTS `plans`;
SET FOREIGN_KEY_CHECKS = 1;


-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: plans
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `plans` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(100)    NOT NULL              COMMENT 'Display name: Starter, Growth, Enterprise',
  `slug`              VARCHAR(100)    NOT NULL              COMMENT 'Machine key: starter, growth, enterprise, custom',
  `description`       VARCHAR(255)    NULL,
  `monthly_price`     DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'KES monthly price; 0 = free',
  `annual_price`      DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'KES annual price (full year, not per month)',
  `currency`          CHAR(3)         NOT NULL DEFAULT 'KES',
  `trial_days`        SMALLINT        NOT NULL DEFAULT 0    COMMENT 'Free trial days before billing starts',
  `grace_period_days` TINYINT         NOT NULL DEFAULT 3    COMMENT 'Days of access after payment fails before locking out',
  `is_public`         TINYINT(1)      NOT NULL DEFAULT 1    COMMENT '0 = internal/custom plan, not shown on pricing page',
  `is_active`         TINYINT(1)      NOT NULL DEFAULT 1,
  `sort_order`        SMALLINT        NOT NULL DEFAULT 0,
  `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plans_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: plan_features
-- Which module_features are included in each plan.
-- One row per plan-feature pair.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `plan_features` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `plan_id`     INT UNSIGNED  NOT NULL,
  `feature_id`  INT UNSIGNED  NOT NULL COMMENT 'FK → module_features.id',
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_feature` (`plan_id`, `feature_id`),
  CONSTRAINT `fk_plan_features_plan`
    FOREIGN KEY (`plan_id`)    REFERENCES `plans` (`id`)           ON DELETE CASCADE,
  CONSTRAINT `fk_plan_features_feature`
    FOREIGN KEY (`feature_id`) REFERENCES `module_features` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: plan_limits
-- Quantitative caps per plan (max users, branches, SMS, etc.)
-- limit_value = -1 means unlimited.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `plan_limits` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `plan_id`      INT UNSIGNED  NOT NULL,
  `limit_key`    VARCHAR(100)  NOT NULL COMMENT 'e.g. max_users, max_branches, sms_per_month, data_retention_days, max_products',
  `limit_value`  INT           NOT NULL COMMENT '-1 = unlimited',
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_limit` (`plan_id`, `limit_key`),
  CONSTRAINT `fk_plan_limits_plan`
    FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: company_plan_history
-- Full audit trail of every plan change for a tenant.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `company_plan_history` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `company_id`          INT           NOT NULL,
  `plan_id`             INT UNSIGNED  NOT NULL,
  `changed_by_admin_id` INT           NULL     COMMENT 'platform_admins.id — who made the change',
  `reason`              VARCHAR(255)  NULL     COMMENT 'e.g. Upgraded, Downgraded, Trial expired, Manual override',
  `started_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at`            TIMESTAMP     NULL     COMMENT 'NULL = current active plan',
  PRIMARY KEY (`id`),
  KEY `idx_company_history` (`company_id`, `started_at`),
  CONSTRAINT `fk_cph_plan`
    FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: tenant_feature_overrides
-- Per-tenant exceptions on top of their plan.
-- Platform admins can force-enable or force-disable any feature for any tenant.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `tenant_feature_overrides` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `company_id`          INT           NOT NULL,
  `feature_id`          INT UNSIGNED  NOT NULL COMMENT 'FK → module_features.id',
  `is_enabled`          TINYINT(1)    NOT NULL COMMENT '1 = force ON (even if plan excludes it), 0 = force OFF (even if plan includes it)',
  `reason`              VARCHAR(255)  NULL     COMMENT 'Why this override was applied',
  `added_by_admin_id`   INT           NULL     COMMENT 'platform_admins.id',
  `expires_at`          TIMESTAMP     NULL     COMMENT 'NULL = permanent override',
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_feature_override` (`company_id`, `feature_id`),
  CONSTRAINT `fk_tfo_feature`
    FOREIGN KEY (`feature_id`) REFERENCES `module_features` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- ALTER: companies — replace the plan VARCHAR with a proper FK
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `companies`
  ADD COLUMN `plan_id`         INT UNSIGNED NULL     AFTER `plan`,
  ADD COLUMN `trial_ends_at`   TIMESTAMP    NULL     AFTER `plan_id`,
  ADD COLUMN `plan_expires_at` TIMESTAMP    NULL     COMMENT 'NULL = no expiry (monthly rolling)' AFTER `trial_ends_at`,
  ADD CONSTRAINT `fk_companies_plan`
    FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL;


-- =============================================================================
-- SEED DATA
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- PLANS (5)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `plans` (`id`, `name`, `slug`, `description`, `monthly_price`, `annual_price`, `trial_days`, `is_public`, `sort_order`) VALUES
(1, 'Free',       'free',       'Limited access for trying out the platform. No billing.',                     0.00,      0.00,      0,  1, 1),
(2, 'Starter',    'starter',    'Core CRM, transactions and business management for small teams.',         2500.00,  25000.00,  14,  1, 2),
(3, 'Growth',     'growth',     'Starter plus marketing, loyalty, online orders and communications.',      6500.00,  65000.00,  14,  1, 3),
(4, 'Enterprise', 'enterprise', 'Everything including integrations, API access and advanced analytics.',  15000.00, 150000.00, 14,  1, 4),
(5, 'Custom',     'custom',     'Manually configured plan for special accounts. Contact sales.',               0.00,      0.00,   0,  0, 5);


-- ─────────────────────────────────────────────────────────────────────────────
-- PLAN LIMITS
-- limit_value = -1 means unlimited
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `plan_limits` (`plan_id`, `limit_key`, `limit_value`) VALUES

-- Free
(1, 'max_users',             2),
(1, 'max_branches',          1),
(1, 'max_products',         20),
(1, 'sms_per_month',         0),
(1, 'api_calls_per_month',   0),
(1, 'data_retention_days',  30),

-- Starter
(2, 'max_users',             5),
(2, 'max_branches',          2),
(2, 'max_products',        100),
(2, 'sms_per_month',       200),
(2, 'api_calls_per_month',   0),
(2, 'data_retention_days', 180),

-- Growth
(3, 'max_users',            20),
(3, 'max_branches',          5),
(3, 'max_products',        500),
(3, 'sms_per_month',      1000),
(3, 'api_calls_per_month',   0),
(3, 'data_retention_days', 365),

-- Enterprise
(4, 'max_users',            -1),
(4, 'max_branches',         -1),
(4, 'max_products',         -1),
(4, 'sms_per_month',      5000),
(4, 'api_calls_per_month', 10000),
(4, 'data_retention_days', -1),

-- Custom (fully unlimited — overrides applied per tenant)
(5, 'max_users',            -1),
(5, 'max_branches',         -1),
(5, 'max_products',         -1),
(5, 'sms_per_month',        -1),
(5, 'api_calls_per_month',  -1),
(5, 'data_retention_days',  -1);


-- ─────────────────────────────────────────────────────────────────────────────
-- PLAN FEATURES
-- Using subqueries against modules/submodules so we don't hard-code feature IDs.
-- Each block clearly states what the plan gets and why.
-- ─────────────────────────────────────────────────────────────────────────────

-- ── FREE (plan 1) ─────────────────────────────────────────────────────────────
-- Customer CRM: profiles submodule only (no activity history, no segmentation)
-- Transactions: records only (can log transactions, no order management or refunds)
-- Business Management: users + permissions (need to manage their small team)
-- Settings: all (they need to configure their account)
-- Security: all (authentication and logs always available)
INSERT INTO `plan_features` (`plan_id`, `feature_id`)
SELECT 1, mf.id
FROM module_features mf
JOIN module_submodules ms ON ms.id = mf.submodule_id
JOIN modules m ON m.id = ms.module_id
WHERE (m.slug = 'customer_crm'        AND ms.slug = 'profiles')
   OR (m.slug = 'transactions'         AND ms.slug = 'records')
   OR (m.slug = 'business_management'  AND ms.slug IN ('users', 'permissions'))
   OR (m.slug = 'settings')
   OR (m.slug = 'security');


-- ── STARTER (plan 2) ──────────────────────────────────────────────────────────
-- Everything in Free PLUS:
-- Customer CRM: all submodules (activity + segmentation unlocked)
-- Transactions: all submodules (orders + payments + refunds)
-- Business Management: all (branches unlocked)
-- Analytics: revenue only (basic revenue reports and trends)
-- Menu: products + categories + availability (no public menu link / QR yet)
-- Payments: processing only (confirmation and status — no new integrations)
INSERT INTO `plan_features` (`plan_id`, `feature_id`)
SELECT 2, mf.id
FROM module_features mf
JOIN module_submodules ms ON ms.id = mf.submodule_id
JOIN modules m ON m.id = ms.module_id
WHERE (m.slug = 'customer_crm')
   OR (m.slug = 'transactions')
   OR (m.slug = 'business_management')
   OR (m.slug = 'analytics'   AND ms.slug = 'revenue')
   OR (m.slug = 'menu'        AND ms.slug IN ('products', 'categories', 'availability'))
   OR (m.slug = 'payments'    AND ms.slug = 'processing')
   OR (m.slug = 'settings')
   OR (m.slug = 'security');


-- ── GROWTH (plan 3) ───────────────────────────────────────────────────────────
-- Everything in Starter PLUS:
-- Analytics: all (customers, sales behavior, menu performance unlocked)
-- Menu: all (public menu link + QR + multi-branch menus unlocked)
-- Online Orders: all (cart, order management)
-- Payments: all (MPesa, bank, custom integrations unlocked)
-- Marketing: all (campaigns, targeting, promotions)
-- Loyalty: all (points, rewards, loyalty history)
-- Communications: all (SMS/email notifications + automation)
-- Inventory: all (stock levels + alerts)
INSERT INTO `plan_features` (`plan_id`, `feature_id`)
SELECT 3, mf.id
FROM module_features mf
JOIN module_submodules ms ON ms.id = mf.submodule_id
JOIN modules m ON m.id = ms.module_id
WHERE (m.slug = 'customer_crm')
   OR (m.slug = 'transactions')
   OR (m.slug = 'business_management')
   OR (m.slug = 'analytics')
   OR (m.slug = 'menu')
   OR (m.slug = 'online_orders')
   OR (m.slug = 'payments')
   OR (m.slug = 'marketing')
   OR (m.slug = 'loyalty')
   OR (m.slug = 'communications')
   OR (m.slug = 'inventory')
   OR (m.slug = 'settings')
   OR (m.slug = 'security');


-- ── ENTERPRISE (plan 4) ───────────────────────────────────────────────────────
-- Everything in Growth PLUS:
-- Integrations: all (API access, API keys, webhooks, external services)
INSERT INTO `plan_features` (`plan_id`, `feature_id`)
SELECT 4, mf.id
FROM module_features mf
JOIN module_submodules ms ON ms.id = mf.submodule_id
JOIN modules m ON m.id = ms.module_id;
-- Enterprise gets ALL features — no WHERE clause


-- ── CUSTOM (plan 5) ───────────────────────────────────────────────────────────
-- Starts with all features enabled — overrides applied per tenant via tenant_feature_overrides
INSERT INTO `plan_features` (`plan_id`, `feature_id`)
SELECT 5, mf.id
FROM module_features mf
JOIN module_submodules ms ON ms.id = mf.submodule_id
JOIN modules m ON m.id = ms.module_id;
-- Custom gets ALL features — tenant_feature_overrides handles exceptions
