-- ============================================================
-- Segment 7 — Create Role Hierarchy Table
-- Purpose: Explicit parent-child role relationships for hierarchy management
-- Run AFTER Segment 6
-- ============================================================

-- ── PART 1: Create role_hierarchy table ───────────────────────
CREATE TABLE IF NOT EXISTS `role_hierarchy` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT(11) NOT NULL,
  `role_id` INT(11) NOT NULL,
  `parent_role_id` INT(11) NULL,
  `level` INT(3) DEFAULT 0,
  `scope` ENUM('any', 'hq', 'region', 'branch') DEFAULT 'any',

  UNIQUE KEY `uk_company_role_hierarchy` (`company_id`, `role_id`),
  KEY `idx_hierarchy_parent` (`parent_role_id`),
  KEY `idx_hierarchy_level` (`level`),
  KEY `idx_hierarchy_scope` (`scope`),

  CONSTRAINT `fk_hierarchy_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hierarchy_parent` FOREIGN KEY (`parent_role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hierarchy_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PART 2: Seed role_hierarchy with system roles (company_id=1) ──
-- Using current system role IDs: 1=Overall Manager, 2=Branch Manager, etc.
-- Getting actual IDs from current roles table

SET @overall_mgr = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Overall Manager' LIMIT 1);
SET @branch_mgr = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Branch Manager' LIMIT 1);
SET @cashier = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Cashier' LIMIT 1);
SET @viewer = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Viewer' LIMIT 1);
SET @service_staff = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Service Staff' LIMIT 1);
SET @retail_staff = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Retail Staff' LIMIT 1);
SET @housekeeping = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Housekeeping' LIMIT 1);
SET @car_wash_attendant = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Car Wash Attendant' LIMIT 1);
SET @regional_mgr = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Regional Manager' LIMIT 1);
SET @assistant_mgr = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Assistant Manager' LIMIT 1);
SET @department_mgr = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Department Manager' LIMIT 1);
SET @supervisor = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Supervisor' LIMIT 1);
SET @support_functions = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Support Functions' LIMIT 1);
SET @director = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Director' LIMIT 1);
SET @owner = (SELECT id FROM `roles` WHERE company_id = 1 AND name = 'Owner' LIMIT 1);

-- Insert hierarchy
-- level 4 = owner
-- level 3 = executive / company leadership
-- level 2 = management
-- level 1 = supervisor / team lead
-- level 0 = operational
INSERT IGNORE INTO `role_hierarchy` (`company_id`, `role_id`, `parent_role_id`, `level`, `scope`)
VALUES
  -- Owner: Top level, no parent
  (1, @owner, NULL, 4, 'hq'),

  -- Director: Reports to Owner
  (1, @director, @owner, 3, 'hq'),

  -- Overall Manager: Reports to Director
  (1, @overall_mgr, @director, 3, 'hq'),

  -- Regional Manager: Reports to Overall Manager
  (1, @regional_mgr, @overall_mgr, 2, 'region'),

  -- Branch Manager: Reports to Regional Manager
  (1, @branch_mgr, @regional_mgr, 2, 'branch'),

  -- Assistant Manager: supports the Branch Manager at branch management level
  (1, @assistant_mgr, @branch_mgr, 2, 'branch'),

  -- Department Manager: runs a functional area within a branch at management level
  (1, @department_mgr, @assistant_mgr, 2, 'branch'),

  -- Support Functions: shared branch support role reporting to Assistant Manager
  (1, @support_functions, @assistant_mgr, 1, 'branch'),

  -- Supervisor: leads frontline execution within a department
  (1, @supervisor, @department_mgr, 1, 'branch'),

  -- Branch operations roll up through Department Manager
  (1, @cashier, @department_mgr, 0, 'branch'),
  (1, @viewer, @department_mgr, 0, 'branch'),
  (1, @service_staff, @department_mgr, 0, 'branch'),
  (1, @retail_staff, @department_mgr, 0, 'branch'),
  (1, @housekeeping, @department_mgr, 0, 'branch'),
  (1, @car_wash_attendant, @department_mgr, 0, 'branch');

-- ── PART 3: Verify hierarchy structure ────────────────────────
SELECT
  r.name AS role_name,
  rh.level,
  rh.scope,
  COALESCE(pr.name, 'TOP') AS parent_role
FROM `role_hierarchy` rh
LEFT JOIN `roles` r ON r.id = rh.role_id
LEFT JOIN `roles` pr ON pr.id = rh.parent_role_id
WHERE rh.company_id = 1
ORDER BY rh.level DESC, r.name;
