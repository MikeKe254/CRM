-- segment37: Extend events table to support Offers
-- Offers share the same recurrence engine as Events.
-- entry_type = 'event' (default) | 'offer'
-- Offer-specific: discount type, value, and applies_to scope.

ALTER TABLE events
  ADD COLUMN entry_type          ENUM('event','offer') NOT NULL DEFAULT 'event'      AFTER name,
  ADD COLUMN offer_discount_type ENUM('percent','fixed')        DEFAULT NULL,
  ADD COLUMN offer_discount_value DECIMAL(10,2) UNSIGNED        DEFAULT NULL,
  ADD COLUMN offer_applies_to    ENUM('all','category','item')  DEFAULT NULL;

-- All existing rows are correctly classified as 'event' via the DEFAULT above.
-- No data migration required.
