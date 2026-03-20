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
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `icon` varchar(100) DEFAULT NULL COMMENT 'Lucide icon key e.g. lucide:users',
  `description` varchar(255) DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `modules`
--

INSERT INTO `modules` (`id`, `name`, `slug`, `icon`, `description`, `sort_order`, `is_active`, `created_at`) VALUES
(1, 'Customer CRM', 'customer_crm', 'lucide:users', 'Customer profiles, activity, and segmentation', 1, 1, '2026-03-19 16:32:54'),
(2, 'Transactions', 'transactions', 'lucide:receipt', 'Transaction records, orders, and payment tracking', 2, 1, '2026-03-19 16:32:54'),
(3, 'Business Management', 'business_management', 'lucide:building-2', 'Branches, staff users, and access control', 3, 1, '2026-03-19 16:32:54'),
(4, 'Analytics', 'analytics', 'lucide:bar-chart-2', 'Revenue, customer, and sales performance insights', 4, 1, '2026-03-19 16:32:54'),
(5, 'Menu', 'menu', 'lucide:utensils', 'Products, categories, availability, and public menus', 5, 1, '2026-03-19 16:32:54'),
(6, 'Online Orders', 'online_orders', 'lucide:shopping-cart', 'Cart, order placement, and order management', 6, 1, '2026-03-19 16:32:54'),
(7, 'Payments', 'payments', 'lucide:credit-card', 'Payment integrations and processing workflows', 7, 1, '2026-03-19 16:32:54'),
(8, 'Marketing', 'marketing', 'lucide:megaphone', 'Campaigns, targeting, and promotions', 8, 1, '2026-03-19 16:32:54'),
(9, 'Loyalty', 'loyalty', 'lucide:star', 'Points system, rewards, and customer loyalty', 9, 1, '2026-03-19 16:32:54'),
(10, 'Communications', 'communications', 'lucide:mail', 'Messaging and automated notifications', 10, 1, '2026-03-19 16:32:54'),
(11, 'Inventory', 'inventory', 'lucide:package', 'Stock levels and low-stock alerts', 11, 1, '2026-03-19 16:32:54'),
(12, 'Integrations', 'integrations', 'lucide:link', 'API access, webhooks, and external services', 12, 1, '2026-03-19 16:32:54'),
(13, 'Settings', 'settings', 'lucide:settings', 'General business settings and system preferences', 13, 1, '2026-03-19 16:32:54'),
(14, 'Security', 'security', 'lucide:shield', 'Authentication, session control, and audit logs', 14, 1, '2026-03-19 16:32:54');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_modules_slug` (`slug`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
