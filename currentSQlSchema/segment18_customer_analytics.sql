-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 18 — `customer_analytics` table
--
-- Computed analytics for each customer, decoupled from identity.
-- Updated by background jobs / analytics engine, not by the POS flow.
-- Mirrors the useful columns from the legacy `customer_profiles` table
-- but linked properly to `customers.id`.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `customer_analytics` (
  `id`                          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`                 BIGINT(20) UNSIGNED NOT NULL,
  `company_id`                  INT(11)             NOT NULL,

  -- ── Spend ────────────────────────────────────────────────────────────────
  `all_time_spend`              DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `average_spend`               DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `highest_transaction`         DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `lowest_transaction`          DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `all_time_transactions`       INT(11)             NOT NULL DEFAULT 0,

  -- ── Visit dates ───────────────────────────────────────────────────────────
  `first_transaction`           DATETIME            DEFAULT NULL,
  `last_transaction`            DATETIME            DEFAULT NULL,
  `customer_age_days`           INT(11)             DEFAULT NULL,
  `days_since_last`             INT(11)             DEFAULT NULL,

  -- ── Frequency ────────────────────────────────────────────────────────────
  `visit_frequency_per_month`   DECIMAL(10,2)       DEFAULT NULL,
  `average_return_interval_days`DECIMAL(10,2)       DEFAULT NULL,
  `longest_interval_days`       INT(11)             DEFAULT NULL,
  `spend_velocity_per_month`    DECIMAL(14,2)       DEFAULT NULL,

  -- ── Segmentation ─────────────────────────────────────────────────────────
  `spending_segment`            VARCHAR(50)         DEFAULT NULL COMMENT 'e.g. High Spender, Occasional',
  `lifecycle_stage`             VARCHAR(50)         DEFAULT NULL COMMENT 'e.g. Active, At Risk, Churned',
  `churn_risk`                  VARCHAR(50)         DEFAULT NULL,
  `churn_probability`           DECIMAL(6,3)        DEFAULT NULL,

  -- ── RFM ──────────────────────────────────────────────────────────────────
  `rfm_recency_score`           INT(11)             DEFAULT NULL,
  `rfm_frequency_score`         INT(11)             DEFAULT NULL,
  `rfm_monetary_score`          INT(11)             DEFAULT NULL,
  `rfm_total_score`             INT(11)             DEFAULT NULL,

  -- ── Predictions ──────────────────────────────────────────────────────────
  `predicted_next_visit`        DATETIME            DEFAULT NULL,
  `predicted_lifetime_value`    DECIMAL(14,2)       DEFAULT NULL,
  `spending_growth_rate`        DECIMAL(10,4)       DEFAULT NULL,
  `engagement_score`            DECIMAL(10,2)       DEFAULT NULL,

  -- ── Time patterns ────────────────────────────────────────────────────────
  `most_common_visit_time`      VARCHAR(50)         DEFAULT NULL,
  `most_common_visit_day`       VARCHAR(50)         DEFAULT NULL,
  `weekday_visit_ratio`         DECIMAL(6,3)        DEFAULT NULL,
  `weekend_visit_ratio`         DECIMAL(6,3)        DEFAULT NULL,
  `morning_visit_ratio`         DECIMAL(6,3)        DEFAULT NULL,
  `afternoon_visit_ratio`       DECIMAL(6,3)        DEFAULT NULL,
  `evening_visit_ratio`         DECIMAL(6,3)        DEFAULT NULL,

  -- ── Rankings ─────────────────────────────────────────────────────────────
  `customer_rank`               INT(11)             DEFAULT NULL,
  `top_spender_percentile`      DECIMAL(6,3)        DEFAULT NULL,
  `revenue_share_percent`       DECIMAL(6,3)        DEFAULT NULL,

  `computed_at`                 TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                  TIMESTAMP           NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ca_customer`       (`customer_id`),
  KEY        `idx_ca_company`       (`company_id`),

  CONSTRAINT `fk_ca_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Computed analytics per customer — updated by analytics jobs, not real-time POS.';
