-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 38 — Terminal settings: enable_pos_pricing + show_events_at_terminal
--
-- enable_pos_pricing (default OFF):
--   Allows Services & Items prices to pre-fill checkout amounts at the terminal.
--   Deliberately discouraged — Patronr is a revenue classification system, not
--   a POS. Only enable if the branch has no dedicated point-of-sale system.
--
-- show_events_at_terminal (default ON):
--   Controls whether running events & offers appear as selectable chips on the
--   terminal checkout step 1. When OFF the cashier cannot tag transactions with
--   an event/offer context.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `pos_terminal_settings`
  ADD COLUMN IF NOT EXISTS `enable_pos_pricing` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'When enabled, Services & Items prices pre-fill amounts at checkout. Patronr is not a POS — only enable if no dedicated point-of-sale system is available.'
    AFTER `show_quick_stk`,
  ADD COLUMN IF NOT EXISTS `show_events_at_terminal` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'When enabled, running events & offers appear as chips at checkout step 1 for cashiers to tag the transaction.'
    AFTER `enable_pos_pricing`;
