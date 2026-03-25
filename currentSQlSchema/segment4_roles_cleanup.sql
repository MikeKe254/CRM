-- ============================================================
-- Segment 4 — Roles Cleanup & Branch Data Migration
-- Run AFTER Segment 3 (branch management)
-- ============================================================

-- ── PART 1: Wipe all user-node-role assignments ─────────────
-- (All will be re-seeded correctly below)
DELETE FROM `user_node_roles`;

-- ── PART 2: Wipe messy role_permissions and roles ───────────
DELETE FROM `role_permissions`;
DELETE FROM `roles`;
ALTER TABLE `roles` AUTO_INCREMENT = 1;

-- ── PART 3: Insert clean system roles (company_id=1) ────────
-- These seeded roles cover the core HQ, regional, branch-management, and branch-operations structure.
-- is_system_role=1 marks them as platform-seeded (not user-created).
INSERT INTO `roles` (`company_id`, `name`, `description`, `is_system_role`) VALUES
(1, 'Overall Manager',
    'Full authority across all branches. Assign at HQ or regional node.',           1),
(1, 'Branch Manager',
    'Manages a single branch: staff, POS terminals, reports, activity.',             1),
(1, 'Assistant Manager',
    'Supports branch leadership, supervises staff follow-through, and keeps branch operations moving.', 1),
(1, 'Department Manager',
    'Leads a functional department within a branch without hardcoding a specific department type.',      1),
(1, 'Supervisor',
    'Oversees day-to-day frontline execution inside a branch team under department leadership.',         1),
(1, 'Support Functions',
    'Handles shared branch support work under assistant management, including coordination and back-office execution.', 1),
(1, 'Cashier',
    'Front-desk and POS operator. Can view transactions and initiate payments.',     1),
(1, 'Viewer',
    'Read-only access to dashboard, transactions, and analytics.',                   1);

-- Capture the new IDs
SET @overall_mgr = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Overall Manager' LIMIT 1);
SET @branch_mgr  = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Branch Manager'  LIMIT 1);
SET @assistant_mgr = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Assistant Manager' LIMIT 1);
SET @department_mgr = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Department Manager' LIMIT 1);
SET @supervisor  = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Supervisor' LIMIT 1);
SET @support_functions = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Support Functions' LIMIT 1);
SET @cashier     = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Cashier'          LIMIT 1);
SET @viewer      = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Viewer'           LIMIT 1);

-- ── PART 4: Assign permissions to roles ─────────────────────

-- Overall Manager → every permission
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @overall_mgr, `id` FROM `permissions` WHERE `deleted_at` IS NULL;

-- Branch Manager → operational management (no role/permission admin, no delete users)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @branch_mgr, `id` FROM `permissions`
WHERE `action_key` IN (
    'VIEW_DASHBOARD', 'VIEW_TRANSACTION_CARDS',
    'VIEW_TRANSACTIONS', 'VIEW_TRANSACTION_DETAILS', 'VIEW_FULL_CUSTOMER_PHONE',
    'SEND_STK_PUSH',
    'ACCESS_ADVANCED_SEARCH', 'SEARCH_BY_SHORTCODE', 'SEARCH_BY_DATE_RANGE',
    'SEARCH_BY_PHONE', 'SEARCH_BY_REFERENCE', 'SEARCH_BY_TRANSACTION_ID', 'SEARCH_BY_AMOUNT',
    'VIEW_SEARCH_SUMMARY', 'VIEW_TOTAL_AMOUNT', 'VIEW_TOTAL_TRANSACTIONS',
    'VIEW_TOTAL_CUSTOMERS', 'VIEW_NEW_CUSTOMERS', 'VIEW_RETURNING_CUSTOMERS', 'VIEW_GENDER_BREAKDOWN',
    'VIEW_CUSTOMER_PROFILE',
    'EXPORT_ANALYTICS',
    'VIEW_USERS', 'CREATE_USERS', 'EDIT_USERS',
    'VIEW_ROLES', 'ASSIGN_ROLES',
    'AUTHORIZE_POS_TERMINAL',
    'VIEW_USER_ACTIVITY', 'VIEW_AUDIT_LOGS',
    'MANAGE_BRANCHES', 'VIEW_ALL_BRANCH_DATA'
)
AND `deleted_at` IS NULL;

-- Assistant Manager → branch supervision with staff management, without branch-owner controls
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @assistant_mgr, `id` FROM `permissions`
WHERE `action_key` IN (
    'VIEW_DASHBOARD', 'VIEW_TRANSACTION_CARDS',
    'VIEW_TRANSACTIONS', 'VIEW_TRANSACTION_DETAILS', 'VIEW_FULL_CUSTOMER_PHONE',
    'SEND_STK_PUSH',
    'ACCESS_ADVANCED_SEARCH', 'SEARCH_BY_SHORTCODE', 'SEARCH_BY_DATE_RANGE',
    'SEARCH_BY_PHONE', 'SEARCH_BY_REFERENCE', 'SEARCH_BY_TRANSACTION_ID', 'SEARCH_BY_AMOUNT',
    'VIEW_SEARCH_SUMMARY', 'VIEW_TOTAL_AMOUNT', 'VIEW_TOTAL_TRANSACTIONS',
    'VIEW_TOTAL_CUSTOMERS', 'VIEW_NEW_CUSTOMERS', 'VIEW_RETURNING_CUSTOMERS', 'VIEW_GENDER_BREAKDOWN',
    'VIEW_CUSTOMER_PROFILE',
    'EXPORT_ANALYTICS',
    'VIEW_USERS', 'CREATE_USERS', 'EDIT_USERS',
    'VIEW_BRANCH_REPORTS'
)
AND `deleted_at` IS NULL;

-- Department Manager → department-level oversight inside a branch
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @department_mgr, `id` FROM `permissions`
WHERE `action_key` IN (
    'VIEW_DASHBOARD', 'VIEW_TRANSACTION_CARDS',
    'VIEW_TRANSACTIONS', 'VIEW_TRANSACTION_DETAILS',
    'SEND_STK_PUSH',
    'ACCESS_ADVANCED_SEARCH', 'SEARCH_BY_SHORTCODE', 'SEARCH_BY_DATE_RANGE',
    'SEARCH_BY_PHONE', 'SEARCH_BY_REFERENCE', 'SEARCH_BY_TRANSACTION_ID', 'SEARCH_BY_AMOUNT',
    'VIEW_SEARCH_SUMMARY', 'VIEW_TOTAL_AMOUNT', 'VIEW_TOTAL_TRANSACTIONS',
    'VIEW_TOTAL_CUSTOMERS', 'VIEW_NEW_CUSTOMERS', 'VIEW_RETURNING_CUSTOMERS', 'VIEW_GENDER_BREAKDOWN',
    'VIEW_CUSTOMER_PROFILE',
    'VIEW_BRANCH_REPORTS'
)
AND `deleted_at` IS NULL;

-- Supervisor → frontline supervision without user or role administration
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @supervisor, `id` FROM `permissions`
WHERE `action_key` IN (
    'VIEW_DASHBOARD', 'VIEW_TRANSACTION_CARDS',
    'VIEW_TRANSACTIONS', 'VIEW_TRANSACTION_DETAILS',
    'SEND_STK_PUSH',
    'ACCESS_ADVANCED_SEARCH', 'SEARCH_BY_SHORTCODE', 'SEARCH_BY_DATE_RANGE',
    'SEARCH_BY_PHONE', 'SEARCH_BY_REFERENCE', 'SEARCH_BY_TRANSACTION_ID', 'SEARCH_BY_AMOUNT',
    'VIEW_SEARCH_SUMMARY', 'VIEW_TOTAL_AMOUNT', 'VIEW_TOTAL_TRANSACTIONS',
    'VIEW_TOTAL_CUSTOMERS', 'VIEW_NEW_CUSTOMERS', 'VIEW_RETURNING_CUSTOMERS', 'VIEW_GENDER_BREAKDOWN',
    'VIEW_CUSTOMER_PROFILE',
    'VIEW_BRANCH_REPORTS'
)
AND `deleted_at` IS NULL;

-- Support Functions → shared branch support and visibility without management administration
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @support_functions, `id` FROM `permissions`
WHERE `action_key` IN (
    'VIEW_DASHBOARD', 'VIEW_TRANSACTION_CARDS',
    'VIEW_TRANSACTIONS', 'VIEW_TRANSACTION_DETAILS',
    'ACCESS_ADVANCED_SEARCH', 'SEARCH_BY_SHORTCODE', 'SEARCH_BY_DATE_RANGE',
    'SEARCH_BY_PHONE', 'SEARCH_BY_REFERENCE', 'SEARCH_BY_TRANSACTION_ID', 'SEARCH_BY_AMOUNT',
    'VIEW_SEARCH_SUMMARY', 'VIEW_TOTAL_AMOUNT', 'VIEW_TOTAL_TRANSACTIONS',
    'VIEW_TOTAL_CUSTOMERS', 'VIEW_NEW_CUSTOMERS', 'VIEW_RETURNING_CUSTOMERS', 'VIEW_GENDER_BREAKDOWN',
    'VIEW_CUSTOMER_PROFILE',
    'VIEW_BRANCH_REPORTS'
)
AND `deleted_at` IS NULL;

-- Cashier → POS operations + basic transaction view
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @cashier, `id` FROM `permissions`
WHERE `action_key` IN (
    'VIEW_DASHBOARD', 'VIEW_TRANSACTION_CARDS',
    'VIEW_TRANSACTIONS', 'VIEW_TRANSACTION_DETAILS',
    'VIEW_CUSTOMER_PROFILE',
    'SEND_STK_PUSH'
)
AND `deleted_at` IS NULL;

-- Viewer → read-only analytics and reports
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @viewer, `id` FROM `permissions`
WHERE `action_key` IN (
    'VIEW_DASHBOARD', 'VIEW_TRANSACTION_CARDS',
    'VIEW_TRANSACTIONS', 'VIEW_TRANSACTION_DETAILS',
    'VIEW_CUSTOMER_PROFILE',
    'ACCESS_ADVANCED_SEARCH', 'SEARCH_BY_DATE_RANGE', 'SEARCH_BY_PHONE',
    'SEARCH_BY_TRANSACTION_ID', 'SEARCH_BY_AMOUNT',
    'VIEW_SEARCH_SUMMARY', 'VIEW_TOTAL_AMOUNT', 'VIEW_TOTAL_TRANSACTIONS',
    'VIEW_TOTAL_CUSTOMERS', 'VIEW_NEW_CUSTOMERS', 'VIEW_RETURNING_CUSTOMERS', 'VIEW_GENDER_BREAKDOWN',
    'EXPORT_ANALYTICS'
)
AND `deleted_at` IS NULL;

-- ── PART 4b: Restore custom roles (is_system_role=0) ─────────
-- These were company-created roles that must survive any system role migration.
-- Their permission sets are restored exactly as they existed before this migration.

INSERT INTO `roles` (`company_id`, `name`, `is_system_role`) VALUES
(1, 'Service Staff',      0),
(1, 'Retail Staff',       0),
(1, 'Housekeeping',       0),
(1, 'Car Wash Attendant', 0);

SET @service_staff    = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Service Staff'      LIMIT 1);
SET @retail_staff     = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Retail Staff'       LIMIT 1);
SET @housekeeping     = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Housekeeping'       LIMIT 1);
SET @car_wash         = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Car Wash Attendant' LIMIT 1);

-- Service Staff permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @service_staff, `id` FROM `permissions`
WHERE `action_key` IN (
    'ACCESS_ADVANCED_SEARCH','ASSIGN_PERMISSIONS','CREATE_PERMISSIONS','CREATE_USERS',
    'DELETE_USERS','EDIT_ROLES','EDIT_USERS','EXPORT_ANALYTICS','SEND_STK_PUSH',
    'VIEW_AUDIT_LOGS','VIEW_CUSTOMER_PROFILE','VIEW_FULL_CUSTOMER_PHONE',
    'VIEW_GENDER_BREAKDOWN','VIEW_PERMISSIONS','VIEW_ROLES','VIEW_SEARCH_SUMMARY',
    'VIEW_TOTAL_TRANSACTIONS','VIEW_TRANSACTIONS','VIEW_TRANSACTION_CARDS',
    'VIEW_TRANSACTION_DETAILS','VIEW_USERS'
) AND `deleted_at` IS NULL;

-- Retail Staff permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @retail_staff, `id` FROM `permissions`
WHERE `action_key` IN (
    'ACCESS_ADVANCED_SEARCH','ASSIGN_PERMISSIONS','ASSIGN_ROLES','AUTHORIZE_POS_TERMINAL',
    'CREATE_PERMISSIONS','CREATE_ROLES','CREATE_USERS','DELETE_PERMISSIONS','DELETE_ROLES',
    'DELETE_USERS','EDIT_ROLES','EDIT_USERS','EXPORT_ANALYTICS','SEARCH_BY_AMOUNT',
    'SEARCH_BY_DATE_RANGE','SEARCH_BY_PHONE','SEARCH_BY_REFERENCE','SEARCH_BY_SHORTCODE',
    'SEARCH_BY_TRANSACTION_ID','SEND_STK_PUSH','VIEW_AUDIT_LOGS','VIEW_CUSTOMER_PROFILE',
    'VIEW_DASHBOARD','VIEW_FULL_CUSTOMER_PHONE','VIEW_GENDER_BREAKDOWN','VIEW_NEW_CUSTOMERS',
    'VIEW_PERMISSIONS','VIEW_RETURNING_CUSTOMERS','VIEW_ROLES','VIEW_SEARCH_SUMMARY',
    'VIEW_TOTAL_AMOUNT','VIEW_TOTAL_CUSTOMERS','VIEW_TOTAL_TRANSACTIONS','VIEW_TRANSACTIONS',
    'VIEW_TRANSACTION_CARDS','VIEW_TRANSACTION_DETAILS','VIEW_USERS'
) AND `deleted_at` IS NULL;

-- Housekeeping permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @housekeeping, `id` FROM `permissions`
WHERE `action_key` IN (
    'ACCESS_ADVANCED_SEARCH','AUTHORIZE_POS_TERMINAL','CREATE_PERMISSIONS',
    'DELETE_PERMISSIONS','EXPORT_ANALYTICS','SEND_STK_PUSH','VIEW_AUDIT_LOGS',
    'VIEW_CUSTOMER_PROFILE','VIEW_DASHBOARD','VIEW_GENDER_BREAKDOWN','VIEW_PERMISSIONS',
    'VIEW_ROLES','VIEW_TOTAL_AMOUNT','VIEW_TRANSACTIONS','VIEW_TRANSACTION_CARDS',
    'VIEW_USER_ACTIVITY'
) AND `deleted_at` IS NULL;

-- Car Wash Attendant permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @car_wash, `id` FROM `permissions`
WHERE `action_key` IN (
    'SEND_STK_PUSH','VIEW_CUSTOMER_PROFILE','VIEW_GENDER_BREAKDOWN',
    'VIEW_NEW_CUSTOMERS','VIEW_RETURNING_CUSTOMERS','VIEW_SEARCH_SUMMARY','VIEW_TRANSACTIONS'
) AND `deleted_at` IS NULL;

-- ── PART 5: Re-assign users to their correct branches ────────
--
--  user_id  | name            | branch              | role
--  ---------+-----------------+---------------------+----------------
--  1        | Mike Njagi      | HQ (node_id=2)      | Overall Manager
--  2        | Mike Kimathi    | kim branch (node=4)  | Branch Manager
--  3        | Mike Kim N      | HQ (node_id=2)      | Branch Manager
--  4        | Grace Wanjiru   | kim branch (node=4)  | Cashier
--  6        | test            | HQ (node_id=2)      | Viewer
--
-- user_id=5 (MIKE NJAGI SUPER, company_id=0) uses platform admin — no tenant branch needed.

INSERT INTO `user_node_roles` (`user_id`, `node_id`, `role_id`, `is_primary`) VALUES
(1, 2, @overall_mgr, 1),
(2, 4, @branch_mgr,  1),
(3, 2, @branch_mgr,  1),
(4, 4, @cashier,     1),
(6, 2, @viewer,      1);

-- ── PART 6: Add branch_id to core transaction tables ─────────
-- Keeps company_id (multi-tenant join key) and adds branch_id (scope key).
-- All backfilled to the company HQ branch — controllers will write the correct
-- branch_id for new records going forward.

-- 6a. transactions
ALTER TABLE `transactions`
  ADD COLUMN `branch_id` int(11) NULL DEFAULT NULL
    COMMENT 'FK → branches.id'
    AFTER `company_id`,
  ADD KEY `idx_branch_id` (`branch_id`);

UPDATE `transactions` t
  JOIN `branches` b ON b.`company_id` = t.`company_id`
                   AND b.`is_hq`      = 1
                   AND b.`deleted_at` IS NULL
SET t.`branch_id` = b.`id`
WHERE t.`branch_id` IS NULL;

-- 6b. mpesa_payments
ALTER TABLE `mpesa_payments`
  ADD COLUMN `branch_id` int(11) NULL DEFAULT NULL
    COMMENT 'FK → branches.id'
    AFTER `company_id`,
  ADD KEY `idx_branch_id` (`branch_id`);

UPDATE `mpesa_payments` m
  JOIN `branches` b ON b.`company_id` = m.`company_id`
                   AND b.`is_hq`      = 1
                   AND b.`deleted_at` IS NULL
SET m.`branch_id` = b.`id`
WHERE m.`branch_id` IS NULL;

-- 6c. stk_push_logs
ALTER TABLE `stk_push_logs`
  ADD COLUMN `branch_id` int(11) NULL DEFAULT NULL
    COMMENT 'FK → branches.id'
    AFTER `company_id`,
  ADD KEY `idx_branch_id` (`branch_id`);

UPDATE `stk_push_logs` s
  JOIN `branches` b ON b.`company_id` = s.`company_id`
                   AND b.`is_hq`      = 1
                   AND b.`deleted_at` IS NULL
SET s.`branch_id` = b.`id`
WHERE s.`branch_id` IS NULL;

-- 6d. customer_profiles
--   Profiles are company-wide aggregates; branch_id = HQ as the canonical anchor.
--   Branch-scoped profile views are done by filtering transactions, not profiles.
ALTER TABLE `customer_profiles`
  ADD COLUMN `branch_id` int(11) NULL DEFAULT NULL
    COMMENT 'FK → branches.id — canonical branch anchor for this profile'
    AFTER `company_id`,
  ADD KEY `idx_branch_id` (`branch_id`);

UPDATE `customer_profiles` cp
  JOIN `branches` b ON b.`company_id` = cp.`company_id`
                   AND b.`is_hq`      = 1
                   AND b.`deleted_at` IS NULL
SET cp.`branch_id` = b.`id`
WHERE cp.`branch_id` IS NULL;

-- ── PART 7: Seed pattern note ────────────────────────────────
-- When a NEW company is created, auto-seed these system roles by running:
--
--   INSERT INTO roles (company_id, name, description, is_system_role) VALUES
--   (:cid, 'Overall Manager',   '...', 1),
--   (:cid, 'Branch Manager',    '...', 1),
--   (:cid, 'Assistant Manager', '...', 1),
--   (:cid, 'Department Manager','...', 1),
--   (:cid, 'Supervisor',        '...', 1),
--   (:cid, 'Support Functions', '...', 1),
--   (:cid, 'Cashier',           '...', 1),
--   (:cid, 'Viewer',            '...', 1);
--
-- Then duplicate the role_permissions from the same names in any existing company.
-- Hook: CompanyRegistrationService::afterCreate() — not yet implemented.
