-- ============================================================
-- Segment 11 — User Areas (many-to-many)
-- Purpose: Replace users.area_id (single) with user_areas
--          junction table to support multiple area assignments.
-- Run AFTER Segment 10
-- ============================================================

-- ── PART 1: Drop area_id FK and column from users ────────────
ALTER TABLE `users`
DROP FOREIGN KEY `fk_users_area`;

ALTER TABLE `users`
DROP INDEX `idx_users_area_id`;

ALTER TABLE `users`
DROP COLUMN `area_id`;

-- ── PART 2: Create user_areas junction table ─────────────────
CREATE TABLE IF NOT EXISTS `user_areas` (
  `id`         INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)   NOT NULL,
  `area_id`    INT(11)   NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_area` (`user_id`, `area_id`),
  KEY `idx_user_areas_user`  (`user_id`),
  KEY `idx_user_areas_area`  (`area_id`),
  CONSTRAINT `fk_user_areas_user` FOREIGN KEY (`user_id`) REFERENCES `users`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_areas_area` FOREIGN KEY (`area_id`) REFERENCES `areas`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
