-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 33 — Revenue Classification Layer
--
-- Adds three new revenue-context dimensions that attach to pos_transactions:
--
--   catalog_items  — what was sold (service or product), branch-scoped
--   events         — context under which the sale happened, branch-scoped
--   covers         — headcount for the transaction (unlocks per-cover metrics)
--
-- Areas were already captured via pos_transactions.area_id (segment 10/16).
--
-- Design rules:
--   • catalog_items and events are independent dimensions — an event is NOT a
--     type of revenue source; it is the context around any sale
--   • event_id is a standalone column on pos_transactions, not folded into
--     revenue_source_type — this allows "revenue by catalog item during event X"
--   • all new tables are branch-scoped (company_id + branch_id), soft-deleted
--   • permissions follow the same pattern as areas (view / create / edit / delete)
-- ─────────────────────────────────────────────────────────────────────────────

-- ── PART 1: catalog_items ────────────────────────────────────────────────────
--
-- Lightweight revenue classifiers: services and products.
-- NOT a full inventory system — no stock tracking, no SKUs, no purchase orders.
-- Purpose: tag transactions so revenue can be sliced by "what was sold."

CREATE TABLE IF NOT EXISTS `catalog_items` (
  `id`          INT(11)          NOT NULL AUTO_INCREMENT,
  `company_id`  INT(11)          NOT NULL,
  `branch_id`   INT(11)          NOT NULL,
  `name`        VARCHAR(120)     NOT NULL,
  `category`    VARCHAR(80)      DEFAULT NULL COMMENT 'Free-text grouping label — e.g. Food, Drinks, Treatments',
  `type`        ENUM('service','product') NOT NULL DEFAULT 'service',
  `price`       DECIMAL(12,2)    DEFAULT NULL COMMENT 'Suggested / default price — nullable, cashier can override',
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `deleted_at`  DATETIME         DEFAULT NULL,
  `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP        NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_ci_company`  (`company_id`),
  KEY `idx_ci_branch`   (`branch_id`),
  KEY `idx_ci_status`   (`status`),
  KEY `idx_ci_type`     (`type`),
  UNIQUE KEY `uq_ci_branch_name` (`branch_id`, `name`, `deleted_at`),

  CONSTRAINT `fk_ci_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ci_branch`  FOREIGN KEY (`branch_id`)  REFERENCES `branches`  (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Branch-scoped revenue classifiers. Lightweight — name, type, optional price. Not a stock system.';


-- ── PART 2: events ───────────────────────────────────────────────────────────
--
-- Revenue context: a named occasion during which transactions occurred.
-- Examples: Valentine's Dinner, Monthly Ladies Night, Weekend Brunch
--
-- An event is NOT a type of thing sold — it is the context around any sale.
-- event_id on pos_transactions is a separate column, allowing queries like:
--   "Which catalog items sold best during the Valentine's event?"
--
-- Tickets are deferred until events are stable.

CREATE TABLE IF NOT EXISTS `events` (
  `id`          INT(11)          NOT NULL AUTO_INCREMENT,
  `company_id`  INT(11)          NOT NULL,
  `branch_id`   INT(11)          NOT NULL,
  `name`        VARCHAR(120)     NOT NULL,
  `description` TEXT             DEFAULT NULL,
  `starts_at`   DATETIME         DEFAULT NULL,
  `ends_at`     DATETIME         DEFAULT NULL,
  `status`      ENUM('draft','active','ended','cancelled') NOT NULL DEFAULT 'draft',
  `deleted_at`  DATETIME         DEFAULT NULL,
  `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP        NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_ev_company`  (`company_id`),
  KEY `idx_ev_branch`   (`branch_id`),
  KEY `idx_ev_status`   (`status`),
  KEY `idx_ev_starts`   (`starts_at`),

  CONSTRAINT `fk_ev_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ev_branch`  FOREIGN KEY (`branch_id`)  REFERENCES `branches`  (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Branch-scoped revenue context events. Separate from catalog_items — event_id is a context column, not a sale type.';


-- ── PART 3: Extend pos_transactions ─────────────────────────────────────────
--
-- Three new revenue-context columns:
--
--   revenue_source_type  — what category of thing was sold (e.g. 'catalog_item')
--   revenue_source_id    — FK to catalog_items.id (when type = 'catalog_item')
--   event_id             — FK to events.id — context, not a sale type
--   covers               — headcount for this transaction (unlocks per-cover metrics)
--
-- These are all nullable — existing transactions remain valid without them.
-- area_id already existed (segment 10/16) and is unchanged here.

ALTER TABLE `pos_transactions`
  ADD COLUMN `revenue_source_type` VARCHAR(50)      DEFAULT NULL
    COMMENT 'Classifies what was sold — e.g. catalog_item. Null = unclassified.'
    AFTER `area_id`,
  ADD COLUMN `revenue_source_id`   INT(11) UNSIGNED DEFAULT NULL
    COMMENT 'FK to catalog_items.id when revenue_source_type = catalog_item'
    AFTER `revenue_source_type`,
  ADD COLUMN `event_id`            INT(11)          DEFAULT NULL
    COMMENT 'FK to events.id — context under which the transaction occurred. Independent of revenue_source_type.'
    AFTER `revenue_source_id`,
  ADD COLUMN `covers`              TINYINT UNSIGNED DEFAULT NULL
    COMMENT 'Number of guests / covers. Null = not recorded. Max 255.'
    AFTER `event_id`,
  ADD KEY `idx_pt_revenue_source` (`revenue_source_type`, `revenue_source_id`),
  ADD KEY `idx_pt_event`          (`event_id`),
  ADD CONSTRAINT `fk_pt_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL;


-- ── PART 4: Permissions ──────────────────────────────────────────────────────
--
-- Catalog: 58-61
-- Events:  62-65
-- Pattern mirrors the areas permissions (54-57)

INSERT INTO `permissions` (`id`, `name`, `category`, `description`, `action_key`, `scope`)
VALUES
  (58, 'View Catalog',      'catalog', 'View catalog items (services and products)',   'VIEW_CATALOG',      'any'),
  (59, 'Create Catalog',    'catalog', 'Create new catalog items',                     'CREATE_CATALOG',    'any'),
  (60, 'Edit Catalog',      'catalog', 'Edit existing catalog items',                  'EDIT_CATALOG',      'any'),
  (61, 'Delete Catalog',    'catalog', 'Delete / deactivate catalog items',            'DELETE_CATALOG',    'any'),
  (62, 'View Events',       'events',  'View the list of events',                      'VIEW_EVENTS',       'any'),
  (63, 'Create Events',     'events',  'Create new events',                            'CREATE_EVENTS',     'any'),
  (64, 'Edit Events',       'events',  'Edit existing event details and manage status','EDIT_EVENTS',       'any'),
  (65, 'Delete Events',     'events',  'Delete events',                                'DELETE_EVENTS',     'any')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
