-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 34 — Recurring Events
--
-- Adds recurrence fields to the events table so a single event record can
-- represent a repeating occasion (weekly, biweekly, daily).
--
-- Design rules:
--   • One record per recurring event — forever.
--     "Tuesday Night Jazz" is one row. Every transaction ever tagged to it
--     carries event_id = that row. Revenue aggregates naturally, zero overhead.
--
--   • recurrence_type = 'none' preserves the original one-off behaviour
--     (uses starts_at / ends_at as before).
--
--   • For recurring events the terminal derives "running now" purely from
--     recurrence_days + recurrence_time_start/end + the clock.
--     No manager action is needed to start or end each occurrence.
--
--   • recurrence_valid_from / recurrence_valid_until bound the date range
--     within which the recurrence is active. valid_until = NULL means forever.
--
--   • Biweekly anchor: recurrence_valid_from is used as week-0.
--     Weeks 0, 2, 4, ... are active. If valid_from is null, the event's
--     created_at date is used as the anchor.
--
--   • 'cancelled' status is still the only manual override — it suppresses
--     the event regardless of recurrence pattern or schedule.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `events`
  ADD COLUMN `recurrence_type`        ENUM('none','daily','weekly','biweekly')
                                        NOT NULL DEFAULT 'none'
                                        COMMENT 'Recurrence pattern. none = one-off (uses starts_at/ends_at).'
                                        AFTER `status`,
  ADD COLUMN `recurrence_days`         JSON DEFAULT NULL
                                        COMMENT 'Days of week active: [0=Sun,1=Mon,2=Tue,3=Wed,4=Thu,5=Fri,6=Sat]'
                                        AFTER `recurrence_type`,
  ADD COLUMN `recurrence_time_start`   TIME DEFAULT NULL
                                        COMMENT 'Daily window open time, e.g. 18:00:00'
                                        AFTER `recurrence_days`,
  ADD COLUMN `recurrence_time_end`     TIME DEFAULT NULL
                                        COMMENT 'Daily window close time, e.g. 23:30:00'
                                        AFTER `recurrence_time_start`,
  ADD COLUMN `recurrence_valid_from`   DATE DEFAULT NULL
                                        COMMENT 'Date from which recurrence is active. Also biweekly anchor (week 0).'
                                        AFTER `recurrence_time_end`,
  ADD COLUMN `recurrence_valid_until`  DATE DEFAULT NULL
                                        COMMENT 'Date until which recurrence is active. NULL = indefinite.'
                                        AFTER `recurrence_valid_from`,
  ADD KEY `idx_ev_recurrence_type` (`recurrence_type`);
