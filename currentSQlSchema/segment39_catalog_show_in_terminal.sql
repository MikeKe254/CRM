-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 39 — catalog_items: show_in_terminal flag
--
-- Controls which Services & Items entries appear on the terminal checkout
-- chip row. Default ON (1) so existing items continue showing without any
-- admin action required.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `catalog_items`
  ADD COLUMN IF NOT EXISTS `show_in_terminal` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'When 1 this item appears on the terminal checkout chip row. Set to 0 to hide it from cashiers without deactivating it.'
    AFTER `price`;
