
-- ============================================================
-- Seed: Platform Admin accounts (one per role, for testing)
-- Generated: 2026-03-22
-- Password for all: Mike@132 (bcrypt)
-- Excludes: PlatformOwner (id=1) and PlatformAdmin role users
-- ============================================================

INSERT IGNORE INTO `platform_admins`
    (`id`, `name`, `email`, `password`, `status`, `is_platform_owner`, `is_system_account`, `created_at`)
VALUES
(5, 'Director Dan', 'director@angavu.com', '$2y$10$LdSzGVmKIaZcuincdXUI8Om2HBaHTASJJC5H/9UiF41XTVsJYswWC', 'active', 0, 0, NOW()),
(6, 'Support Agent Sara', 'support@angavu.com', '$2y$10$LdSzGVmKIaZcuincdXUI8Om2HBaHTASJJC5H/9UiF41XTVsJYswWC', 'active', 0, 0, NOW()),
(7, 'Finance Admin Felix', 'finance@angavu.com', '$2y$10$LdSzGVmKIaZcuincdXUI8Om2HBaHTASJJC5H/9UiF41XTVsJYswWC', 'active', 0, 0, NOW()),
(8, 'Ops Admin Omar', 'ops@angavu.com', '$2y$10$LdSzGVmKIaZcuincdXUI8Om2HBaHTASJJC5H/9UiF41XTVsJYswWC', 'active', 0, 0, NOW()),
(9, 'Security Admin Sam', 'security@angavu.com', '$2y$10$LdSzGVmKIaZcuincdXUI8Om2HBaHTASJJC5H/9UiF41XTVsJYswWC', 'active', 0, 0, NOW()),
(10, 'Tenant Access Tara', 'tenantaccess@angavu.com', '$2y$10$LdSzGVmKIaZcuincdXUI8Om2HBaHTASJJC5H/9UiF41XTVsJYswWC', 'active', 0, 0, NOW());

INSERT IGNORE INTO `platform_admin_roles` (`platform_admin_id`, `platform_role_id`, `created_at`) VALUES
(5, 9, NOW()),
(6, 3, NOW()),
(7, 4, NOW()),
(8, 5, NOW()),
(9, 6, NOW()),
(10, 8, NOW());
