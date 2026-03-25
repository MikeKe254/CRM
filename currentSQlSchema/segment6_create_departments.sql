-- ============================================================
-- Segment 6 — Create Departments Table
-- Purpose: Organizational structure for staff analysis & payroll grouping
-- Run AFTER Segment 5
-- ============================================================

-- ── PART 1: Create departments table ────────────────────────────
CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL,

  UNIQUE KEY `uk_company_department_name` (`company_id`, `name`, `deleted_at`),
  KEY `idx_departments_company` (`company_id`),
  KEY `idx_departments_status` (`status`),
  CONSTRAINT `fk_departments_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
ADD COLUMN `department_id` INT(11) NULL,
ADD CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

-- ── PART 3: Create seed departments for company_id=1 ───────────
-- These are common departments applicable to most organizations
INSERT INTO `departments` (`company_id`, `name`, `description`, `status`)
SELECT 1, 'Unassigned', 'Default dept for users pending assignment', 'active'
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE company_id = 1 AND name = 'Unassigned');

INSERT INTO `departments` (`company_id`, `name`, `description`, `status`)
VALUES
(1, 'Operations', 'Daily business operations and service delivery', 'active'),
(1, 'Sales', 'Customer acquisition and sales team', 'active'),
(1, 'Finance', 'Accounting, payroll, financial management', 'active'),
(1, 'Management', 'Executive and middle management', 'active'),
(1, 'Support', 'Customer support and service excellence', 'active')
ON DUPLICATE KEY UPDATE `deleted_at` = NULL;

-- ── PART 4: Assign all users to Unassigned dept (safe default) ──
UPDATE `users` u
SET u.`department_id` = (
  SELECT d.id FROM `departments` d
  WHERE d.company_id = u.company_id AND d.name = 'Unassigned'
  LIMIT 1
)
WHERE u.`deleted_at` IS NULL AND u.`department_id` IS NULL;

-- ── PART 5: Create index for department queries ──────────────────
CREATE INDEX `idx_users_department_id` ON `users` (`department_id`);

-- Verify migration
SELECT d.name, COUNT(u.id) AS user_count
FROM `departments` d
LEFT JOIN `users` u ON u.department_id = d.id AND u.deleted_at IS NULL
WHERE d.company_id = 1
GROUP BY d.id, d.name
ORDER BY user_count DESC;
