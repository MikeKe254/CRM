-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 20, 2026 at 02:37 AM
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
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `action_key` varchar(120) NOT NULL,
  `scope` enum('any','hq') NOT NULL DEFAULT 'any' COMMENT 'any=visible everywhere; hq=only relevant at HQ-level nodes',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `category`, `description`, `action_key`, `created_at`) VALUES
(1, 'View Dashboard', 'dashboard', 'Access main dashboard', 'VIEW_DASHBOARD', '2026-03-14 00:27:39'),
(2, 'View Transaction Cards', 'dashboard', 'View transaction cards on dashboard', 'VIEW_TRANSACTION_CARDS', '2026-03-14 00:27:39'),
(3, 'View Transactions', 'transactions', 'View transaction list', 'VIEW_TRANSACTIONS', '2026-03-14 00:27:39'),
(4, 'View Transaction Details', 'transactions', 'View single transaction details', 'VIEW_TRANSACTION_DETAILS', '2026-03-14 00:27:39'),
(5, 'View Full Customer Phone', 'transactions', 'View full customer phone number', 'VIEW_FULL_CUSTOMER_PHONE', '2026-03-14 00:27:39'),
(6, 'Send STK Push', 'Payments', 'Send STK Push request', 'SEND_STK_PUSH', '2026-03-14 00:27:39'),
(7, 'Access Advanced Search', 'search', 'Access advanced search page', 'ACCESS_ADVANCED_SEARCH', '2026-03-14 00:27:39'),
(8, 'Search by Shortcode', 'search', 'Search transactions by shortcode', 'SEARCH_BY_SHORTCODE', '2026-03-14 00:27:39'),
(9, 'Search by Date Range', 'search', 'Search transactions by date range', 'SEARCH_BY_DATE_RANGE', '2026-03-14 00:27:39'),
(10, 'Search by Phone', 'search', 'Search transactions by phone', 'SEARCH_BY_PHONE', '2026-03-14 00:27:39'),
(11, 'Search by Transaction ID', 'search', 'Search transactions by transaction id', 'SEARCH_BY_TRANSACTION_ID', '2026-03-14 00:27:39'),
(12, 'Search by Reference', 'search', 'Search transactions by reference', 'SEARCH_BY_REFERENCE', '2026-03-14 00:27:39'),
(13, 'Search by Amount', 'search', 'Search transactions by amount', 'SEARCH_BY_AMOUNT', '2026-03-14 00:27:39'),
(14, 'View Search Summary', 'analytics', 'View advanced search summary dashboard', 'VIEW_SEARCH_SUMMARY', '2026-03-14 00:27:39'),
(15, 'View Total Amount', 'analytics', 'View total amount statistics', 'VIEW_TOTAL_AMOUNT', '2026-03-14 00:27:39'),
(16, 'View Total Transactions', 'analytics', 'View total transactions statistics', 'VIEW_TOTAL_TRANSACTIONS', '2026-03-14 00:27:39'),
(17, 'View Total Customers', 'analytics', 'View total customers statistics', 'VIEW_TOTAL_CUSTOMERS', '2026-03-14 00:27:39'),
(18, 'View New Customers', 'analytics', 'View new customers statistics', 'VIEW_NEW_CUSTOMERS', '2026-03-14 00:27:39'),
(19, 'View Returning Customers', 'analytics', 'View returning customers statistics', 'VIEW_RETURNING_CUSTOMERS', '2026-03-14 00:27:39'),
(20, 'View Gender Breakdown', 'analytics', 'View gender breakdown statistics', 'VIEW_GENDER_BREAKDOWN', '2026-03-14 00:27:39'),
(21, 'View Customer Profile', 'customers', 'View customer profile', 'VIEW_CUSTOMER_PROFILE', '2026-03-14 00:27:39'),
(22, 'View Permissions', 'permissions', 'View permissions', 'VIEW_PERMISSIONS', '2026-03-14 00:27:39'),
(23, 'Create Permissions', 'permissions', 'Create permissions', 'CREATE_PERMISSIONS', '2026-03-14 00:27:39'),
(24, 'export_analytics', 'analytics', 'Export analytics.', 'EXPORT_ANALYTICS', '2026-03-14 00:27:39'),
(25, 'Delete Permissions', 'permissions', 'Delete permissions', 'DELETE_PERMISSIONS', '2026-03-14 00:27:39'),
(26, 'Assign Permissions', 'permissions', 'Assign permissions to roles', 'ASSIGN_PERMISSIONS', '2026-03-14 00:27:39'),
(27, 'View Roles', 'roles', 'View roles', 'VIEW_ROLES', '2026-03-14 00:27:39'),
(28, 'Create Roles', 'roles', 'Create roles', 'CREATE_ROLES', '2026-03-14 00:27:39'),
(29, 'Edit Roles', 'roles', 'Edit roles', 'EDIT_ROLES', '2026-03-14 00:27:39'),
(30, 'Delete Roles', 'roles', 'Delete roles', 'DELETE_ROLES', '2026-03-14 00:27:39'),
(31, 'Assign Roles', 'roles', 'Assign roles to users', 'ASSIGN_ROLES', '2026-03-14 00:27:39'),
(32, 'View Users', 'users', 'View users', 'VIEW_USERS', '2026-03-14 00:27:39'),
(33, 'Create Users', 'users', 'Create users', 'CREATE_USERS', '2026-03-14 00:27:39'),
(34, 'Edit Users', 'users', 'Edit users', 'EDIT_USERS', '2026-03-14 00:27:39'),
(35, 'Delete Users', 'users', 'Delete users', 'DELETE_USERS', '2026-03-14 00:27:39'),
(36, 'Authorize Pos Terminal', 'Point of Payment', 'Authorize or register a new POP terminal/device', 'AUTHORIZE_POS_TERMINAL', '2026-03-14 22:25:25'),
(37, 'View Audit Logs', 'admin', 'Can view system audit logs and activity history', 'VIEW_AUDIT_LOGS', '2026-03-17 18:16:04'),
(38, 'View User Activity', 'admin', 'View user activity logs and action history', 'VIEW_USER_ACTIVITY', '2026-03-20 00:00:00'),
(39, 'View User Activity', 'admin', 'View user activity logs and action history', 'VIEW_USER_ACTIVITY', '2026-03-20 04:56:27'),
(41, 'Manage Branches', 'branches', 'Create, rename, move, deactivate and delete branches within the user''s authority scope', 'MANAGE_BRANCHES', '2026-03-21 15:00:22'),
(42, 'View Branch Reports', 'branches', 'View cross-branch reporting and subtree data aggregations', 'VIEW_BRANCH_REPORTS', '2026-03-21 15:00:22'),
(43, 'Assign Users to Branches', 'branches', 'Assign users to branches within authority scope', 'ASSIGN_USERS_TO_BRANCHES', '2026-03-21 15:00:22'),
(44, 'View All Branch Data', 'branches', 'See data in all descendant branches (inherited via node assignment)', 'VIEW_ALL_BRANCH_DATA', '2026-03-21 15:00:22'),
(45, 'Assign Branch Heads', 'branches', 'Assign Regional Manager and Branch Manager roles to users', 'ASSIGN_BRANCH_HEADS', '2026-03-21 22:30:12'),
(46, 'Cross-Branch Login', 'access', 'User can log into any branch in the company without an explicit assignment', 'CROSS_BRANCH_LOGIN', '2026-03-21 22:30:12'),
(47, 'Cross-Branch Data View', 'access', 'User can view data from branches they are not directly assigned to', 'CROSS_BRANCH_VIEW', '2026-03-21 22:30:12'),
(48, 'View Roles Hierarchy', 'roles', 'View the role hierarchy structure', 'VIEW_ROLES_HIERARCHY', '2026-03-24 00:00:00'),
(49, 'Edit Roles Hierarchy', 'roles', 'Edit role hierarchy parent relationships and levels', 'EDIT_ROLES_HIERARCHY', '2026-03-24 00:00:00'),
(50, 'View Settings', 'settings', 'View company settings pages and configuration screens', 'VIEW_SETTINGS', '2026-04-06 00:00:00'),
(51, 'Edit Settings', 'settings', 'Edit company settings and save configuration changes', 'EDIT_SETTINGS', '2026-04-06 00:00:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
