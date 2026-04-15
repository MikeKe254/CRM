-- segment25: Company-level loyalty module toggle
-- Added to companies table to enable/disable the entire loyalty module per company.
--
-- When disabled (0):
--   - Dashboard hides Loyalty Enroll and Points Checker cards
--   - Step 2 payment methods: loyalty redemption option filtered out
--   - Step 4 (loyalty capture): automatically skipped, transaction marked complete
--   - Loyalty JSON endpoints (loyalty-check, loyalty-redeem, redemption-balance): return 400
--   - LoyaltyController routes (check, enroll, check-phone): redirect to dashboard / return 403
--
-- Default is 1 (enabled) so existing companies are unaffected.

ALTER TABLE companies
    ADD COLUMN loyalty_module_enabled tinyint(1) NOT NULL DEFAULT 1
    AFTER enable_branches;
