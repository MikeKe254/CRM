-- ─────────────────────────────────────────────────────────────────────────────
-- Segment 22 — Payment config table updates
--
-- Adds to all four payment config tables:
--   branch_id              — scope config to a specific branch (NULL = company-wide)
--   credentials_encrypted  — flag: 0 = plaintext (legacy), 1 = encrypted with APP_CREDENTIALS_KEY
--
-- Sensitive credential columns remain in place but the application layer will
-- encrypt/decrypt them via CredentialEncryptionService when this flag is 1.
-- Existing rows keep flag=0 (plaintext) until re-saved through the UI.
-- ─────────────────────────────────────────────────────────────────────────────

-- ── mpesa_configs ────────────────────────────────────────────────────────────

ALTER TABLE `mpesa_configs`
  ADD COLUMN `branch_id`             INT(11)    DEFAULT NULL
    COMMENT 'NULL = company-wide; set to scope to a specific branch'
    AFTER `company_id`,
  ADD COLUMN `credentials_encrypted` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = consumer_key/secret/passkey/initiator_password are encrypted with APP_CREDENTIALS_KEY'
    AFTER `updated_at`,
  ADD KEY `idx_mpesa_cfg_branch` (`branch_id`),
  ADD CONSTRAINT `fk_mpesa_cfg_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;


-- ── cash_configs ─────────────────────────────────────────────────────────────
-- Cash has no API credentials, but branch_id scoping is still useful.

ALTER TABLE `cash_configs`
  ADD COLUMN `branch_id`             INT(11)    DEFAULT NULL
    COMMENT 'NULL = company-wide'
    AFTER `company_id`,
  ADD COLUMN `credentials_encrypted` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Placeholder for consistency — cash has no credentials to encrypt'
    AFTER `updated_at`,
  ADD KEY `idx_cash_cfg_branch` (`branch_id`),
  ADD CONSTRAINT `fk_cash_cfg_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;


-- ── bank_transfer_configs ─────────────────────────────────────────────────────

ALTER TABLE `bank_transfer_configs`
  ADD COLUMN `branch_id`             INT(11)    DEFAULT NULL
    COMMENT 'NULL = company-wide'
    AFTER `company_id`,
  ADD COLUMN `credentials_encrypted` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = any sensitive fields are encrypted with APP_CREDENTIALS_KEY'
    AFTER `updated_at`,
  ADD KEY `idx_bank_cfg_branch` (`branch_id`),
  ADD CONSTRAINT `fk_bank_cfg_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;


-- ── pesapal_configs ───────────────────────────────────────────────────────────

ALTER TABLE `pesapal_configs`
  ADD COLUMN `branch_id`             INT(11)    DEFAULT NULL
    COMMENT 'NULL = company-wide'
    AFTER `company_id`,
  ADD COLUMN `credentials_encrypted` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = consumer_key/secret/api_key/secret_key are encrypted with APP_CREDENTIALS_KEY'
    AFTER `updated_at`,
  ADD KEY `idx_pesapal_cfg_branch` (`branch_id`),
  ADD CONSTRAINT `fk_pesapal_cfg_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;
