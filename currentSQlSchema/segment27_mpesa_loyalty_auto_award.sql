-- Segment 27 — M-Pesa loyalty auto-award support
--
-- Adds the fields needed to:
--   1. opt in at loyalty-program level
--   2. opt in at M-Pesa-config level
--   3. mark callback-side award state on mpesa_payments
--   4. mark checkout-side synced/auto-awarded state on pos_transactions
--   5. reconcile callback awards back to loyalty_ledger rows

ALTER TABLE `loyalty_programs`
  ADD COLUMN `auto_award_enabled` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `redemption_enabled`,
  ADD COLUMN `auto_enroll_on_payment` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `auto_award_enabled`;

ALTER TABLE `mpesa_configs`
  ADD COLUMN `auto_award_loyalty` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `integration_enabled`;

ALTER TABLE `mpesa_payments`
  ADD COLUMN `loyalty_auto_awarded` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `claimed_by_user_id`,
  ADD COLUMN `loyalty_awarded_at` DATETIME DEFAULT NULL
    AFTER `loyalty_auto_awarded`,
  ADD COLUMN `loyalty_points_awarded` INT(11) NOT NULL DEFAULT 0
    AFTER `loyalty_awarded_at`,
  ADD COLUMN `loyalty_account_id` INT(11) DEFAULT NULL
    AFTER `loyalty_points_awarded`,
  ADD COLUMN `customer_id` BIGINT(20) UNSIGNED DEFAULT NULL
    AFTER `loyalty_account_id`,
  ADD KEY `idx_mp_loyalty_awarded` (`loyalty_auto_awarded`, `loyalty_awarded_at`),
  ADD KEY `idx_mp_loyalty_account` (`loyalty_account_id`),
  ADD KEY `idx_mp_customer` (`customer_id`);

ALTER TABLE `pos_transactions`
  ADD COLUMN `loyalty_auto_awarded` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `loyalty_points_awarded`,
  ADD COLUMN `loyalty_auto_awarded_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00
    AFTER `loyalty_auto_awarded`,
  ADD COLUMN `loyalty_awarded_at` DATETIME DEFAULT NULL
    AFTER `loyalty_auto_awarded_amount`,
  ADD COLUMN `loyalty_award_source` VARCHAR(50) DEFAULT NULL
    AFTER `loyalty_awarded_at`,
  ADD KEY `idx_pt_loyalty_auto` (`loyalty_auto_awarded`, `loyalty_awarded_at`);

ALTER TABLE `loyalty_ledger`
  ADD COLUMN `mpesa_payment_id` INT(11) DEFAULT NULL
    AFTER `pos_transaction_id`,
  ADD KEY `idx_ll_mpesa_payment` (`mpesa_payment_id`);
