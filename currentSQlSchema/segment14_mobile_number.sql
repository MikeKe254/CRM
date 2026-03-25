-- ============================================================
-- Segment 14 — Mobile number on users & platform_admins
-- Run AFTER Segment 13
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `mobile` VARCHAR(20) NULL DEFAULT NULL
      COMMENT 'Staff mobile/phone number'
      AFTER `email`;

ALTER TABLE `platform_admins`
    ADD COLUMN `mobile` VARCHAR(20) NULL DEFAULT NULL
      COMMENT 'Platform admin mobile/phone number'
      AFTER `email`;
