-- Segment 28 — Allow system-generated activity log entries
--
-- Patronrapis callback events (loyalty auto-award, auto-enroll, claim-sync)
-- have no interactive user session.  These changes let us write audit rows
-- with actor_type = 'system' and a NULL user_id so all financial loyalty
-- events appear in the audit trail, not just manual cashier actions.

ALTER TABLE `user_activity_logs`
  MODIFY COLUMN `user_id`    INT(11)                              DEFAULT NULL,
  MODIFY COLUMN `actor_type` ENUM('tenant','superadmin','system') NOT NULL DEFAULT 'tenant';

-- branch_id was inserted by UserActivityLogService but was absent from the
-- original schema dump.  Add it only if not already present.
ALTER TABLE `user_activity_logs`
  ADD COLUMN IF NOT EXISTS `branch_id` INT(11) DEFAULT NULL
    AFTER `company_id`,
  ADD KEY IF NOT EXISTS `idx_branch_created` (`branch_id`, `created_at`);
