-- ============================================================
-- Segment 5 — Add User Type Column (office/branch/both)
-- Purpose: Distinguish between office-level and branch-level users
-- Run AFTER Segment 4
-- ============================================================

-- PART 1: Add user_type column to users table
ALTER TABLE `users`
ADD COLUMN `user_type` ENUM('office', 'branch', 'both') DEFAULT 'branch' NOT NULL;

-- PART 2: Add soft-delete support
ALTER TABLE `users`
ADD COLUMN `deleted_at` TIMESTAMP NULL;

-- ── PART 3: Index for user_type queries ───────────────────────
CREATE INDEX `idx_users_user_type` ON `users` (`company_id`, `user_type`);
CREATE INDEX `idx_users_deleted_at` ON `users` (`deleted_at`);

-- ── PART 4: Data migration — Infer user_type from existing assignments
-- Logic:
--   - If user assigned only at depth=0 (HQ) → office
--   - If user assigned only at depth≥1 (regional/branch) → branch
--   - If assigned at both → both
-- Default users with no assignments → branch (safer assumption)

UPDATE `users` u
SET u.`user_type` = (
  CASE
    WHEN EXISTS (
      SELECT 1 FROM `user_node_roles` unr
      JOIN `branches` b ON b.id = unr.node_id
      WHERE unr.user_id = u.id AND b.depth = 0
    ) AND NOT EXISTS (
      SELECT 1 FROM `user_node_roles` unr
      JOIN `branches` b ON b.id = unr.node_id
      WHERE unr.user_id = u.id AND b.depth > 0
    ) THEN 'office'

    WHEN EXISTS (
      SELECT 1 FROM `user_node_roles` unr
      JOIN `branches` b ON b.id = unr.node_id
      WHERE unr.user_id = u.id AND b.depth > 0
    ) AND NOT EXISTS (
      SELECT 1 FROM `user_node_roles` unr
      JOIN `branches` b ON b.id = unr.node_id
      WHERE unr.user_id = u.id AND b.depth = 0
    ) THEN 'branch'

    WHEN EXISTS (
      SELECT 1 FROM `user_node_roles` unr
      JOIN `branches` b ON b.id = unr.node_id
      WHERE unr.user_id = u.id AND b.depth = 0
    ) AND EXISTS (
      SELECT 1 FROM `user_node_roles` unr
      JOIN `branches` b ON b.id = unr.node_id
      WHERE unr.user_id = u.id AND b.depth > 0
    ) THEN 'both'

    ELSE 'branch'  -- default for users with no assignments
  END
)
WHERE u.`deleted_at` IS NULL;

-- Verify migration
SELECT user_type, COUNT(*) AS count FROM `users` WHERE deleted_at IS NULL GROUP BY user_type;
