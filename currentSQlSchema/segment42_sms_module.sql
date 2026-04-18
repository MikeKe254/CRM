-- ============================================================
-- SMS Module — segment 42
-- Tables: sms_providers, sms_configs, sms_sender_ids, sms_outbox
-- ============================================================

-- ------------------------------------------------------------
-- 1. sms_providers
--    Thin catalog of supported SMS providers.
--    PHP adapters are the source of truth for behaviour;
--    this table drives the tenant selection UI and activation.
-- ------------------------------------------------------------
CREATE TABLE `sms_providers` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `provider_key`     varchar(50)  NOT NULL COMMENT 'Machine key matching SmsProviderInterface::getProviderKey()',
  `display_name`     varchar(100) NOT NULL,
  `is_system`        tinyint(1)   NOT NULL DEFAULT 0 COMMENT '1 = Patronr-owned, tenant cannot edit credentials',
  `supports_balance` tinyint(1)   NOT NULL DEFAULT 0,
  `is_active`        tinyint(1)   NOT NULL DEFAULT 1 COMMENT '0 = hidden from tenant setup UI',
  `sort_order`       smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at`       datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at`       datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sms_provider_key` (`provider_key`),
  KEY `idx_sms_provider_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sms_providers` (`provider_key`, `display_name`, `is_system`, `supports_balance`, `is_active`, `sort_order`) VALUES
  ('patronr',       'Patronr',           1, 1, 1, 10),
  ('hostpinnacle',  'HostPinnacle',       0, 0, 1, 20),
  ('africastalking','Africa\'s Talking',  0, 0, 1, 30),
  ('uwazii',        'Uwazii',             0, 0, 0, 40),
  ('intouchvas',    'InTouch VAS',        0, 0, 0, 50);

-- ------------------------------------------------------------
-- 2. sms_configs
--    One row per company/branch/provider setup.
--    Credentials stored as an encrypted JSON blob.
-- ------------------------------------------------------------
CREATE TABLE `sms_configs` (
  `id`                              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id`                      bigint(20) unsigned NOT NULL,
  `branch_id`                       bigint(20) unsigned DEFAULT NULL,
  `provider_key`                    varchar(50)  NOT NULL,
  `label`                           varchar(100) DEFAULT NULL COMMENT 'Optional tenant-supplied name, e.g. "Main account"',
  `credentials_json`                text         DEFAULT NULL COMMENT 'Encrypted JSON blob — keys vary per provider',
  `credentials_encrypted`           tinyint(1)   NOT NULL DEFAULT 1,
  `default_sender_id_transactional` varchar(50)  DEFAULT NULL COMMENT 'Fallback if no sms_sender_ids row matches',
  `default_sender_id_promotional`   varchar(50)  DEFAULT NULL,
  `is_active`                       tinyint(1)   NOT NULL DEFAULT 1,
  `is_default`                      tinyint(1)   NOT NULL DEFAULT 0 COMMENT 'One default config per company used when configId not specified',
  `deleted_at`                      datetime     DEFAULT NULL,
  `created_at`                      datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at`                      datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sms_configs_company`          (`company_id`, `is_active`, `is_default`),
  KEY `idx_sms_configs_branch`           (`branch_id`),
  KEY `idx_sms_configs_provider`         (`provider_key`),
  KEY `idx_sms_configs_company_provider` (`company_id`, `provider_key`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. sms_sender_ids
--    Approved sender IDs per config.
--    Patronr configs have NO rows here — adapter enforces its own.
-- ------------------------------------------------------------
CREATE TABLE `sms_sender_ids` (
  `id`            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id`    bigint(20) unsigned NOT NULL,
  `sms_config_id` bigint(20) unsigned NOT NULL,
  `sender_id`     varchar(50) NOT NULL,
  `type`          enum('transactional','promotional','both') NOT NULL DEFAULT 'transactional',
  `is_default`    tinyint(1) NOT NULL DEFAULT 0,
  `is_active`     tinyint(1) NOT NULL DEFAULT 1,
  `created_at`    datetime   NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime   NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sms_sender_config_id_type` (`sms_config_id`, `sender_id`, `type`),
  KEY `idx_sms_sender_company`    (`company_id`),
  KEY `idx_sms_sender_config`     (`sms_config_id`, `type`, `is_default`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. sms_outbox
--    Every outbound message — written before dispatch,
--    updated to sent/failed after the Messenger handler runs.
-- ------------------------------------------------------------
CREATE TABLE `sms_outbox` (
  `id`                       bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id`               bigint(20) unsigned NOT NULL,
  `branch_id`                bigint(20) unsigned DEFAULT NULL,
  `sms_config_id`            bigint(20) unsigned DEFAULT NULL,
  `provider_key`             varchar(50)  NOT NULL,
  `sender_id`                varchar(50)  DEFAULT NULL,
  `message_type`             enum('transactional','promotional') NOT NULL DEFAULT 'transactional',
  `recipient_msisdn`         varchar(20)  NOT NULL,
  `message_body`             text         NOT NULL,
  -- linking
  `customer_id`              bigint(20) unsigned DEFAULT NULL,
  `loyalty_account_id`       bigint(20) unsigned DEFAULT NULL,
  `loyalty_notification_id`  bigint(20) unsigned DEFAULT NULL,
  -- status
  `status`                   enum('pending','queued','sent','delivered','failed') NOT NULL DEFAULT 'pending',
  `provider_message_id`      varchar(255) DEFAULT NULL,
  `provider_response`        text         DEFAULT NULL,
  `sent_at`                  datetime     DEFAULT NULL,
  `delivered_at`             datetime     DEFAULT NULL,
  `failed_at`                datetime     DEFAULT NULL,
  `failure_reason`           text         DEFAULT NULL,
  `created_at`               datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at`               datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sms_outbox_company_status`  (`company_id`, `status`, `created_at`),
  KEY `idx_sms_outbox_config`          (`sms_config_id`),
  KEY `idx_sms_outbox_customer`        (`customer_id`),
  KEY `idx_sms_outbox_loyalty_account` (`loyalty_account_id`),
  KEY `idx_sms_outbox_notification`    (`loyalty_notification_id`),
  KEY `idx_sms_outbox_recipient`       (`recipient_msisdn`),
  KEY `idx_sms_outbox_created`         (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
