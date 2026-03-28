-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 23 — mpesa_configs: manual_code_required flag
--
-- When enabled for an M-Pesa config, the cashier MUST enter the M-Pesa
-- transaction code (e.g. QHX4AB1234) before the "M-Pesa Received" button
-- becomes active on the manual-confirm panel at checkout step 3.
-- When disabled (default), the field is shown but optional.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `mpesa_configs`
  ADD COLUMN `manual_code_required` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = cashier must enter M-Pesa transaction code on manual confirm'
    AFTER `integration_mode`;
