-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 20, 2026 at 02:48 AM
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
-- Table structure for table `module_features`
--

CREATE TABLE `module_features` (
  `id` int(10) UNSIGNED NOT NULL,
  `submodule_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `module_features`
--

INSERT INTO `module_features` (`id`, `submodule_id`, `name`, `slug`, `description`, `sort_order`, `is_active`, `created_at`) VALUES
(1, 1, 'Customer Profiles', 'customer_profiles', NULL, 1, 1, '2026-03-19 16:32:56'),
(2, 1, 'Contact Details', 'contact_details', NULL, 2, 1, '2026-03-19 16:32:56'),
(3, 1, 'Customer Notes', 'customer_notes', NULL, 3, 1, '2026-03-19 16:32:56'),
(4, 1, 'Tags / Labels', 'tags_labels', NULL, 4, 1, '2026-03-19 16:32:56'),
(5, 1, 'Merge Duplicates', 'merge_duplicates', NULL, 5, 1, '2026-03-19 16:32:56'),
(6, 2, 'Transaction History', 'transaction_history', NULL, 1, 1, '2026-03-19 16:32:56'),
(7, 2, 'Order History', 'order_history', NULL, 2, 1, '2026-03-19 16:32:56'),
(8, 2, 'Visit Frequency', 'visit_frequency', NULL, 3, 1, '2026-03-19 16:32:56'),
(9, 2, 'Last Activity', 'last_activity', NULL, 4, 1, '2026-03-19 16:32:56'),
(10, 3, 'Customer Groups', 'customer_groups', NULL, 1, 1, '2026-03-19 16:32:56'),
(11, 3, 'Filters (Spend, Visits, Recency)', 'customer_filters', NULL, 2, 1, '2026-03-19 16:32:56'),
(12, 3, 'Dynamic Segments', 'dynamic_segments', NULL, 3, 1, '2026-03-19 16:32:56'),
(13, 4, 'Transaction Logging', 'transaction_logging', NULL, 1, 1, '2026-03-19 16:32:56'),
(14, 4, 'Payment Methods (MPesa, Cash, Bank)', 'payment_methods', NULL, 2, 1, '2026-03-19 16:32:56'),
(15, 4, 'Payment References', 'payment_references', NULL, 3, 1, '2026-03-19 16:32:56'),
(16, 5, 'Order Records', 'order_records', NULL, 1, 1, '2026-03-19 16:32:56'),
(17, 5, 'Items Attached', 'items_attached', NULL, 2, 1, '2026-03-19 16:32:56'),
(18, 5, 'Order Status', 'order_status', NULL, 3, 1, '2026-03-19 16:32:56'),
(19, 6, 'Payment Status', 'payment_status', NULL, 1, 1, '2026-03-19 16:32:56'),
(20, 6, 'Partial Payments', 'partial_payments', NULL, 2, 1, '2026-03-19 16:32:56'),
(21, 6, 'Refunds', 'refunds', NULL, 3, 1, '2026-03-19 16:32:56'),
(22, 7, 'Branch Management', 'branch_management', NULL, 1, 1, '2026-03-19 16:32:56'),
(23, 7, 'Branch Assignment', 'branch_assignment', NULL, 2, 1, '2026-03-19 16:32:56'),
(24, 8, 'User Accounts', 'user_accounts', NULL, 1, 1, '2026-03-19 16:32:56'),
(25, 8, 'Staff Roles', 'staff_roles', NULL, 2, 1, '2026-03-19 16:32:56'),
(26, 9, 'Role-Based Access', 'role_based_access', NULL, 1, 1, '2026-03-19 16:32:56'),
(27, 9, 'Module-Level Permissions', 'module_level_permissions', NULL, 2, 1, '2026-03-19 16:32:56'),
(28, 10, 'Revenue Reports', 'revenue_reports', NULL, 1, 1, '2026-03-19 16:32:56'),
(29, 10, 'Revenue Trends', 'revenue_trends', NULL, 2, 1, '2026-03-19 16:32:56'),
(30, 10, 'Revenue Per Branch', 'revenue_per_branch', NULL, 3, 1, '2026-03-19 16:32:56'),
(31, 11, 'Customer Lifetime Value', 'customer_lifetime_value', NULL, 1, 1, '2026-03-19 16:32:56'),
(32, 11, 'Top Customers', 'top_customers', NULL, 2, 1, '2026-03-19 16:32:56'),
(33, 11, 'Retention / Churn', 'retention_churn', NULL, 3, 1, '2026-03-19 16:32:56'),
(34, 11, 'Visit Frequency Analytics', 'visit_frequency_analytics', NULL, 4, 1, '2026-03-19 16:32:56'),
(35, 12, 'Average Order Value', 'average_order_value', NULL, 1, 1, '2026-03-19 16:32:56'),
(36, 12, 'Customer Spend Patterns', 'customer_spend_patterns', NULL, 2, 1, '2026-03-19 16:32:56'),
(37, 12, 'Payment Method Breakdown', 'payment_method_breakdown', NULL, 3, 1, '2026-03-19 16:32:56'),
(38, 13, 'Best-Selling Items', 'best_selling_items', NULL, 1, 1, '2026-03-19 16:32:56'),
(39, 13, 'Category Performance', 'category_performance', NULL, 2, 1, '2026-03-19 16:32:56'),
(40, 14, 'Product / Item Management', 'product_management', NULL, 1, 1, '2026-03-19 16:32:56'),
(41, 14, 'Pricing', 'pricing', NULL, 2, 1, '2026-03-19 16:32:56'),
(42, 14, 'Descriptions', 'descriptions', NULL, 3, 1, '2026-03-19 16:32:56'),
(43, 14, 'Images', 'images', NULL, 4, 1, '2026-03-19 16:32:56'),
(44, 15, 'Category Management', 'category_management', NULL, 1, 1, '2026-03-19 16:32:56'),
(45, 15, 'Sorting / Ordering', 'sorting_ordering', NULL, 2, 1, '2026-03-19 16:32:56'),
(46, 16, 'Stock Status (Available / Out of Stock)', 'stock_status', NULL, 1, 1, '2026-03-19 16:32:56'),
(47, 16, 'Time-Based Availability', 'time_based_availability', NULL, 2, 1, '2026-03-19 16:32:56'),
(48, 17, 'Public Menu Link', 'public_menu_link', NULL, 1, 1, '2026-03-19 16:32:56'),
(49, 17, 'QR Code', 'qr_code', NULL, 2, 1, '2026-03-19 16:32:56'),
(50, 17, 'Multi-Branch Menus', 'multi_branch_menus', NULL, 3, 1, '2026-03-19 16:32:56'),
(51, 18, 'Cart', 'cart', NULL, 1, 1, '2026-03-19 16:32:56'),
(52, 18, 'Place Order', 'place_order', NULL, 2, 1, '2026-03-19 16:32:56'),
(53, 18, 'Customer Details Capture', 'customer_details_capture', NULL, 3, 1, '2026-03-19 16:32:56'),
(54, 19, 'Order Status Tracking', 'order_status_tracking', NULL, 1, 1, '2026-03-19 16:32:56'),
(55, 19, 'Order History', 'order_history', NULL, 2, 1, '2026-03-19 16:32:56'),
(56, 19, 'Branch Selection', 'branch_selection', NULL, 3, 1, '2026-03-19 16:32:56'),
(57, 20, 'MPesa', 'mpesa', NULL, 1, 1, '2026-03-19 16:32:56'),
(58, 20, 'Bank Payments', 'bank_payments', NULL, 2, 1, '2026-03-19 16:32:56'),
(59, 20, 'Custom Payment Methods', 'custom_payment_methods', NULL, 3, 1, '2026-03-19 16:32:56'),
(60, 21, 'Payment Confirmation', 'payment_confirmation', NULL, 1, 1, '2026-03-19 16:32:56'),
(61, 21, 'Payment Status Tracking', 'payment_status_tracking', NULL, 2, 1, '2026-03-19 16:32:56'),
(62, 21, 'Payment Linking to Customers', 'payment_linking', NULL, 3, 1, '2026-03-19 16:32:56'),
(63, 22, 'SMS Campaigns', 'sms_campaigns', NULL, 1, 1, '2026-03-19 16:32:56'),
(64, 22, 'Email Campaigns', 'email_campaigns', NULL, 2, 1, '2026-03-19 16:32:56'),
(65, 23, 'Customer Segmentation Targeting', 'segmentation_targeting', NULL, 1, 1, '2026-03-19 16:32:56'),
(66, 23, 'Filters (Spend, Visits, Inactivity)', 'targeting_filters', NULL, 2, 1, '2026-03-19 16:32:56'),
(67, 24, 'Discounts', 'discounts', NULL, 1, 1, '2026-03-19 16:32:56'),
(68, 24, 'Coupons', 'coupons', NULL, 2, 1, '2026-03-19 16:32:56'),
(69, 24, 'Offers', 'offers', NULL, 3, 1, '2026-03-19 16:32:56'),
(70, 25, 'Earn Points', 'earn_points', NULL, 1, 1, '2026-03-19 16:32:56'),
(71, 25, 'Redeem Points', 'redeem_points', NULL, 2, 1, '2026-03-19 16:32:56'),
(72, 26, 'Reward Setup', 'reward_setup', NULL, 1, 1, '2026-03-19 16:32:56'),
(73, 26, 'Redemption Tracking', 'redemption_tracking', NULL, 2, 1, '2026-03-19 16:32:56'),
(74, 27, 'Loyalty Balance', 'loyalty_balance', NULL, 1, 1, '2026-03-19 16:32:56'),
(75, 27, 'Loyalty History', 'loyalty_history', NULL, 2, 1, '2026-03-19 16:32:56'),
(76, 28, 'SMS Notifications', 'sms_notifications', NULL, 1, 1, '2026-03-19 16:32:56'),
(77, 28, 'Email Notifications', 'email_notifications', NULL, 2, 1, '2026-03-19 16:32:56'),
(78, 29, 'Payment Confirmations', 'payment_confirmations_auto', NULL, 1, 1, '2026-03-19 16:32:56'),
(79, 29, 'Order Alerts', 'order_alerts', NULL, 2, 1, '2026-03-19 16:32:56'),
(80, 29, 'Campaign Sends', 'campaign_sends', NULL, 3, 1, '2026-03-19 16:32:56'),
(81, 30, 'Stock Levels', 'stock_levels', NULL, 1, 1, '2026-03-19 16:32:56'),
(82, 30, 'Stock Updates', 'stock_updates', NULL, 2, 1, '2026-03-19 16:32:56'),
(83, 31, 'Low Stock Alerts', 'low_stock_alerts', NULL, 1, 1, '2026-03-19 16:32:56'),
(84, 31, 'Out-of-Stock Flags', 'out_of_stock_flags', NULL, 2, 1, '2026-03-19 16:32:56'),
(85, 32, 'API Access', 'api_access', NULL, 1, 1, '2026-03-19 16:32:56'),
(86, 32, 'API Keys', 'api_keys', NULL, 2, 1, '2026-03-19 16:32:56'),
(87, 33, 'Event Triggers', 'event_triggers', NULL, 1, 1, '2026-03-19 16:32:56'),
(88, 33, 'External Sync', 'external_sync', NULL, 2, 1, '2026-03-19 16:32:56'),
(89, 34, 'Payment Providers', 'payment_providers', NULL, 1, 1, '2026-03-19 16:32:56'),
(90, 34, 'SMS Gateways', 'sms_gateways', NULL, 2, 1, '2026-03-19 16:32:56'),
(91, 35, 'Business Settings', 'business_settings', NULL, 1, 1, '2026-03-19 16:32:56'),
(92, 35, 'Currency', 'currency', NULL, 2, 1, '2026-03-19 16:32:56'),
(93, 35, 'Tax', 'tax', NULL, 3, 1, '2026-03-19 16:32:56'),
(94, 36, 'Module Toggles', 'module_toggles', NULL, 1, 1, '2026-03-19 16:32:56'),
(95, 36, 'Preferences', 'preferences', NULL, 2, 1, '2026-03-19 16:32:56'),
(96, 37, 'Authentication', 'authentication', NULL, 1, 1, '2026-03-19 16:32:56'),
(97, 37, 'Session Control', 'session_control', NULL, 2, 1, '2026-03-19 16:32:56'),
(98, 38, 'Activity Logs', 'activity_logs', NULL, 1, 1, '2026-03-19 16:32:56'),
(99, 38, 'Audit Logs', 'audit_logs', NULL, 2, 1, '2026-03-19 16:32:56');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `module_features`
--
ALTER TABLE `module_features`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_feature_slug` (`submodule_id`,`slug`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `module_features`
--
ALTER TABLE `module_features`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `module_features`
--
ALTER TABLE `module_features`
  ADD CONSTRAINT `fk_features_submodule` FOREIGN KEY (`submodule_id`) REFERENCES `module_submodules` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
