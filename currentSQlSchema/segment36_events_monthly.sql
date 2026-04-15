-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 36 — Monthly recurrence for events
--
-- Adds 'monthly' to the recurrence_type ENUM and a helper column
-- recurrence_monthly_day that carries the day-of-month number OR the nth-
-- weekday occurrence number depending on mode:
--
--   Mode A — "By date" (recurrence_days IS NULL):
--     recurrence_monthly_day = 1-31  →  fires on that day every month
--     e.g. recurrence_monthly_day=15  →  "Every 15th of the month"
--
--   Mode B — "Nth weekday" (recurrence_days = JSON [dow]):
--     recurrence_monthly_day = 1-4   →  1st/2nd/3rd/4th occurrence of that weekday
--     recurrence_monthly_day = 5     →  last occurrence of that weekday
--     e.g. recurrence_days=[5], recurrence_monthly_day=1  →  "First Friday of every month"
--          recurrence_days=[6], recurrence_monthly_day=5  →  "Last Saturday of every month"
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `events`
  MODIFY COLUMN `recurrence_type`
    ENUM('none','daily','weekly','biweekly','monthly')
    NOT NULL DEFAULT 'none'
    COMMENT 'Recurrence pattern. none = one-off (uses starts_at/ends_at).'
    AFTER `status`,

  ADD COLUMN `recurrence_monthly_day`
    TINYINT UNSIGNED DEFAULT NULL
    COMMENT 'Monthly: day of month (1-31, date mode) OR nth occurrence (1=first…5=last, weekday mode when recurrence_days set).'
    AFTER `recurrence_valid_until`;
