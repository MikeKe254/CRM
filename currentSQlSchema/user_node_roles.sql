-- ============================================================
-- Table: user_node_roles
-- Replaces user_roles with hierarchy-aware node assignments.
-- Segment 1 — Branch Hierarchy Foundation
-- ============================================================

CREATE TABLE `user_node_roles` (
  `id`          int(11)    NOT NULL AUTO_INCREMENT,
  `user_id`     int(11)    NOT NULL,
  `node_id`     int(11)    NOT NULL  COMMENT 'FK → branches.id (any level in the tree)',
  `role_id`     int(11)    NOT NULL  COMMENT 'FK → roles.id',
  `is_primary`  tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = default landing branch after login',
  `created_at`  timestamp  NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_node_role` (`user_id`, `node_id`, `role_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_node` (`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- Migration: Migrate existing user_roles → user_node_roles
-- Maps every existing flat role assignment to the HQ branch.
-- Run ONCE after both branches and user_node_roles are created.
-- ============================================================

INSERT IGNORE INTO `user_node_roles` (`user_id`, `node_id`, `role_id`, `is_primary`)
SELECT
    ur.`user_id`,
    b.`id`   AS node_id,
    ur.`role_id`,
    1        AS is_primary       -- HQ is the primary node for migrated users
FROM `user_roles` ur
JOIN `users` u       ON u.`id` = ur.`user_id`
JOIN `branches` b    ON b.`company_id` = u.`company_id`
                     AND b.`is_hq` = 1
                     AND b.`deleted_at` IS NULL
WHERE u.`deleted_at` IS NULL;
