-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 20, 2026 at 02:35 AM
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
-- Table structure for table `activity_log_templates`
--

CREATE TABLE `activity_log_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `action_key` varchar(120) NOT NULL COMMENT 'Unique dot-notation key e.g. customer.create',
  `template` text NOT NULL COMMENT 'Human-readable template with {placeholder} variables',
  `module_slug` varchar(80) DEFAULT NULL COMMENT 'Resolved from modules.slug — auto-fills module_id on log insert',
  `submodule_slug` varchar(80) DEFAULT NULL COMMENT 'Resolved from module_submodules.slug',
  `feature_slug` varchar(80) DEFAULT NULL COMMENT 'Resolved from module_features.slug',
  `default_action` varchar(50) DEFAULT NULL COMMENT 'VIEW, CREATE, UPDATE, DELETE, SEND, EXPORT, etc.',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_log_templates`
--

INSERT INTO `activity_log_templates` (`id`, `action_key`, `template`, `module_slug`, `submodule_slug`, `feature_slug`, `default_action`, `is_active`, `created_at`) VALUES
(1, 'auth.login', 'Logged in', 'security', 'access', 'authentication', 'LOGIN', 1, '2026-03-19 21:02:38'),
(2, 'auth.logout', 'Logged out', 'security', 'access', 'authentication', 'LOGOUT', 1, '2026-03-19 21:02:38'),
(3, 'auth.password.change', 'Changed account password', 'security', 'access', 'authentication', 'UPDATE', 1, '2026-03-19 21:02:38'),
(4, 'auth.2fa.enable', 'Enabled two-factor authentication', 'security', 'access', 'authentication', 'ENABLE', 1, '2026-03-19 21:02:38'),
(5, 'auth.2fa.disable', 'Disabled two-factor authentication', 'security', 'access', 'authentication', 'DISABLE', 1, '2026-03-19 21:02:38'),
(6, 'customer.view', 'Viewed customer list', 'customer_crm', 'profiles', 'customer_profiles', 'VIEW', 1, '2026-03-19 21:02:38'),
(7, 'customer.create', 'Created customer profile for {name}', 'customer_crm', 'profiles', 'customer_profiles', 'CREATE', 1, '2026-03-19 21:02:38'),
(8, 'customer.update', 'Updated customer profile for {name}', 'customer_crm', 'profiles', 'customer_profiles', 'UPDATE', 1, '2026-03-19 21:02:38'),
(9, 'customer.delete', 'Deleted customer profile: {name}', 'customer_crm', 'profiles', 'customer_profiles', 'DELETE', 1, '2026-03-19 21:02:38'),
(10, 'customer.note.add', 'Added note to customer {name}', 'customer_crm', 'profiles', 'customer_notes', 'CREATE', 1, '2026-03-19 21:02:38'),
(11, 'customer.note.delete', 'Deleted note from customer {name}', 'customer_crm', 'profiles', 'customer_notes', 'DELETE', 1, '2026-03-19 21:02:38'),
(12, 'customer.tag.add', 'Tagged customer {name} with \"{tag}\"', 'customer_crm', 'profiles', 'tags_labels', 'UPDATE', 1, '2026-03-19 21:02:38'),
(13, 'customer.tag.remove', 'Removed tag \"{tag}\" from customer {name}', 'customer_crm', 'profiles', 'tags_labels', 'UPDATE', 1, '2026-03-19 21:02:38'),
(14, 'customer.merge', 'Merged customer records: kept {primary}, removed {duplicate}', 'customer_crm', 'profiles', 'merge_duplicates', 'UPDATE', 1, '2026-03-19 21:02:38'),
(15, 'customer.segment.view', 'Viewed customer segments', 'customer_crm', 'segmentation', 'customer_groups', 'VIEW', 1, '2026-03-19 21:02:38'),
(16, 'customer.segment.create', 'Created customer segment: {name}', 'customer_crm', 'segmentation', 'customer_groups', 'CREATE', 1, '2026-03-19 21:02:38'),
(17, 'customer.segment.update', 'Updated customer segment: {name}', 'customer_crm', 'segmentation', 'customer_groups', 'UPDATE', 1, '2026-03-19 21:02:38'),
(18, 'customer.segment.delete', 'Deleted customer segment: {name}', 'customer_crm', 'segmentation', 'customer_groups', 'DELETE', 1, '2026-03-19 21:02:38'),
(19, 'transaction.view', 'Viewed transaction list', 'transactions', 'records', 'transaction_logging', 'VIEW', 1, '2026-03-19 21:02:38'),
(20, 'transaction.create', 'Created transaction #{id} · KES {amount}', 'transactions', 'records', 'transaction_logging', 'CREATE', 1, '2026-03-19 21:02:38'),
(21, 'transaction.update', 'Updated transaction #{id}', 'transactions', 'records', 'transaction_logging', 'UPDATE', 1, '2026-03-19 21:02:38'),
(22, 'transaction.delete', 'Deleted transaction #{id} · KES {amount}', 'transactions', 'records', 'transaction_logging', 'DELETE', 1, '2026-03-19 21:02:38'),
(23, 'transaction.status.update', 'Changed transaction #{id} status from {from} to {to}', 'transactions', 'records', 'transaction_logging', 'UPDATE', 1, '2026-03-19 21:02:38'),
(24, 'transaction.export', 'Exported {count} transactions', 'transactions', 'records', 'transaction_logging', 'EXPORT', 1, '2026-03-19 21:02:38'),
(25, 'order.view', 'Viewed order list', 'transactions', 'orders', 'order_records', 'VIEW', 1, '2026-03-19 21:02:38'),
(26, 'order.create', 'Created order #{id} for {customer}', 'transactions', 'orders', 'order_records', 'CREATE', 1, '2026-03-19 21:02:38'),
(27, 'order.status.update', 'Changed order #{id} status from {from} to {to}', 'transactions', 'orders', 'order_status', 'UPDATE', 1, '2026-03-19 21:02:38'),
(28, 'payment.status.update', 'Marked payment for transaction #{id} as {status}', 'transactions', 'payments', 'payment_status', 'UPDATE', 1, '2026-03-19 21:02:38'),
(29, 'payment.partial', 'Recorded partial payment of KES {amount} for transaction #{id}', 'transactions', 'payments', 'partial_payments', 'CREATE', 1, '2026-03-19 21:02:38'),
(30, 'payment.refund', 'Issued refund of KES {amount} for transaction #{id}', 'transactions', 'payments', 'refunds', 'CREATE', 1, '2026-03-19 21:02:38'),
(31, 'payment.stk.send', 'Sent STK push to {customer} ({phone}) · KES {amount}', 'payments', 'processing', 'payment_confirmation', 'SEND', 1, '2026-03-19 21:02:38'),
(32, 'payment.mpesa.configure', 'Updated MPesa configuration', 'payments', 'integrations', 'mpesa', 'UPDATE', 1, '2026-03-19 21:02:38'),
(33, 'payment.mpesa.enable', 'Enabled MPesa payment integration', 'payments', 'integrations', 'mpesa', 'ENABLE', 1, '2026-03-19 21:02:38'),
(34, 'payment.mpesa.disable', 'Disabled MPesa payment integration', 'payments', 'integrations', 'mpesa', 'DISABLE', 1, '2026-03-19 21:02:38'),
(35, 'payment.method.set_default', 'Changed default payment method from {from} to {to}', 'payments', 'integrations', 'custom_payment_methods', 'UPDATE', 1, '2026-03-19 21:02:38'),
(36, 'payment.method.create', 'Added payment method: {name}', 'payments', 'integrations', 'custom_payment_methods', 'CREATE', 1, '2026-03-19 21:02:38'),
(37, 'payment.method.delete', 'Removed payment method: {name}', 'payments', 'integrations', 'custom_payment_methods', 'DELETE', 1, '2026-03-19 21:02:38'),
(38, 'user.view', 'Viewed staff user list', 'business_management', 'users', 'user_accounts', 'VIEW', 1, '2026-03-19 21:02:38'),
(39, 'user.create', 'Created user account for {name} ({email})', 'business_management', 'users', 'user_accounts', 'CREATE', 1, '2026-03-19 21:02:38'),
(40, 'user.update', 'Updated user {name}: {changes}', 'business_management', 'users', 'user_accounts', 'UPDATE', 1, '2026-03-19 21:02:38'),
(41, 'user.delete', 'Deleted user account: {name} ({email})', 'business_management', 'users', 'user_accounts', 'DELETE', 1, '2026-03-19 21:02:38'),
(42, 'user.role.assign', 'Assigned role \"{role}\" to {user}', 'business_management', 'users', 'staff_roles', 'ASSIGN', 1, '2026-03-19 21:02:38'),
(43, 'user.role.revoke', 'Revoked role \"{role}\" from {user}', 'business_management', 'users', 'staff_roles', 'REVOKE', 1, '2026-03-19 21:02:38'),
(44, 'user.permission.grant', 'Granted permission \"{permission}\" to role {role}', 'business_management', 'permissions', 'role_based_access', 'ASSIGN', 1, '2026-03-19 21:02:38'),
(45, 'user.permission.revoke', 'Revoked permission \"{permission}\" from role {role}', 'business_management', 'permissions', 'role_based_access', 'REVOKE', 1, '2026-03-19 21:02:38'),
(46, 'branch.view', 'Viewed branch list', 'business_management', 'branches', 'branch_management', 'VIEW', 1, '2026-03-19 21:02:38'),
(47, 'branch.create', 'Created branch: {name}', 'business_management', 'branches', 'branch_management', 'CREATE', 1, '2026-03-19 21:02:38'),
(48, 'branch.update', 'Updated branch: {name}', 'business_management', 'branches', 'branch_management', 'UPDATE', 1, '2026-03-19 21:02:38'),
(49, 'branch.delete', 'Deleted branch: {name}', 'business_management', 'branches', 'branch_management', 'DELETE', 1, '2026-03-19 21:02:38'),
(50, 'branch.user.assign', 'Assigned {user} to branch {branch}', 'business_management', 'branches', 'branch_assignment', 'ASSIGN', 1, '2026-03-19 21:02:38'),
(51, 'branch.user.remove', 'Removed {user} from branch {branch}', 'business_management', 'branches', 'branch_assignment', 'REVOKE', 1, '2026-03-19 21:02:38'),
(52, 'product.view', 'Viewed product/menu list', 'menu', 'products', 'product_management', 'VIEW', 1, '2026-03-19 21:02:38'),
(53, 'product.create', 'Created product: {name} (KES {price})', 'menu', 'products', 'product_management', 'CREATE', 1, '2026-03-19 21:02:38'),
(54, 'product.update', 'Updated product: {name}', 'menu', 'products', 'product_management', 'UPDATE', 1, '2026-03-19 21:02:38'),
(55, 'product.delete', 'Deleted product: {name}', 'menu', 'products', 'product_management', 'DELETE', 1, '2026-03-19 21:02:38'),
(56, 'product.availability.toggle', 'Marked \"{product}\" as {status}', 'menu', 'availability', 'stock_status', 'UPDATE', 1, '2026-03-19 21:02:38'),
(57, 'category.create', 'Created menu category: {name}', 'menu', 'categories', 'category_management', 'CREATE', 1, '2026-03-19 21:02:38'),
(58, 'category.update', 'Updated menu category: {name}', 'menu', 'categories', 'category_management', 'UPDATE', 1, '2026-03-19 21:02:38'),
(59, 'category.delete', 'Deleted menu category: {name}', 'menu', 'categories', 'category_management', 'DELETE', 1, '2026-03-19 21:02:38'),
(60, 'campaign.sms.create', 'Created SMS campaign: {name}', 'marketing', 'campaigns', 'sms_campaigns', 'CREATE', 1, '2026-03-19 21:02:38'),
(61, 'campaign.sms.send', 'Sent SMS campaign \"{name}\" to {count} customers', 'marketing', 'campaigns', 'sms_campaigns', 'SEND', 1, '2026-03-19 21:02:38'),
(62, 'campaign.sms.delete', 'Deleted SMS campaign: {name}', 'marketing', 'campaigns', 'sms_campaigns', 'DELETE', 1, '2026-03-19 21:02:38'),
(63, 'campaign.email.create', 'Created email campaign: {name}', 'marketing', 'campaigns', 'email_campaigns', 'CREATE', 1, '2026-03-19 21:02:38'),
(64, 'campaign.email.send', 'Sent email campaign \"{name}\" to {count} customers', 'marketing', 'campaigns', 'email_campaigns', 'SEND', 1, '2026-03-19 21:02:38'),
(65, 'promotion.create', 'Created promotion: {name}', 'marketing', 'promotions', 'discounts', 'CREATE', 1, '2026-03-19 21:02:38'),
(66, 'promotion.update', 'Updated promotion: {name}', 'marketing', 'promotions', 'discounts', 'UPDATE', 1, '2026-03-19 21:02:38'),
(67, 'promotion.delete', 'Deleted promotion: {name}', 'marketing', 'promotions', 'discounts', 'DELETE', 1, '2026-03-19 21:02:38'),
(68, 'loyalty.points.award', 'Awarded {points} points to {customer}', 'loyalty', 'points_system', 'earn_points', 'CREATE', 1, '2026-03-19 21:02:38'),
(69, 'loyalty.points.redeem', 'Redeemed {points} points for {customer}', 'loyalty', 'points_system', 'redeem_points', 'UPDATE', 1, '2026-03-19 21:02:38'),
(70, 'loyalty.reward.create', 'Created loyalty reward: {name}', 'loyalty', 'rewards', 'reward_setup', 'CREATE', 1, '2026-03-19 21:02:38'),
(71, 'loyalty.reward.update', 'Updated loyalty reward: {name}', 'loyalty', 'rewards', 'reward_setup', 'UPDATE', 1, '2026-03-19 21:02:38'),
(72, 'loyalty.reward.delete', 'Deleted loyalty reward: {name}', 'loyalty', 'rewards', 'reward_setup', 'DELETE', 1, '2026-03-19 21:02:38'),
(73, 'inventory.stock.update', 'Updated stock for \"{product}\": {from} → {to} units', 'inventory', 'stock', 'stock_updates', 'UPDATE', 1, '2026-03-19 21:02:38'),
(74, 'inventory.stock.view', 'Viewed stock levels', 'inventory', 'stock', 'stock_levels', 'VIEW', 1, '2026-03-19 21:02:38'),
(75, 'analytics.revenue.view', 'Viewed revenue analytics', 'analytics', 'revenue', 'revenue_reports', 'VIEW', 1, '2026-03-19 21:02:38'),
(76, 'analytics.revenue.export', 'Exported revenue report', 'analytics', 'revenue', 'revenue_reports', 'EXPORT', 1, '2026-03-19 21:02:38'),
(77, 'analytics.customers.view', 'Viewed customer analytics', 'analytics', 'customers', 'top_customers', 'VIEW', 1, '2026-03-19 21:02:38'),
(78, 'analytics.sales.view', 'Viewed sales behaviour analytics', 'analytics', 'sales_behavior', 'average_order_value', 'VIEW', 1, '2026-03-19 21:02:38'),
(79, 'settings.business.update', 'Updated business settings', 'settings', 'general', 'business_settings', 'UPDATE', 1, '2026-03-19 21:02:38'),
(80, 'settings.currency.update', 'Changed currency to {currency}', 'settings', 'general', 'currency', 'UPDATE', 1, '2026-03-19 21:02:38'),
(81, 'settings.tax.update', 'Updated tax settings', 'settings', 'general', 'tax', 'UPDATE', 1, '2026-03-19 21:02:38'),
(82, 'settings.preferences.update', 'Updated system preferences', 'settings', 'system', 'preferences', 'UPDATE', 1, '2026-03-19 21:02:38'),
(83, 'settings.module.toggle', '{action} module: {module}', 'settings', 'system', 'module_toggles', 'UPDATE', 1, '2026-03-19 21:02:38'),
(84, 'integration.api_key.create', 'Generated new API key: {name}', 'integrations', 'api', 'api_keys', 'CREATE', 1, '2026-03-19 21:02:38'),
(85, 'integration.api_key.revoke', 'Revoked API key: {name}', 'integrations', 'api', 'api_keys', 'DELETE', 1, '2026-03-19 21:02:38'),
(86, 'integration.webhook.create', 'Created webhook for event \"{event}\": {url}', 'integrations', 'webhooks', 'event_triggers', 'CREATE', 1, '2026-03-19 21:02:38'),
(87, 'integration.webhook.delete', 'Deleted webhook: {url}', 'integrations', 'webhooks', 'event_triggers', 'DELETE', 1, '2026-03-19 21:02:38'),
(88, 'dashboard.access', 'Accessed the dashboard', 'security', NULL, NULL, 'VIEW', 1, '2026-03-20 00:00:00'),
(89, 'role.view', 'Viewed roles list', 'business_management', 'users', 'staff_roles', 'VIEW', 1, '2026-03-20 00:00:00'),
(90, 'role.show', 'Viewed role: {name}', 'business_management', 'users', 'staff_roles', 'VIEW', 1, '2026-03-20 00:00:00'),
(91, 'role.create', 'Created role: {name}', 'business_management', 'users', 'staff_roles', 'CREATE', 1, '2026-03-20 00:00:00'),
(92, 'role.update', 'Updated role: {name}', 'business_management', 'users', 'staff_roles', 'UPDATE', 1, '2026-03-20 00:00:00'),
(93, 'role.delete', 'Deleted role: {name}', 'business_management', 'users', 'staff_roles', 'DELETE', 1, '2026-03-20 00:00:00'),
(94, 'permission.view', 'Viewed permissions list', 'business_management', 'permissions', 'role_based_access', 'VIEW', 1, '2026-03-20 00:00:00'),
(95, 'role.constraint.set', 'Set "{constraint}" to "{value}" on "{permission}" for role: {role}', 'business_management', 'permissions', 'role_based_access', 'UPDATE', 1, '2026-03-20 00:00:00'),
(96, 'role.constraint.remove', 'Removed "{constraint}" constraint on "{permission}" from role: {role}', 'business_management', 'permissions', 'role_based_access', 'DELETE', 1, '2026-03-20 00:00:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log_templates`
--
ALTER TABLE `activity_log_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_action_key` (`action_key`),
  ADD KEY `idx_module_slug` (`module_slug`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log_templates`
--
ALTER TABLE `activity_log_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
