-- ============================================================
-- Segment 8 — Add Enable Branches Feature Flag to Companies
-- Purpose: Allow companies to opt-out of branch/hierarchy structure
-- Run AFTER Segment 7
-- ============================================================

-- PART 1: Add enable_branches column to companies table
ALTER TABLE `companies`
ADD COLUMN `enable_branches` TINYINT(1) DEFAULT 1;

-- ── PART 2: Create index for feature flag queries ──────────────
CREATE INDEX `idx_companies_enable_branches` ON `companies` (`enable_branches`);

-- ── PART 3: Set default for existing companies ────────────────
-- For now, all existing companies keep branches enabled (1)
UPDATE `companies` SET `enable_branches` = 1 WHERE `enable_branches` IS NULL;

-- ── PART 4: Example — Disable for a test company (optional) ───
-- UPDATE `companies` SET `enable_branches` = 0 WHERE id = 99;

-- ── PART 5: Verification query ────────────────────────────────
SELECT
  c.id,
  c.name,
  c.enable_branches,
  COUNT(DISTINCT b.id) AS branch_count
FROM `companies` c
LEFT JOIN `branches` b ON b.company_id = c.id AND b.deleted_at IS NULL
GROUP BY c.id, c.name, c.enable_branches
ORDER BY c.enable_branches DESC, c.id;
