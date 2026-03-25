-- =============================================================================
-- Segment 3 — Branch Management Permission Seed
-- =============================================================================
-- Adds the manage_branches permission used by BranchController.
-- Run this once after deploying Segment 3.
-- =============================================================================

INSERT INTO `permissions` (`name`, `category`, `description`, `action_key`, `created_at`)
VALUES
    ('Manage Branches', 'branches', 'Create, rename, move, deactivate and delete branches within the user\'s authority scope', 'MANAGE_BRANCHES', NOW()),
    ('View Branch Reports', 'branches', 'View cross-branch reporting and subtree data aggregations', 'VIEW_BRANCH_REPORTS', NOW()),
    ('Assign Users to Branches', 'branches', 'Assign users to branches within authority scope', 'ASSIGN_USERS_TO_BRANCHES', NOW()),
    ('View All Branch Data', 'branches', 'See data in all descendant branches (inherited via node assignment)', 'VIEW_ALL_BRANCH_DATA', NOW());
