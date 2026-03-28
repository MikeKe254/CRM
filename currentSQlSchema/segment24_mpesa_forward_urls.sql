-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 24 — mpesa_configs: forward_urls
--
-- Stores a JSON array of URLs that the patronrapis callback processor will
-- POST the raw Safaricom C2B payload to before any local processing.
-- Delivery is fire-and-forget (parallel curl, 5s timeout).
-- Null / empty array = no forwarding.
--
-- Example value:
--   '["https://erp.acme.co.ke/hooks/mpesa","https://logs.example.com/mpesa"]'
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `mpesa_configs`
  ADD COLUMN `forward_urls` JSON DEFAULT NULL
    COMMENT 'Array of URLs to receive forwarded Safaricom C2B payloads'
    AFTER `confirmation_url`;
