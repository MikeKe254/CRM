-- ============================================================
-- Segment 12 — Area: is_transactional flag
-- Purpose: Mark areas where customers make payments/transactions
-- Run AFTER Segment 11
-- ============================================================

ALTER TABLE `areas`
ADD COLUMN `is_transactional` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = customers make payments in this area'
  AFTER `is_system`;
