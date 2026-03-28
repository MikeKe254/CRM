-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 16 — Add branch_id to departments & areas
--
-- Departments and areas are branch-scoped resources.
-- Every row is now attached to the specific branch it belongs to.
-- Existing rows are migrated to the company's head-office-branch automatically.
-- ─────────────────────────────────────────────────────────────────────────────

-- ── STEP 1: Add branch_id (nullable first so we can populate) ────────────────

ALTER TABLE `departments`
    ADD COLUMN `branch_id` INT(11) NULL DEFAULT NULL AFTER `company_id`,
    ADD KEY `idx_departments_branch` (`branch_id`);

ALTER TABLE `areas`
    ADD COLUMN `branch_id` INT(11) NULL DEFAULT NULL AFTER `company_id`,
    ADD KEY `idx_areas_branch` (`branch_id`);

-- ── STEP 2: Populate existing rows → head-office-branch ──────────────────────

UPDATE `departments` d
    JOIN `branches` b
        ON b.company_id = d.company_id
        AND b.slug      = 'head-office-branch'
        AND b.deleted_at IS NULL
SET d.branch_id = b.id;

UPDATE `areas` a
    JOIN `branches` b
        ON b.company_id = a.company_id
        AND b.slug      = 'head-office-branch'
        AND b.deleted_at IS NULL
SET a.branch_id = b.id;

-- ── STEP 3: Make branch_id NOT NULL + add FK constraints ────────────────────

ALTER TABLE `departments`
    MODIFY COLUMN `branch_id` INT(11) NOT NULL,
    ADD CONSTRAINT `fk_departments_branch`
        FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE;

ALTER TABLE `areas`
    MODIFY COLUMN `branch_id` INT(11) NOT NULL,
    ADD CONSTRAINT `fk_areas_branch`
        FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE;

-- ── STEP 4: Update unique keys to be branch-scoped ──────────────────────────
-- Names must be unique per branch (not just per company).

ALTER TABLE `departments`
    DROP INDEX `uk_company_department_name`,
    ADD UNIQUE KEY `uk_branch_department_name` (`branch_id`, `name`, `deleted_at`);

ALTER TABLE `areas`
    DROP INDEX `uk_company_area_name`,
    ADD UNIQUE KEY `uk_branch_area_name` (`branch_id`, `name`, `deleted_at`);
