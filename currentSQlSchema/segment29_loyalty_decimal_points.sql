-- Segment 29 — Loyalty decimal points
--
-- Switches all loyalty point storage from INT to DECIMAL(12,2) so customers
-- can hold fractional points (e.g. 0.99, 47.88) rather than being floored to
-- whole numbers.  A KES 99 payment at 1pt/KES 100 now earns 0.99 pts instead
-- of 0 pts.  Redemption still works in whole points; the balance simply
-- carries the fractional remainder indefinitely.

ALTER TABLE `loyalty_accounts`
  MODIFY COLUMN `points_balance`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  MODIFY COLUMN `total_points_earned`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  MODIFY COLUMN `total_points_redeemed` DECIMAL(12,2) NOT NULL DEFAULT 0.00;

ALTER TABLE `loyalty_ledger`
  MODIFY COLUMN `points`        DECIMAL(12,2) NOT NULL,
  MODIFY COLUMN `balance_after` DECIMAL(12,2) NOT NULL;

ALTER TABLE `pos_transactions`
  MODIFY COLUMN `loyalty_points_awarded` DECIMAL(12,2) NOT NULL DEFAULT 0.00;

ALTER TABLE `mpesa_payments`
  MODIFY COLUMN `loyalty_points_awarded` DECIMAL(12,2) NOT NULL DEFAULT 0.00;
