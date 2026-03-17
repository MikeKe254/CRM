-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 17, 2026 at 09:42 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `koma_transactions`
--

-- --------------------------------------------------------

--
-- Table structure for table `bank_transfer_configs`
--

CREATE TABLE `bank_transfer_configs` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `payment_method_id` int(10) UNSIGNED NOT NULL DEFAULT 3,
  `account_name` varchar(100) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `bank_branch` varchar(100) DEFAULT NULL,
  `bank_code` varchar(20) DEFAULT NULL,
  `bank_swift_code` varchar(20) DEFAULT NULL,
  `account_number` varchar(50) NOT NULL,
  `account_holder_name` varchar(100) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'KES',
  `payment_instructions` text DEFAULT NULL,
  `reference_format` varchar(200) DEFAULT NULL,
  `reconciliation_email` varchar(200) DEFAULT NULL,
  `auto_confirm` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cash_configs`
--

CREATE TABLE `cash_configs` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `payment_method_id` int(10) UNSIGNED NOT NULL DEFAULT 2,
  `account_name` varchar(100) NOT NULL DEFAULT 'Cash',
  `currency` varchar(10) NOT NULL DEFAULT 'KES',
  `min_amount` decimal(15,2) DEFAULT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `requires_receipt` tinyint(1) NOT NULL DEFAULT 1,
  `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
  `approval_threshold` decimal(15,2) DEFAULT NULL,
  `float_amount` decimal(15,2) DEFAULT NULL,
  `reconciliation_email` varchar(200) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `subdomain` varchar(80) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `plan` varchar(50) NOT NULL DEFAULT 'free',
  `status` varchar(50) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `constraints`
--

CREATE TABLE `constraints` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `constraint_key` varchar(100) NOT NULL,
  `constraint_type` varchar(30) NOT NULL DEFAULT 'text' COMMENT 'Allowed: text, number, currency, list, boolean, date, time, percentage',
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `customer_profiles`
--

CREATE TABLE `customer_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `msisdn` varchar(20) NOT NULL,
  `first_name` varchar(120) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `first_transaction` datetime DEFAULT NULL,
  `last_transaction` datetime DEFAULT NULL,
  `customer_age_days` int(11) DEFAULT NULL,
  `customer_age_months` int(11) DEFAULT NULL,
  `days_since_last` int(11) DEFAULT NULL,
  `all_time_spend` decimal(14,2) DEFAULT 0.00,
  `average_spend` decimal(14,2) DEFAULT 0.00,
  `highest_transaction` decimal(14,2) DEFAULT 0.00,
  `lowest_transaction` decimal(14,2) DEFAULT 0.00,
  `all_time_transactions` int(11) DEFAULT 0,
  `search_spend` decimal(14,2) DEFAULT 0.00,
  `search_transactions` int(11) DEFAULT 0,
  `classification` varchar(50) DEFAULT NULL,
  `first_appearance_before_search` datetime DEFAULT NULL,
  `average_return_interval_days` decimal(10,2) DEFAULT NULL,
  `longest_interval_days` int(11) DEFAULT NULL,
  `visit_frequency_per_month` decimal(10,2) DEFAULT NULL,
  `spend_velocity_per_month` decimal(14,2) DEFAULT NULL,
  `spend_variance` decimal(14,2) DEFAULT NULL,
  `spend_std_dev` decimal(14,2) DEFAULT NULL,
  `revenue_share_percent` decimal(6,3) DEFAULT NULL,
  `customer_rank` int(11) DEFAULT NULL,
  `top_spender_percentile` decimal(6,3) DEFAULT NULL,
  `spending_segment` varchar(50) DEFAULT NULL,
  `loyalty_tier` varchar(50) DEFAULT NULL,
  `lifecycle_stage` varchar(50) DEFAULT NULL,
  `churn_risk` varchar(50) DEFAULT NULL,
  `churn_probability` decimal(6,3) DEFAULT NULL,
  `rfm_recency_score` int(11) DEFAULT NULL,
  `rfm_frequency_score` int(11) DEFAULT NULL,
  `rfm_monetary_score` int(11) DEFAULT NULL,
  `rfm_total_score` int(11) DEFAULT NULL,
  `predicted_next_visit` datetime DEFAULT NULL,
  `predicted_lifetime_value` decimal(14,2) DEFAULT NULL,
  `spending_growth_rate` decimal(10,4) DEFAULT NULL,
  `engagement_score` decimal(10,2) DEFAULT NULL,
  `visit_consistency_score` decimal(10,2) DEFAULT NULL,
  `weekday_visit_ratio` decimal(6,3) DEFAULT NULL,
  `weekend_visit_ratio` decimal(6,3) DEFAULT NULL,
  `morning_visit_ratio` decimal(6,3) DEFAULT NULL,
  `afternoon_visit_ratio` decimal(6,3) DEFAULT NULL,
  `evening_visit_ratio` decimal(6,3) DEFAULT NULL,
  `night_visit_ratio` decimal(6,3) DEFAULT NULL,
  `favorite_reference` varchar(255) DEFAULT NULL,
  `preferred_shortcode` int(11) DEFAULT NULL,
  `first_reference` varchar(255) DEFAULT NULL,
  `first_shortcode` int(11) DEFAULT NULL,
  `most_common_visit_time` varchar(50) DEFAULT NULL,
  `most_common_visit_day` varchar(50) DEFAULT NULL,
  `anomaly_flag` tinyint(1) DEFAULT 0,
  `profile_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `profile_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `company_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mpesa_configs`
--

CREATE TABLE `mpesa_configs` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `payment_method_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `account_name` varchar(100) NOT NULL,
  `type` varchar(20) NOT NULL COMMENT 'paybill | buygoods | till',
  `shortcode` varchar(20) NOT NULL,
  `till_number` varchar(20) DEFAULT NULL,
  `party_a` varchar(20) DEFAULT NULL,
  `party_b` varchar(20) DEFAULT NULL,
  `account_reference` varchar(100) DEFAULT NULL,
  `consumer_key` text DEFAULT NULL,
  `consumer_secret` text DEFAULT NULL,
  `initiator_name` varchar(100) DEFAULT NULL,
  `initiator_password` text DEFAULT NULL,
  `security_credential` text DEFAULT NULL,
  `passkey` text DEFAULT NULL,
  `callback_url` varchar(500) DEFAULT NULL,
  `validation_url` varchar(500) DEFAULT NULL,
  `confirmation_url` varchar(500) DEFAULT NULL,
  `timeout_url` varchar(500) DEFAULT NULL,
  `result_url` varchar(500) DEFAULT NULL,
  `queue_timeout_url` varchar(500) DEFAULT NULL,
  `environment` varchar(10) NOT NULL DEFAULT 'sandbox' COMMENT 'sandbox | production',
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `integration_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'If 0, payment method is active but processed manually without API calls',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mpesa_payments`
--

CREATE TABLE `mpesa_payments` (
  `id` int(11) NOT NULL,
  `bestguess` varchar(50) NOT NULL DEFAULT 'BILL',
  `short_code` bigint(20) NOT NULL,
  `client_id` int(11) NOT NULL,
  `msisdn` varchar(128) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference` varchar(50) NOT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `method` varchar(50) NOT NULL,
  `reference_id` int(11) NOT NULL,
  `transaction_id` varchar(50) DEFAULT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `gender` varchar(20) NOT NULL DEFAULT 'checking',
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `account` varchar(50) DEFAULT NULL,
  `status_code` int(11) NOT NULL,
  `retries` int(11) NOT NULL DEFAULT 0,
  `status_description` text DEFAULT NULL,
  `result_description` text DEFAULT NULL,
  `paybill_balance` varchar(20) DEFAULT NULL,
  `payment_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `method_key` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `action_key` varchar(120) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permission_constraints`
--

CREATE TABLE `permission_constraints` (
  `id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `constraint_id` int(10) UNSIGNED NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'If 1 this constraint must be set when permission is assigned',
  `default_value` varchar(500) DEFAULT NULL COMMENT 'Default value applied if none is set',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pesapal_configs`
--

CREATE TABLE `pesapal_configs` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `payment_method_id` int(10) UNSIGNED NOT NULL DEFAULT 4,
  `account_name` varchar(100) NOT NULL,
  `consumer_key` text DEFAULT NULL,
  `consumer_secret` text DEFAULT NULL,
  `api_key` text DEFAULT NULL,
  `secret_key` text DEFAULT NULL,
  `ipn_url` varchar(500) DEFAULT NULL,
  `callback_url` varchar(500) DEFAULT NULL,
  `cancellation_url` varchar(500) DEFAULT NULL,
  `notification_id` varchar(100) DEFAULT NULL,
  `accepts_cards` tinyint(1) NOT NULL DEFAULT 1,
  `accepted_card_types` varchar(200) DEFAULT NULL COMMENT 'e.g. visa,mastercard,amex',
  `accepts_mpesa` tinyint(1) NOT NULL DEFAULT 1,
  `accepts_airtel` tinyint(1) NOT NULL DEFAULT 0,
  `accepts_bank` tinyint(1) NOT NULL DEFAULT 0,
  `environment` varchar(10) NOT NULL DEFAULT 'sandbox' COMMENT 'sandbox | production',
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `integration_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'If 0, payment method is active but processed manually without API calls',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_terminals`
--

CREATE TABLE `pos_terminals` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `terminal_identifier` varchar(255) NOT NULL COMMENT 'Stable device fingerprint sent by the client',
  `authorized_by_user_id` int(11) NOT NULL COMMENT 'Dashboard user who authorized this terminal',
  `device_name` varchar(255) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `authorized_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL COMMENT '30 days from authorized_at. NULL = no expiry (legacy records).',
  `revoked_at` datetime DEFAULT NULL COMMENT 'NULL = active, set = revoked'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system_role` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permission_constraints`
--

CREATE TABLE `role_permission_constraints` (
  `id` int(10) UNSIGNED NOT NULL,
  `role_permission_id` int(10) UNSIGNED NOT NULL,
  `constraint_id` int(10) UNSIGNED NOT NULL,
  `constraint_value` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stk_push_logs`
--

CREATE TABLE `stk_push_logs` (
  `id` int(11) NOT NULL,
  `channel` enum('TILL','PAYBILL') NOT NULL,
  `shortcode` bigint(20) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `account_reference` varchar(50) DEFAULT NULL,
  `checkout_request_id` varchar(100) DEFAULT NULL,
  `merchant_request_id` varchar(100) DEFAULT NULL,
  `result_code` int(11) DEFAULT NULL,
  `result_description` text DEFAULT NULL,
  `mpesa_receipt` varchar(50) DEFAULT NULL,
  `transaction_date` datetime DEFAULT NULL,
  `status` enum('PENDING','SUCCESS','FAILED') DEFAULT 'PENDING',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `status_code` int(11) DEFAULT 0,
  `status_description` text DEFAULT NULL,
  `company_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint(20) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_option` varchar(50) DEFAULT NULL,
  `transaction_date` datetime NOT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `merchant_reference` varchar(255) DEFAULT NULL,
  `confirmation_code` varchar(100) DEFAULT NULL,
  `auth_code` varchar(100) DEFAULT NULL,
  `card_number` varchar(50) DEFAULT NULL,
  `company_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `pin` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'active',
  `is_super_admin` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `can_pos_login` tinyint(1) DEFAULT 0,
  `can_dashboard_login` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_logs`
--

CREATE TABLE `user_logs` (
  `id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) DEFAULT NULL,
  `action` varchar(120) DEFAULT NULL,
  `target_table` varchar(120) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the raw token',
  `device_name` varchar(255) DEFAULT NULL COMMENT 'e.g. Chrome on Windows, POS Terminal 3',
  `device_type` varchar(50) DEFAULT NULL COMMENT 'dashboard | pos',
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `last_active_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL COMMENT 'NULL = active, set = revoked/logged out',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bank_transfer_configs`
--
ALTER TABLE `bank_transfer_configs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `payment_method_id` (`payment_method_id`);

--
-- Indexes for table `cash_configs`
--
ALTER TABLE `cash_configs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `payment_method_id` (`payment_method_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subdomain` (`subdomain`);

--
-- Indexes for table `constraints`
--
ALTER TABLE `constraints`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `constraint_key` (`constraint_key`);

--
-- Indexes for table `customer_profiles`
--
ALTER TABLE `customer_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `msisdn` (`msisdn`),
  ADD KEY `idx_msisdn` (`msisdn`),
  ADD KEY `idx_last_transaction` (`last_transaction`),
  ADD KEY `idx_all_time_spend` (`all_time_spend`),
  ADD KEY `idx_customer_rank` (`customer_rank`),
  ADD KEY `idx_spending_segment` (`spending_segment`),
  ADD KEY `idx_loyalty_tier` (`loyalty_tier`),
  ADD KEY `idx_lifecycle_stage` (`lifecycle_stage`),
  ADD KEY `idx_churn_risk` (`churn_risk`);

--
-- Indexes for table `mpesa_configs`
--
ALTER TABLE `mpesa_configs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `payment_method_id` (`payment_method_id`);

--
-- Indexes for table `mpesa_payments`
--
ALTER TABLE `mpesa_payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `method_key` (`method_key`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `permission_constraints`
--
ALTER TABLE `permission_constraints`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_perm_constraint` (`permission_id`,`constraint_id`),
  ADD KEY `constraint_id` (`constraint_id`);

--
-- Indexes for table `pesapal_configs`
--
ALTER TABLE `pesapal_configs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company` (`company_id`),
  ADD KEY `payment_method_id` (`payment_method_id`);

--
-- Indexes for table `pos_terminals`
--
ALTER TABLE `pos_terminals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_company_terminal` (`company_id`,`terminal_identifier`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_roles_company` (`company_id`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_permission` (`role_id`,`permission_id`),
  ADD KEY `idx_role_permissions_role` (`role_id`),
  ADD KEY `idx_role_permissions_permission` (`permission_id`);

--
-- Indexes for table `role_permission_constraints`
--
ALTER TABLE `role_permission_constraints`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rpc` (`role_permission_id`,`constraint_id`),
  ADD KEY `constraint_id` (`constraint_id`);

--
-- Indexes for table `stk_push_logs`
--
ALTER TABLE `stk_push_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_company_email` (`company_id`,`email`),
  ADD KEY `idx_users_company` (`company_id`);

--
-- Indexes for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_logs_company` (`company_id`),
  ADD KEY `idx_logs_user` (`user_id`),
  ADD KEY `idx_logs_permission` (`permission_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
  ADD KEY `idx_user_roles_user` (`user_id`),
  ADD KEY `idx_user_roles_role` (`role_id`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bank_transfer_configs`
--
ALTER TABLE `bank_transfer_configs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_configs`
--
ALTER TABLE `cash_configs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `constraints`
--
ALTER TABLE `constraints`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_profiles`
--
ALTER TABLE `customer_profiles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mpesa_configs`
--
ALTER TABLE `mpesa_configs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mpesa_payments`
--
ALTER TABLE `mpesa_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permission_constraints`
--
ALTER TABLE `permission_constraints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pesapal_configs`
--
ALTER TABLE `pesapal_configs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_terminals`
--
ALTER TABLE `pos_terminals`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role_permission_constraints`
--
ALTER TABLE `role_permission_constraints`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stk_push_logs`
--
ALTER TABLE `stk_push_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_logs`
--
ALTER TABLE `user_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bank_transfer_configs`
--
ALTER TABLE `bank_transfer_configs`
  ADD CONSTRAINT `bank_transfer_configs_ibfk_1` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`);

--
-- Constraints for table `cash_configs`
--
ALTER TABLE `cash_configs`
  ADD CONSTRAINT `cash_configs_ibfk_1` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`);

--
-- Constraints for table `mpesa_configs`
--
ALTER TABLE `mpesa_configs`
  ADD CONSTRAINT `mpesa_configs_ibfk_1` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`);

--
-- Constraints for table `permission_constraints`
--
ALTER TABLE `permission_constraints`
  ADD CONSTRAINT `permission_constraints_ibfk_1` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `permission_constraints_ibfk_2` FOREIGN KEY (`constraint_id`) REFERENCES `constraints` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pesapal_configs`
--
ALTER TABLE `pesapal_configs`
  ADD CONSTRAINT `pesapal_configs_ibfk_1` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`);

--
-- Constraints for table `role_permission_constraints`
--
ALTER TABLE `role_permission_constraints`
  ADD CONSTRAINT `role_permission_constraints_ibfk_1` FOREIGN KEY (`constraint_id`) REFERENCES `constraints` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
