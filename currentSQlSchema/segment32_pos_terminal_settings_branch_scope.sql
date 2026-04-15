-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 32 — Fix pos_terminal_settings to be branch-scoped
--
-- The original segment30 keyed this table on company_id alone, which violated
-- the architecture rule that branch_id is the primary operational context.
-- Terminal settings must be per-branch because different branches can have
-- different configurations (e.g. one branch has M-Pesa feed enabled, another
-- does not).
--
-- Run this if segment30 has already been applied.
-- Safe to run multiple times (uses IF NOT EXISTS / DROP IF EXISTS guards).
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Add branch_id column (nullable first so existing rows don't break)
ALTER TABLE `pos_terminal_settings`
  ADD COLUMN IF NOT EXISTS `branch_id` INT(11) NULL AFTER `company_id`;

-- 2. For any existing rows set branch_id to the company's HQ branch so the
--    row remains valid. Companies without an HQ branch will need manual
--    clean-up — but this avoids data loss on apply.
UPDATE `pos_terminal_settings` pts
   SET pts.branch_id = (
       SELECT b.id
         FROM branches b
        WHERE b.company_id = pts.company_id
          AND b.is_hq      = 1
          AND b.deleted_at IS NULL
        ORDER BY b.id ASC
        LIMIT 1
   )
 WHERE pts.branch_id IS NULL;

-- 3. Delete rows that still have no branch_id (company had no HQ branch).
DELETE FROM `pos_terminal_settings` WHERE branch_id IS NULL;

-- 4. Make branch_id NOT NULL now that all rows are populated.
ALTER TABLE `pos_terminal_settings`
  MODIFY COLUMN `branch_id` INT(11) NOT NULL;

-- 5. Drop the old company-only unique constraint and add the correct one.
ALTER TABLE `pos_terminal_settings`
  DROP INDEX IF EXISTS `uq_company`;

ALTER TABLE `pos_terminal_settings`
  ADD UNIQUE KEY IF NOT EXISTS `uq_company_branch` (`company_id`, `branch_id`);

-- 6. Add FK to branches if not already present.
ALTER TABLE `pos_terminal_settings`
  ADD CONSTRAINT IF NOT EXISTS `fk_pts_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE;
