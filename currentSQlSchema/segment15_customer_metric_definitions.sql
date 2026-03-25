-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 15 — customer_metric_definitions
--
-- Migrates metric labels + definitions out of src/Config/customer_metrics.php
-- and into a database table so they can be managed without a code deploy.
--
-- CustomerMetricsService reads this table instead of the PHP file.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE `customer_metric_definitions` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `section_name` VARCHAR(100)    NOT NULL,
    `section_sort` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `metric_key`   VARCHAR(100)    NOT NULL,
    `label`        VARCHAR(150)    NOT NULL,
    `definition`   TEXT            NOT NULL,
    `metric_sort`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_metric_key` (`metric_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO `customer_metric_definitions`
    (`section_name`, `section_sort`, `metric_key`, `label`, `definition`, `metric_sort`)
VALUES

-- ── Identity & Overview ───────────────────────────────────────────────────────
('Identity & Overview', 1, 'first_name',          'First Name',             'The first name of the customer as recorded during their first transaction.',                                                               1),
('Identity & Overview', 1, 'gender',               'Gender',                 'The gender of the customer as detected from their name.',                                                                                  2),
('Identity & Overview', 1, 'msisdn',               'Phone Number',           'The mobile number used by the customer for all recorded transactions.',                                                                    3),
('Identity & Overview', 1, 'first_transaction',    'First Transaction',      'The date and time of the first ever payment recorded from this customer.',                                                                 4),
('Identity & Overview', 1, 'last_transaction',     'Last Transaction',       'The date and time of the most recent payment recorded from this customer.',                                                                5),
('Identity & Overview', 1, 'customer_age_days',    'Customer Age (Days)',    'The number of days since the customer first made a payment at this business.',                                                             6),
('Identity & Overview', 1, 'customer_age_months',  'Customer Age (Months)', 'The number of months since the customer first made a payment.',                                                                             7),
('Identity & Overview', 1, 'days_since_last',      'Days Since Last Visit',  'The number of days that have passed since the customer last made a payment.',                                                              8),

-- ── Spending Statistics ───────────────────────────────────────────────────────
('Spending Statistics', 2, 'all_time_spend',        'All Time Spend',        'The total amount of money spent by the customer across all recorded transactions.',                                                        1),
('Spending Statistics', 2, 'average_spend',          'Average Spend',         'The average transaction value. It is calculated as: Total Spend divided by Total Transactions.',                                          2),
('Spending Statistics', 2, 'highest_transaction',    'Highest Transaction',   'The largest single payment ever made by the customer.',                                                                                   3),
('Spending Statistics', 2, 'lowest_transaction',     'Lowest Transaction',    'The smallest payment recorded from the customer.',                                                                                        4),
('Spending Statistics', 2, 'all_time_transactions',  'Total Transactions',    'The total number of payments made by the customer.',                                                                                      5),

-- ── Visit Behavior ────────────────────────────────────────────────────────────
('Visit Behavior', 3, 'average_return_interval_days', 'Average Return Interval', 'The average number of days between each customer visit.',                                                                              1),
('Visit Behavior', 3, 'longest_interval_days',         'Longest Visit Gap',       'The longest period of inactivity between two visits.',                                                                               2),
('Visit Behavior', 3, 'visit_frequency_per_month',     'Visits Per Month',        'The average number of visits the customer makes each month.',                                                                        3),
('Visit Behavior', 3, 'spend_velocity_per_month',      'Spend Velocity Per Month','The average monthly revenue generated by the customer.',                                                                             4),

-- ── Revenue Contribution ──────────────────────────────────────────────────────
('Revenue Contribution', 4, 'revenue_share_percent', 'Revenue Share', 'The percentage of total business revenue that comes from this customer.',                                                                         1),

-- ── Customer Ranking ──────────────────────────────────────────────────────────
('Customer Ranking', 5, 'customer_rank',           'Customer Rank',           'The ranking position of the customer when all customers are ordered by total spending. Rank 1 represents the highest spending customer.', 1),
('Customer Ranking', 5, 'top_spender_percentile',  'Top Spender Percentile',  'The percentile ranking of the customer among all customers based on spending.',                                                          2),
('Customer Ranking', 5, 'spending_segment',        'Spending Segment',        'Customers are grouped into segments based on their spending percentile. Whale (top 1%), VIP (top 5%), High Value (top 10%), Regular (top 50%), Low Value (remaining).', 3),

-- ── Customer Loyalty & Lifecycle ─────────────────────────────────────────────
('Customer Loyalty & Lifecycle', 6, 'loyalty_tier',    'Loyalty Tier',    'A loyalty classification used to group customers by value and engagement. Tiers: Bronze, Silver, Gold, Platinum, Diamond.', 1),
('Customer Loyalty & Lifecycle', 6, 'lifecycle_stage', 'Lifecycle Stage', 'The stage of the customer relationship based on visit count.',                                                               2),

-- ── Churn Prediction ─────────────────────────────────────────────────────────
('Churn Prediction', 7, 'churn_risk',        'Churn Risk',        'A classification indicating the likelihood that the customer may stop visiting the business.', 1),
('Churn Prediction', 7, 'churn_probability', 'Churn Probability', 'The estimated probability that the customer may stop returning.',                              2),

-- ── Customer Value Prediction ─────────────────────────────────────────────────
('Customer Value Prediction', 8, 'predicted_next_visit',      'Predicted Next Visit',      'An estimated date when the customer is expected to return.',                                                         1),
('Customer Value Prediction', 8, 'predicted_lifetime_value',  'Predicted Lifetime Value',  'The estimated revenue the customer is expected to generate over the next 12 months.',                                2),

-- ── Growth & Engagement ───────────────────────────────────────────────────────
('Growth & Engagement', 9, 'spending_growth_rate',      'Spending Growth Rate',     'Measures whether the customer spending is increasing or decreasing over time.',                                             1),
('Growth & Engagement', 9, 'engagement_score',          'Engagement Score',         'A composite score combining visit frequency, spending velocity, and RFM score.',                                            2),
('Growth & Engagement', 9, 'visit_consistency_score',   'Visit Consistency Score',  'A measure of how predictable the customer visit pattern is.',                                                              3),

-- ── Visit Timing Behavior ─────────────────────────────────────────────────────
('Visit Timing Behavior', 10, 'weekday_visit_ratio',       'Weekday Visit Ratio',       'The percentage of visits that occur during weekdays.',                                      1),
('Visit Timing Behavior', 10, 'weekend_visit_ratio',       'Weekend Visit Ratio',       'The percentage of visits that occur during weekends.',                                      2),
('Visit Timing Behavior', 10, 'morning_visit_ratio',       'Morning Visit Ratio',       'The percentage of visits that occur during the morning hours.',                             3),
('Visit Timing Behavior', 10, 'afternoon_visit_ratio',     'Afternoon Visit Ratio',     'The percentage of visits that occur during the afternoon.',                                 4),
('Visit Timing Behavior', 10, 'evening_visit_ratio',       'Evening Visit Ratio',       'The percentage of visits that occur during the evening hours.',                             5),
('Visit Timing Behavior', 10, 'night_visit_ratio',         'Night Visit Ratio',         'The percentage of visits that occur during night hours.',                                   6),
('Visit Timing Behavior', 10, 'most_common_visit_time',    'Most Common Visit Time',    'The time period during which the customer most frequently visits.',                         7),
('Visit Timing Behavior', 10, 'most_common_visit_day',     'Most Common Visit Day',     'The type of day when the customer most frequently visits.',                                 8),

-- ── Payment Behavior ─────────────────────────────────────────────────────────
('Payment Behavior', 11, 'favorite_reference',   'Favorite Payment Reference', 'The payment reference that the customer uses most frequently.',              1),
('Payment Behavior', 11, 'preferred_shortcode',  'Preferred Paybill',          'The paybill or shortcode most commonly used by the customer.',               2),
('Payment Behavior', 11, 'first_reference',      'First Payment Reference',    'The reference used in the very first payment recorded for this customer.',    3),
('Payment Behavior', 11, 'first_shortcode',      'First Paybill',              'The paybill used during the customer\'s first payment.',                     4),

-- ── System Metadata ───────────────────────────────────────────────────────────
('System Metadata', 12, 'anomaly_flag',         'Anomaly Flag',   'Indicates whether unusual patterns have been detected in the customer\'s behavior.',      1),
('System Metadata', 12, 'profile_created_at',   'Profile Created','The timestamp when the customer intelligence profile was generated.',                     2),
('System Metadata', 12, 'profile_updated_at',   'Profile Updated','The timestamp when the customer profile was last recalculated or updated.',               3);
