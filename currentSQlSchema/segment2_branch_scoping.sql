-- ============================================================
-- Segment 2 — Branch Data Scoping
-- Run AFTER Segment 1 (branches + user_node_roles migration)
-- ============================================================

-- ── 1. Add branch_id to user_activity_logs ─────────────────
ALTER TABLE `user_activity_logs`
  ADD COLUMN `branch_id` int(11) NULL DEFAULT NULL
    COMMENT 'FK → branches.id — which branch context generated this log entry'
    AFTER `company_id`,
  ADD KEY `idx_branch_created` (`branch_id`, `created_at`);

-- Backfill existing logs → assign to each company's HQ branch
UPDATE `user_activity_logs` ual
  JOIN `branches` b ON b.`company_id` = ual.`company_id`
                   AND b.`is_hq`      = 1
                   AND b.`deleted_at` IS NULL
SET ual.`branch_id` = b.`id`
WHERE ual.`branch_id` IS NULL;


-- ── 2. Add branch_id to pos_terminals ──────────────────────
ALTER TABLE `pos_terminals`
  ADD COLUMN `branch_id` int(11) NULL DEFAULT NULL
    COMMENT 'FK → branches.id — which branch this terminal belongs to'
    AFTER `company_id`,
  ADD KEY `idx_terminal_branch` (`branch_id`);

-- Backfill existing terminals → assign to each company's HQ branch
UPDATE `pos_terminals` pt
  JOIN `branches` b ON b.`company_id` = pt.`company_id`
                   AND b.`is_hq`      = 1
                   AND b.`deleted_at` IS NULL
SET pt.`branch_id` = b.`id`
WHERE pt.`branch_id` IS NULL;
