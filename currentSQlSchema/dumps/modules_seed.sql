-- =============================================================================
-- Angavu CRM — Modules / Sub-Modules / Features + User Activity Logs
-- Run this file once against your database.
-- Order matters: modules → module_submodules → module_features → user_activity_logs
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: modules
-- Top-level product areas visible in the platform feature registry.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `modules` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100)    NOT NULL,
  `slug`        VARCHAR(100)    NOT NULL,
  `icon`        VARCHAR(100)    NULL     COMMENT 'Lucide icon key e.g. lucide:users',
  `description` VARCHAR(255)    NULL,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modules_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: module_submodules
-- Logical groupings within a module.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `module_submodules` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `module_id`   INT UNSIGNED    NOT NULL,
  `name`        VARCHAR(100)    NOT NULL,
  `slug`        VARCHAR(100)    NOT NULL,
  `description` VARCHAR(255)    NULL,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submodule_slug` (`module_id`, `slug`),
  CONSTRAINT `fk_submodules_module`
    FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: module_features
-- Granular capabilities within a sub-module.
-- These are the units you toggle per-tenant via the features system.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `module_features` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `submodule_id`  INT UNSIGNED    NOT NULL,
  `name`          VARCHAR(150)    NOT NULL,
  `slug`          VARCHAR(150)    NOT NULL,
  `description`   VARCHAR(255)    NULL,
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_feature_slug` (`submodule_id`, `slug`),
  CONSTRAINT `fk_features_submodule`
    FOREIGN KEY (`submodule_id`) REFERENCES `module_submodules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- TABLE: user_activity_logs
-- Tenant-scoped record of every action a user performs.
-- FK columns are nullable — SET NULL on module/submodule/feature deletion
-- so log history is never lost even if a module is restructured.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `user_activity_logs` (
  `id`           BIGINT UNSIGNED             NOT NULL AUTO_INCREMENT,
  `company_id`   INT                         NOT NULL,
  `user_id`      INT                         NOT NULL,
  `actor_type`   ENUM('tenant','superadmin') NOT NULL DEFAULT 'tenant',
  `module_id`    INT UNSIGNED                NULL     COMMENT 'FK → modules.id',
  `submodule_id` INT UNSIGNED                NULL     COMMENT 'FK → module_submodules.id',
  `feature_id`   INT UNSIGNED                NULL     COMMENT 'FK → module_features.id',
  `action`       VARCHAR(50)                 NOT NULL COMMENT 'Verb: VIEW, CREATE, UPDATE, DELETE, SEND, EXPORT, etc.',
  `permission`   VARCHAR(120)                NULL     COMMENT 'Permission that covered this action',
  `description`  TEXT                        NOT NULL COMMENT 'Human-readable narrative of the action',
  `subject_type` VARCHAR(100)                NULL     COMMENT 'Entity acted on: transaction, user, role, terminal',
  `subject_id`   INT                         NULL     COMMENT 'ID of the subject entity',
  `metadata`     JSON                        NULL     COMMENT 'Structured context: amounts, phone numbers, before/after values',
  `ip_address`   VARCHAR(45)                 NULL,
  `created_at`   TIMESTAMP                   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company_created`  (`company_id`, `created_at`),
  KEY `idx_user_created`     (`user_id`, `created_at`),
  KEY `idx_module_action`    (`module_id`, `action`),
  KEY `idx_submodule`        (`submodule_id`),
  KEY `idx_feature_id`       (`feature_id`),
  KEY `idx_subject`          (`subject_type`, `subject_id`),
  CONSTRAINT `fk_activity_module`
    FOREIGN KEY (`module_id`)    REFERENCES `modules` (`id`)            ON DELETE SET NULL,
  CONSTRAINT `fk_activity_submodule`
    FOREIGN KEY (`submodule_id`) REFERENCES `module_submodules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_activity_feature`
    FOREIGN KEY (`feature_id`)   REFERENCES `module_features` (`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- SEED DATA
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- MODULES  (14)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `modules` (`id`, `name`, `slug`, `icon`, `description`, `sort_order`) VALUES
(1,  'Customer CRM',       'customer_crm',       'lucide:users',          'Customer profiles, activity, and segmentation',          1),
(2,  'Transactions',       'transactions',        'lucide:receipt',        'Transaction records, orders, and payment tracking',       2),
(3,  'Business Management','business_management', 'lucide:building-2',     'Branches, staff users, and access control',               3),
(4,  'Analytics',          'analytics',           'lucide:bar-chart-2',    'Revenue, customer, and sales performance insights',       4),
(5,  'Menu',               'menu',                'lucide:utensils',       'Products, categories, availability, and public menus',    5),
(6,  'Online Orders',      'online_orders',       'lucide:shopping-cart',  'Cart, order placement, and order management',             6),
(7,  'Payments',           'payments',            'lucide:credit-card',    'Payment integrations and processing workflows',           7),
(8,  'Marketing',          'marketing',           'lucide:megaphone',      'Campaigns, targeting, and promotions',                    8),
(9,  'Loyalty',            'loyalty',             'lucide:star',           'Points system, rewards, and customer loyalty',            9),
(10, 'Communications',     'communications',      'lucide:mail',           'Messaging and automated notifications',                   10),
(11, 'Inventory',          'inventory',           'lucide:package',        'Stock levels and low-stock alerts',                       11),
(12, 'Integrations',       'integrations',        'lucide:link',           'API access, webhooks, and external services',             12),
(13, 'Settings',           'settings',            'lucide:settings',       'General business settings and system preferences',        13),
(14, 'Security',           'security',            'lucide:shield',         'Authentication, session control, and audit logs',         14);


-- ─────────────────────────────────────────────────────────────────────────────
-- SUBMODULES  (38)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `module_submodules` (`id`, `module_id`, `name`, `slug`, `sort_order`) VALUES

-- Customer CRM (module 1)
(1,  1, 'Profiles',            'profiles',           1),
(2,  1, 'Activity',            'activity',           2),
(3,  1, 'Segmentation',        'segmentation',       3),

-- Transactions (module 2)
(4,  2, 'Records',             'records',            1),
(5,  2, 'Orders',              'orders',             2),
(6,  2, 'Payments',            'payments',           3),

-- Business Management (module 3)
(7,  3, 'Branches',            'branches',           1),
(8,  3, 'Users',               'users',              2),
(9,  3, 'Permissions',         'permissions',        3),

-- Analytics (module 4)
(10, 4, 'Revenue',             'revenue',            1),
(11, 4, 'Customers',           'customers',          2),
(12, 4, 'Sales Behavior',      'sales_behavior',     3),
(13, 4, 'Menu Performance',    'menu_performance',   4),

-- Menu (module 5)
(14, 5, 'Products',            'products',           1),
(15, 5, 'Categories',          'categories',         2),
(16, 5, 'Availability',        'availability',       3),
(17, 5, 'Menu Access',         'menu_access',        4),

-- Online Orders (module 6)
(18, 6, 'Ordering',            'ordering',           1),
(19, 6, 'Order Management',    'order_management',   2),

-- Payments (module 7)
(20, 7, 'Integrations',        'integrations',       1),
(21, 7, 'Processing',          'processing',         2),

-- Marketing (module 8)
(22, 8, 'Campaigns',           'campaigns',          1),
(23, 8, 'Targeting',           'targeting',          2),
(24, 8, 'Promotions',          'promotions',         3),

-- Loyalty (module 9)
(25, 9, 'Points System',       'points_system',      1),
(26, 9, 'Rewards',             'rewards',            2),
(27, 9, 'Customer Loyalty',    'customer_loyalty',   3),

-- Communications (module 10)
(28, 10, 'Messaging',          'messaging',          1),
(29, 10, 'Automation',         'automation',         2),

-- Inventory (module 11)
(30, 11, 'Stock',              'stock',              1),
(31, 11, 'Alerts',             'alerts',             2),

-- Integrations (module 12)
(32, 12, 'API',                'api',                1),
(33, 12, 'Webhooks',           'webhooks',           2),
(34, 12, 'External Services',  'external_services',  3),

-- Settings (module 13)
(35, 13, 'General',            'general',            1),
(36, 13, 'System',             'system',             2),

-- Security (module 14)
(37, 14, 'Access',             'access',             1),
(38, 14, 'Logs',               'logs',               2);


-- ─────────────────────────────────────────────────────────────────────────────
-- FEATURES  (99)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `module_features` (`id`, `submodule_id`, `name`, `slug`, `sort_order`) VALUES

-- ── Submodule 1: Profiles ────────────────────────────────────────────────────
(1,  1, 'Customer Profiles',             'customer_profiles',          1),
(2,  1, 'Contact Details',               'contact_details',            2),
(3,  1, 'Customer Notes',                'customer_notes',             3),
(4,  1, 'Tags / Labels',                 'tags_labels',                4),
(5,  1, 'Merge Duplicates',              'merge_duplicates',           5),

-- ── Submodule 2: Activity ────────────────────────────────────────────────────
(6,  2, 'Transaction History',           'transaction_history',        1),
(7,  2, 'Order History',                 'order_history',              2),
(8,  2, 'Visit Frequency',               'visit_frequency',            3),
(9,  2, 'Last Activity',                 'last_activity',              4),

-- ── Submodule 3: Segmentation ────────────────────────────────────────────────
(10, 3, 'Customer Groups',               'customer_groups',            1),
(11, 3, 'Filters (Spend, Visits, Recency)', 'customer_filters',        2),
(12, 3, 'Dynamic Segments',              'dynamic_segments',           3),

-- ── Submodule 4: Records ─────────────────────────────────────────────────────
(13, 4, 'Transaction Logging',           'transaction_logging',        1),
(14, 4, 'Payment Methods (MPesa, Cash, Bank)', 'payment_methods',      2),
(15, 4, 'Payment References',            'payment_references',         3),

-- ── Submodule 5: Orders ──────────────────────────────────────────────────────
(16, 5, 'Order Records',                 'order_records',              1),
(17, 5, 'Items Attached',                'items_attached',             2),
(18, 5, 'Order Status',                  'order_status',               3),

-- ── Submodule 6: Payments (Transactions) ─────────────────────────────────────
(19, 6, 'Payment Status',                'payment_status',             1),
(20, 6, 'Partial Payments',              'partial_payments',           2),
(21, 6, 'Refunds',                       'refunds',                    3),

-- ── Submodule 7: Branches ────────────────────────────────────────────────────
(22, 7, 'Branch Management',             'branch_management',          1),
(23, 7, 'Branch Assignment',             'branch_assignment',          2),

-- ── Submodule 8: Users ───────────────────────────────────────────────────────
(24, 8, 'User Accounts',                 'user_accounts',              1),
(25, 8, 'Staff Roles',                   'staff_roles',                2),

-- ── Submodule 9: Permissions ─────────────────────────────────────────────────
(26, 9, 'Role-Based Access',             'role_based_access',          1),
(27, 9, 'Module-Level Permissions',      'module_level_permissions',   2),

-- ── Submodule 10: Revenue ────────────────────────────────────────────────────
(28, 10, 'Revenue Reports',              'revenue_reports',            1),
(29, 10, 'Revenue Trends',               'revenue_trends',             2),
(30, 10, 'Revenue Per Branch',           'revenue_per_branch',         3),

-- ── Submodule 11: Customers (Analytics) ─────────────────────────────────────
(31, 11, 'Customer Lifetime Value',      'customer_lifetime_value',    1),
(32, 11, 'Top Customers',                'top_customers',              2),
(33, 11, 'Retention / Churn',            'retention_churn',            3),
(34, 11, 'Visit Frequency Analytics',    'visit_frequency_analytics',  4),

-- ── Submodule 12: Sales Behavior ─────────────────────────────────────────────
(35, 12, 'Average Order Value',          'average_order_value',        1),
(36, 12, 'Customer Spend Patterns',      'customer_spend_patterns',    2),
(37, 12, 'Payment Method Breakdown',     'payment_method_breakdown',   3),

-- ── Submodule 13: Menu Performance ───────────────────────────────────────────
(38, 13, 'Best-Selling Items',           'best_selling_items',         1),
(39, 13, 'Category Performance',         'category_performance',       2),

-- ── Submodule 14: Products ───────────────────────────────────────────────────
(40, 14, 'Product / Item Management',    'product_management',         1),
(41, 14, 'Pricing',                      'pricing',                    2),
(42, 14, 'Descriptions',                 'descriptions',               3),
(43, 14, 'Images',                       'images',                     4),

-- ── Submodule 15: Categories ─────────────────────────────────────────────────
(44, 15, 'Category Management',          'category_management',        1),
(45, 15, 'Sorting / Ordering',           'sorting_ordering',           2),

-- ── Submodule 16: Availability ───────────────────────────────────────────────
(46, 16, 'Stock Status (Available / Out of Stock)', 'stock_status',    1),
(47, 16, 'Time-Based Availability',      'time_based_availability',    2),

-- ── Submodule 17: Menu Access ────────────────────────────────────────────────
(48, 17, 'Public Menu Link',             'public_menu_link',           1),
(49, 17, 'QR Code',                      'qr_code',                    2),
(50, 17, 'Multi-Branch Menus',           'multi_branch_menus',         3),

-- ── Submodule 18: Ordering ───────────────────────────────────────────────────
(51, 18, 'Cart',                         'cart',                       1),
(52, 18, 'Place Order',                  'place_order',                2),
(53, 18, 'Customer Details Capture',     'customer_details_capture',   3),

-- ── Submodule 19: Order Management ──────────────────────────────────────────
(54, 19, 'Order Status Tracking',        'order_status_tracking',      1),
(55, 19, 'Order History',                'order_history',              2),
(56, 19, 'Branch Selection',             'branch_selection',           3),

-- ── Submodule 20: Payment Integrations ──────────────────────────────────────
(57, 20, 'MPesa',                        'mpesa',                      1),
(58, 20, 'Bank Payments',                'bank_payments',              2),
(59, 20, 'Custom Payment Methods',       'custom_payment_methods',     3),

-- ── Submodule 21: Processing ─────────────────────────────────────────────────
(60, 21, 'Payment Confirmation',         'payment_confirmation',       1),
(61, 21, 'Payment Status Tracking',      'payment_status_tracking',    2),
(62, 21, 'Payment Linking to Customers', 'payment_linking',            3),

-- ── Submodule 22: Campaigns ──────────────────────────────────────────────────
(63, 22, 'SMS Campaigns',                'sms_campaigns',              1),
(64, 22, 'Email Campaigns',              'email_campaigns',            2),

-- ── Submodule 23: Targeting ──────────────────────────────────────────────────
(65, 23, 'Customer Segmentation Targeting', 'segmentation_targeting',  1),
(66, 23, 'Filters (Spend, Visits, Inactivity)', 'targeting_filters',   2),

-- ── Submodule 24: Promotions ─────────────────────────────────────────────────
(67, 24, 'Discounts',                    'discounts',                  1),
(68, 24, 'Coupons',                      'coupons',                    2),
(69, 24, 'Offers',                       'offers',                     3),

-- ── Submodule 25: Points System ──────────────────────────────────────────────
(70, 25, 'Earn Points',                  'earn_points',                1),
(71, 25, 'Redeem Points',                'redeem_points',              2),

-- ── Submodule 26: Rewards ────────────────────────────────────────────────────
(72, 26, 'Reward Setup',                 'reward_setup',               1),
(73, 26, 'Redemption Tracking',          'redemption_tracking',        2),

-- ── Submodule 27: Customer Loyalty ───────────────────────────────────────────
(74, 27, 'Loyalty Balance',              'loyalty_balance',            1),
(75, 27, 'Loyalty History',              'loyalty_history',            2),

-- ── Submodule 28: Messaging ──────────────────────────────────────────────────
(76, 28, 'SMS Notifications',            'sms_notifications',          1),
(77, 28, 'Email Notifications',          'email_notifications',        2),

-- ── Submodule 29: Automation ─────────────────────────────────────────────────
(78, 29, 'Payment Confirmations',        'payment_confirmations_auto', 1),
(79, 29, 'Order Alerts',                 'order_alerts',               2),
(80, 29, 'Campaign Sends',               'campaign_sends',             3),

-- ── Submodule 30: Stock ──────────────────────────────────────────────────────
(81, 30, 'Stock Levels',                 'stock_levels',               1),
(82, 30, 'Stock Updates',                'stock_updates',              2),

-- ── Submodule 31: Inventory Alerts ───────────────────────────────────────────
(83, 31, 'Low Stock Alerts',             'low_stock_alerts',           1),
(84, 31, 'Out-of-Stock Flags',           'out_of_stock_flags',         2),

-- ── Submodule 32: API ────────────────────────────────────────────────────────
(85, 32, 'API Access',                   'api_access',                 1),
(86, 32, 'API Keys',                     'api_keys',                   2),

-- ── Submodule 33: Webhooks ───────────────────────────────────────────────────
(87, 33, 'Event Triggers',               'event_triggers',             1),
(88, 33, 'External Sync',                'external_sync',              2),

-- ── Submodule 34: External Services ─────────────────────────────────────────
(89, 34, 'Payment Providers',            'payment_providers',          1),
(90, 34, 'SMS Gateways',                 'sms_gateways',               2),

-- ── Submodule 35: General Settings ──────────────────────────────────────────
(91, 35, 'Business Settings',            'business_settings',          1),
(92, 35, 'Currency',                     'currency',                   2),
(93, 35, 'Tax',                          'tax',                        3),

-- ── Submodule 36: System Settings ───────────────────────────────────────────
(94, 36, 'Module Toggles',               'module_toggles',             1),
(95, 36, 'Preferences',                  'preferences',                2),

-- ── Submodule 37: Access (Security) ─────────────────────────────────────────
(96, 37, 'Authentication',               'authentication',             1),
(97, 37, 'Session Control',              'session_control',            2),

-- ── Submodule 38: Logs (Security) ───────────────────────────────────────────
(98, 38, 'Activity Logs',                'activity_logs',              1),
(99, 38, 'Audit Logs',                   'audit_logs',                 2);
