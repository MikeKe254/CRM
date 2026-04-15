-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 35 — Discount layer on transactions
--
-- Adds gross_amount, discount_amount, discount_reason, discount_by_user_id
-- to pos_transactions so discounts are tracked explicitly and auditably.
--
-- Design rules:
--   • amount         = net amount charged — what the customer pays (unchanged)
--   • gross_amount   = full price before any discount; equals amount when no discount
--   • discount_amount = the deduction applied (0 when no discount)
--   • discount_reason = required when discount > 0 (auditable justification)
--   • discount_by_user_id = the cashier who applied the discount
--
--   Legacy rows (before this migration): gross_amount defaults to 0.
--   In reporting: when gross_amount = 0 and amount > 0, treat amount as gross.
--
-- Permission:
--   apply_discount (ID 66) — must be granted on a role before a cashier can
--   apply any discount at the terminal. Without it the discount row is hidden.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `pos_transactions`
  ADD COLUMN `gross_amount`          DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0
                                      COMMENT 'Full price before discount. Equals amount when no discount applied.'
                                      AFTER `amount`,
  ADD COLUMN `discount_amount`       DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0
                                      COMMENT 'Discount deducted. net (amount) = gross_amount - discount_amount.'
                                      AFTER `gross_amount`,
  ADD COLUMN `discount_reason`       VARCHAR(200)  DEFAULT NULL
                                      COMMENT 'Required when discount_amount > 0. Auditable justification.'
                                      AFTER `discount_amount`,
  ADD COLUMN `discount_by_user_id`   INT UNSIGNED  DEFAULT NULL
                                      COMMENT 'User who applied the discount (cashier or manager).'
                                      AFTER `discount_reason`,
  ADD KEY `idx_pt_discount_user` (`discount_by_user_id`);

-- Permission: apply_discount (ID 66)
INSERT INTO `permissions` (`id`, `name`, `category`, `description`, `action_key`, `scope`)
VALUES
  (66, 'Apply Discounts', 'pos', 'Apply discounts at checkout — reason always required', 'APPLY_DISCOUNT', 'any')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
