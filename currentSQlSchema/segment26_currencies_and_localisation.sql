-- segment26: Currencies, exchange rates, and company localisation columns
--
-- Creates:
--   currencies              — ISO 4217 currency reference table (142 rows in currencies_insert.sql)
--   currency_exchange_rates — Pair-based exchange rates for multi-currency support
--
-- Adds to companies:
--   currency_code   CHAR(3)      DEFAULT 'KES'            — company default currency
--   timezone        VARCHAR(60)  DEFAULT 'Africa/Nairobi' — company timezone for reports/display
--   date_format     VARCHAR(20)  DEFAULT 'DD/MM/YYYY'     — company date display preference

CREATE TABLE IF NOT EXISTS currencies (
    id               SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             CHAR(3)           NOT NULL,
    name             VARCHAR(100)      NOT NULL,
    symbol           VARCHAR(10)       NOT NULL,
    symbol_position  ENUM('before','after') NOT NULL DEFAULT 'before',
    decimal_places   TINYINT UNSIGNED  NOT NULL DEFAULT 2,
    is_active        TINYINT(1)        NOT NULL DEFAULT 1,
    created_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS currency_exchange_rates (
    id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    base_code    CHAR(3)           NOT NULL,
    target_code  CHAR(3)           NOT NULL,
    rate         DECIMAL(18,8)     NOT NULL,
    source       ENUM('manual','api') NOT NULL DEFAULT 'manual',
    updated_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pair (base_code, target_code),
    KEY idx_base   (base_code),
    KEY idx_target (target_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS currency_code CHAR(3)     NOT NULL DEFAULT 'KES'            AFTER loyalty_module_enabled,
    ADD COLUMN IF NOT EXISTS timezone      VARCHAR(60)  NOT NULL DEFAULT 'Africa/Nairobi' AFTER currency_code,
    ADD COLUMN IF NOT EXISTS date_format   VARCHAR(20)  NOT NULL DEFAULT 'DD/MM/YYYY'     AFTER timezone;
