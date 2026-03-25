-- ============================================================
-- Segment 10 — Departments & Areas
-- Purpose: Extend departments with system flag, add areas table,
--          link area_id to users, seed system defaults
-- Run AFTER Segment 9
-- ============================================================

-- ── PART 1: Add is_system flag to departments ────────────────
ALTER TABLE `departments`
ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = platform default; cannot be deleted by tenant' AFTER `description`;

-- ── PART 2: Seed system departments for company_id=1 ─────────
INSERT INTO `departments` (`company_id`, `name`, `description`, `is_system`, `status`)
VALUES
(1, 'Restaurant',              'Front-of-house restaurant service and dining operations',         1, 'active'),
(1, 'Kitchen',                 'Food preparation, cooking, and kitchen operations',               1, 'active'),
(1, 'Bar / Beverage',          'Bar service, beverages, and drink preparation',                   1, 'active'),
(1, 'Retail / Shop',           'Retail sales, merchandising, and shop operations',                1, 'active'),
(1, 'Housekeeping',            'Cleaning, room preparation, and facility upkeep',                 1, 'active'),
(1, 'Maintenance / Facilities','Building maintenance, repairs, and facility management',          1, 'active'),
(1, 'Finance',                 'Accounting, payroll, budgets, and financial reporting',           1, 'active'),
(1, 'Administration',          'Admin support, coordination, and office management',              1, 'active'),
(1, 'Security',                'Premises security, access control, and safety',                   1, 'active')
ON DUPLICATE KEY UPDATE `is_system` = VALUES(`is_system`);

-- ── PART 3: Create areas table ────────────────────────────────
CREATE TABLE IF NOT EXISTS `areas` (
  `id`          INT(11)       NOT NULL AUTO_INCREMENT,
  `company_id`  INT(11)       NOT NULL,
  `name`        VARCHAR(120)  NOT NULL,
  `description` VARCHAR(255)  DEFAULT NULL,
  `is_system`   TINYINT(1)    NOT NULL DEFAULT 0
    COMMENT '1 = platform default; cannot be deleted by tenant',
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP     NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  TIMESTAMP     NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_company_area_name` (`company_id`, `name`, `deleted_at`),
  KEY `idx_areas_company`  (`company_id`),
  KEY `idx_areas_status`   (`status`),
  CONSTRAINT `fk_areas_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PART 4: Add area_id to users ─────────────────────────────
ALTER TABLE `users`
ADD COLUMN `area_id` INT(11) NULL AFTER `department_id`,
ADD CONSTRAINT `fk_users_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL;

CREATE INDEX `idx_users_area_id` ON `users` (`area_id`);

-- ── PART 5: Seed system areas for company_id=1 ───────────────
INSERT INTO `areas` (`company_id`, `name`, `description`, `is_system`, `status`)
VALUES
(1, 'Reception / Front Desk',  'Main entrance, check-in, and front-desk customer touchpoint',   1, 'active'),
(1, 'Main Dining Area',        'Primary dining space for seated guests',                         1, 'active'),
(1, 'Bar Area',                'Bar counter and drink service zone',                             1, 'active'),
(1, 'Outdoor Seating / Terrace','Terrace, balcony, or outdoor dining and lounge area',           1, 'active'),
(1, 'Kitchen Area',            'Cooking, food prep, and back-of-house kitchen space',            1, 'active'),
(1, 'Storage / Store',         'Stockroom, dry store, and inventory holding area',               1, 'active'),
(1, 'Staff Area / Back Office','Staff room, lockers, and back-office workspace',                 1, 'active'),
(1, 'Rooms / Accommodation',   'Guest bedrooms and accommodation units',                         1, 'active'),
(1, 'Corridors / Blocks',      'Hallways, stairwells, and block/floor common areas',             1, 'active'),
(1, 'Laundry Area',            'Linen washing, drying, and housekeeping preparation zone',       1, 'active'),
(1, 'Swimming Pool',           'Pool basin, surrounding deck, and pool equipment room',          1, 'active'),
(1, 'Poolside / Deck',         'Poolside lounge, sun deck, and outdoor relaxation space',        1, 'active'),
(1, 'Garden / Event Grounds',  'Garden, lawns, and outdoor event hosting space',                 1, 'active'),
(1, 'Stage / Event Area',      'Indoor or outdoor performance and event staging area',           1, 'active'),
(1, 'Washing Bay',             'Vehicle washing area, bays, and related equipment zone',         1, 'active'),
(1, 'Waiting Area',            'Customer waiting lounge or seating before service',              1, 'active'),
(1, 'Shop Area',               'Retail floor, shelving, and point-of-sale zone',                 1, 'active'),
(1, 'Parking Area',            'Customer and staff vehicle parking space',                       1, 'active')
ON DUPLICATE KEY UPDATE `is_system` = VALUES(`is_system`);

-- ── PART 6: Add permissions for departments & areas ───────────
INSERT INTO `permissions` (`id`, `name`, `category`, `description`, `action_key`, `scope`)
VALUES
(50, 'View Departments',   'departments', 'View the list of departments',          'VIEW_DEPARTMENTS',   'any'),
(51, 'Create Departments', 'departments', 'Create new departments',                'CREATE_DEPARTMENTS', 'any'),
(52, 'Edit Departments',   'departments', 'Edit existing department details',      'EDIT_DEPARTMENTS',   'any'),
(53, 'Delete Departments', 'departments', 'Delete / deactivate departments',       'DELETE_DEPARTMENTS', 'any'),
(54, 'View Areas',         'areas',       'View the list of areas',                'VIEW_AREAS',         'any'),
(55, 'Create Areas',       'areas',       'Create new areas',                      'CREATE_AREAS',       'any'),
(56, 'Edit Areas',         'areas',       'Edit existing area details',            'EDIT_AREAS',         'any'),
(57, 'Delete Areas',       'areas',       'Delete / deactivate areas',             'DELETE_AREAS',       'any')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
