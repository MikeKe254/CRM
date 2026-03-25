-- ============================================================
-- Table: branches
-- Segment 1 — Branch Hierarchy Foundation
-- ============================================================

CREATE TABLE `branches` (
  `id`          int(11)        NOT NULL AUTO_INCREMENT,
  `company_id`  int(11)        NOT NULL,
  `parent_id`   int(11)        DEFAULT NULL COMMENT 'NULL = root node (HQ)',
  `name`        varchar(120)   NOT NULL                   COMMENT 'Mutable display name',
  `slug`        varchar(80)    NOT NULL                   COMMENT 'Immutable URL identifier, unique per company',
  `type`        varchar(50)    NOT NULL DEFAULT 'branch'  COMMENT 'hq | region | area | branch | office',
  `path`        varchar(500)   NOT NULL DEFAULT '/'       COMMENT 'Materialised path e.g. /1/5/12/',
  `depth`       tinyint        NOT NULL DEFAULT 0         COMMENT '0=root/HQ, 1=region, 2=area, 3=branch',
  `is_hq`       tinyint(1)     NOT NULL DEFAULT 0,
  `status`      varchar(50)    NOT NULL DEFAULT 'active'  COMMENT 'active | inactive',
  `deleted_at`  timestamp      NULL DEFAULT NULL,
  `created_at`  timestamp      NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_slug` (`company_id`, `slug`),
  KEY `idx_company`  (`company_id`),
  KEY `idx_parent`   (`parent_id`),
  KEY `idx_path`     (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- Migration: Create HQ branch for every existing company
-- Run ONCE after creating the table.
-- ============================================================

INSERT INTO `branches` (`company_id`, `name`, `slug`, `type`, `path`, `depth`, `is_hq`, `status`)
SELECT
    c.`id`                          AS company_id,
    'Head Office'                   AS name,
    'hq'                            AS slug,
    'hq'                            AS type,
    CONCAT('/', c.`id`, '/')        AS path,    -- placeholder; will be updated after INSERT
    0                               AS depth,
    1                               AS is_hq,
    'active'                        AS status
FROM `companies` c
WHERE c.`deleted_at` IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM `branches` b WHERE b.`company_id` = c.`id` AND b.`is_hq` = 1
  );

-- Fix path to use the actual branch ID (not company ID).
-- Safe to run multiple times — only updates rows whose path is wrong.
UPDATE `branches` b
SET b.`path` = CONCAT('/', b.`id`, '/')
WHERE b.`is_hq` = 1
  AND b.`path` != CONCAT('/', b.`id`, '/');

-- ============================================================
-- REPAIR: run this if you see redirect loops after login.
-- Fixes any branch whose path still contains the company_id
-- instead of its own branch id (symptom of the migration INSERT
-- placeholder not being corrected by the UPDATE above).
-- ============================================================
UPDATE `branches`
SET `path` = CONCAT('/', `id`, '/')
WHERE `depth` = 0
  AND `path` != CONCAT('/', `id`, '/');

-- ============================================================
-- REPAIR: fix sub-branch paths (depth > 0) where the path
-- does not match parent.path + id + /.
-- Run after the depth=0 repair above.
-- Safe to run multiple times.
-- ============================================================
UPDATE `branches` b
JOIN `branches` p ON p.`id` = b.`parent_id`
SET b.`path` = CONCAT(p.`path`, b.`id`, '/')
WHERE b.`depth` > 0
  AND b.`path` != CONCAT(p.`path`, b.`id`, '/');
