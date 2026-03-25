-- ============================================================
-- Segment 9: Platform-level feature release gate + Multi Branch module
-- ============================================================

-- ── 1. Add platform_released to modules ──────────────────────
ALTER TABLE `modules`
    ADD COLUMN `platform_released` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Platform owner controls whether this entire module is released. 0 = all features under it are blocked.'
        AFTER `is_active`;

-- ── 2. Add platform_released to module_features ──────────────
ALTER TABLE `module_features`
    ADD COLUMN `platform_released` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Platform owner controls whether this specific feature is released. 0 = no tenant can use it.'
        AFTER `is_active`;

-- ── 3. Disable branch features platform-wide (in-development) ─
UPDATE `module_features`
   SET `platform_released` = 0
 WHERE `slug` IN ('branch_management', 'branch_assignment');

-- ── 4. Insert Multi Branch module ────────────────────────────
INSERT INTO `modules` (`id`, `name`, `slug`, `icon`, `description`, `sort_order`, `is_active`, `platform_released`) VALUES
(15, 'Multi Branch', 'multi_branch', 'lucide:git-branch', 'Multi-branch operations, hierarchy, and cross-branch management', 15, 1, 0);

-- ── 5. Insert submodules for Multi Branch ────────────────────
INSERT INTO `module_submodules` (`id`, `module_id`, `name`, `slug`, `sort_order`) VALUES
(39, 15, 'Branch Setup',     'branch_setup',     1),
(40, 15, 'Branch Hierarchy', 'branch_hierarchy', 2),
(41, 15, 'Branch Access',    'branch_access',    3),
(42, 15, 'Branch Reporting', 'branch_reporting', 4);

-- ── 6. Insert features for Multi Branch (all platform_released = 0) ─
INSERT INTO `module_features` (`id`, `submodule_id`, `name`, `slug`, `sort_order`, `is_active`, `platform_released`) VALUES
-- Branch Setup (submodule 39)
(100, 39, 'Create Branches',          'branch_create',                1, 1, 0),
(101, 39, 'Edit Branch Details',      'branch_edit',                  2, 1, 0),
(102, 39, 'Branch Settings',          'branch_settings',              3, 1, 0),
(103, 39, 'Deactivate Branches',      'branch_deactivate',            4, 1, 0),

-- Branch Hierarchy (submodule 40)
(104, 40, 'Hierarchy Setup (HQ / Regional / Local)', 'branch_hierarchy_setup', 1, 1, 0),
(105, 40, 'View Hierarchy Tree',      'branch_hierarchy_view',        2, 1, 0),

-- Branch Access (submodule 41)
(106, 41, 'Branch Role Assignment',   'branch_role_assignment',       1, 1, 0),
(107, 41, 'Branch User Assignment',   'branch_user_assignment',       2, 1, 0),
(108, 41, 'Branch Permission Management', 'branch_permission_management', 3, 1, 0),

-- Branch Reporting (submodule 42)
(109, 42, 'Revenue Per Branch',       'branch_revenue_report',        1, 1, 0),
(110, 42, 'Branch Performance Comparison', 'branch_performance_comparison', 2, 1, 0),
(111, 42, 'Cross-Branch Analytics',   'cross_branch_analytics',       3, 1, 0);
