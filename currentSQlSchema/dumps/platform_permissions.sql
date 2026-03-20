-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 20, 2026 at 12:58 AM
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
-- Table structure for table `platform_permissions`
--

CREATE TABLE `platform_permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `action_key` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `platform_permissions`
--

INSERT INTO `platform_permissions` (`id`, `name`, `category`, `description`, `action_key`, `created_at`) VALUES
(1, 'view_platform_admins', 'platform_admins', 'View platform admin accounts.', 'VIEW_PLATFORM_ADMINS', '2026-03-19 00:35:50'),
(2, 'create_platform_admins', 'platform_admins', 'Create platform admin accounts.', 'CREATE_PLATFORM_ADMINS', '2026-03-19 00:35:50'),
(3, 'edit_platform_admins', 'platform_admins', 'Edit platform admin accounts.', 'EDIT_PLATFORM_ADMINS', '2026-03-19 00:35:50'),
(4, 'assign_platform_roles', 'platform_admins', 'Assign roles to platform admins.', 'ASSIGN_PLATFORM_ROLES', '2026-03-19 00:35:50'),
(5, 'deactivate_platform_admins', 'platform_admins', 'Deactivate platform admin accounts.', 'DEACTIVATE_PLATFORM_ADMINS', '2026-03-19 00:35:50'),
(6, 'view_platform_permissions', 'platform_admins', 'View platform permissions and mappings.', 'VIEW_PLATFORM_PERMISSIONS', '2026-03-19 00:35:50'),
(7, 'view_companies', 'companies', 'View companies.', 'VIEW_COMPANIES', '2026-03-19 00:35:50'),
(8, 'create_companies', 'companies', 'Create companies.', 'CREATE_COMPANIES', '2026-03-19 00:35:50'),
(9, 'edit_companies', 'companies', 'Edit company records.', 'EDIT_COMPANIES', '2026-03-19 00:35:50'),
(10, 'suspend_companies', 'companies', 'Suspend companies.', 'SUSPEND_COMPANIES', '2026-03-19 00:35:50'),
(11, 'activate_companies', 'companies', 'Activate companies.', 'ACTIVATE_COMPANIES', '2026-03-19 00:35:50'),
(12, 'delete_companies', 'companies', 'Delete companies.', 'DELETE_COMPANIES', '2026-03-19 00:35:50'),
(13, 'view_company_settings', 'companies', 'View company settings.', 'VIEW_COMPANY_SETTINGS', '2026-03-19 00:35:50'),
(14, 'edit_company_settings', 'companies', 'Edit company settings.', 'EDIT_COMPANY_SETTINGS', '2026-03-19 00:35:50'),
(15, 'access_company_context', 'company_access', 'Open a company context.', 'ACCESS_COMPANY_CONTEXT', '2026-03-19 00:35:50'),
(16, 'impersonate_company', 'company_access', 'Impersonate or auto-login into a company context.', 'IMPERSONATE_COMPANY', '2026-03-19 00:35:50'),
(17, 'switch_company_context', 'company_access', 'Switch between company contexts.', 'SWITCH_COMPANY_CONTEXT', '2026-03-19 00:35:50'),
(18, 'perform_company_support_actions', 'company_access', 'Perform support actions inside a company.', 'PERFORM_COMPANY_SUPPORT_ACTIONS', '2026-03-19 00:35:50'),
(19, 'view_global_analytics', 'analytics', 'View analytics across companies.', 'VIEW_GLOBAL_ANALYTICS', '2026-03-19 00:35:50'),
(20, 'view_company_analytics', 'analytics', 'View analytics for any company.', 'VIEW_COMPANY_ANALYTICS', '2026-03-19 00:35:50'),
(21, 'view_sales_analytics', 'analytics', 'View sales analytics.', 'VIEW_SALES_ANALYTICS', '2026-03-19 00:35:50'),
(22, 'view_customer_analytics', 'analytics', 'View customer analytics.', 'VIEW_CUSTOMER_ANALYTICS', '2026-03-19 00:35:50'),
(23, 'view_payment_analytics', 'analytics', 'View payment analytics.', 'VIEW_PAYMENT_ANALYTICS', '2026-03-19 00:35:50'),
(24, 'export_analytics', 'analytics', 'Export analytics.', 'EXPORT_ANALYTICS', '2026-03-19 00:35:50'),
(25, 'view_all_company_transactions', 'transactions', 'View transactions in any company.', 'VIEW_ALL_COMPANY_TRANSACTIONS', '2026-03-19 00:35:50'),
(26, 'view_transaction_details', 'transactions', 'View transaction details.', 'VIEW_TRANSACTION_DETAILS', '2026-03-19 00:35:50'),
(27, 'export_transactions', 'transactions', 'Export transactions.', 'EXPORT_TRANSACTIONS', '2026-03-19 00:35:50'),
(28, 'reverse_or_flag_transactions', 'transactions', 'Reverse or flag transactions.', 'REVERSE_OR_FLAG_TRANSACTIONS', '2026-03-19 00:35:50'),
(29, 'view_payment_configs', 'payments', 'View company payment configs.', 'VIEW_PAYMENT_CONFIGS', '2026-03-19 00:35:50'),
(30, 'edit_payment_configs', 'payments', 'Edit company payment configs.', 'EDIT_PAYMENT_CONFIGS', '2026-03-19 00:35:50'),
(31, 'view_mpesa_activity', 'payments', 'View MPesa activity.', 'VIEW_MPESA_ACTIVITY', '2026-03-19 00:35:50'),
(32, 'manage_mpesa_configs', 'payments', 'Manage MPesa configs.', 'MANAGE_MPESA_CONFIGS', '2026-03-19 00:35:50'),
(33, 'manage_pesapal_configs', 'payments', 'Manage Pesapal configs.', 'MANAGE_PESAPAL_CONFIGS', '2026-03-19 00:35:50'),
(34, 'manage_bank_configs', 'payments', 'Manage bank transfer configs.', 'MANAGE_BANK_CONFIGS', '2026-03-19 00:35:50'),
(35, 'view_company_users', 'users', 'View users in any company.', 'VIEW_COMPANY_USERS', '2026-03-19 00:35:50'),
(36, 'create_company_users', 'users', 'Create users in any company.', 'CREATE_COMPANY_USERS', '2026-03-19 00:35:50'),
(37, 'edit_company_users', 'users', 'Edit users in any company.', 'EDIT_COMPANY_USERS', '2026-03-19 00:35:50'),
(38, 'disable_company_users', 'users', 'Disable users in any company.', 'DISABLE_COMPANY_USERS', '2026-03-19 00:35:50'),
(39, 'reset_company_user_credentials', 'users', 'Reset user credentials.', 'RESET_COMPANY_USER_CREDENTIALS', '2026-03-19 00:35:50'),
(40, 'view_company_roles', 'roles_permissions', 'View roles in any company.', 'VIEW_COMPANY_ROLES', '2026-03-19 00:35:50'),
(41, 'edit_company_roles', 'roles_permissions', 'Edit roles in any company.', 'EDIT_COMPANY_ROLES', '2026-03-19 00:35:50'),
(42, 'assign_company_permissions', 'roles_permissions', 'Assign permissions in any company.', 'ASSIGN_COMPANY_PERMISSIONS', '2026-03-19 00:35:50'),
(43, 'edit_permission_constraints', 'roles_permissions', 'Edit permission constraints in any company.', 'EDIT_PERMISSION_CONSTRAINTS', '2026-03-19 00:35:50'),
(44, 'view_terminals', 'terminals', 'View company terminals.', 'VIEW_TERMINALS', '2026-03-19 00:35:50'),
(45, 'authorize_terminals', 'terminals', 'Authorize terminals.', 'AUTHORIZE_TERMINALS', '2026-03-19 00:35:50'),
(46, 'revoke_terminals', 'terminals', 'Revoke terminals.', 'REVOKE_TERMINALS', '2026-03-19 00:35:50'),
(47, 'view_audit_logs', 'audit_security', 'View audit logs.', 'VIEW_AUDIT_LOGS', '2026-03-19 00:35:50'),
(48, 'view_active_sessions', 'audit_security', 'View active sessions.', 'VIEW_ACTIVE_SESSIONS', '2026-03-19 00:35:50'),
(49, 'revoke_sessions', 'audit_security', 'Revoke sessions.', 'REVOKE_SESSIONS', '2026-03-19 00:35:50'),
(50, 'view_security_events', 'audit_security', 'View security events.', 'VIEW_SECURITY_EVENTS', '2026-03-19 00:35:50'),
(51, 'view_billing', 'billing', 'View billing and subscription data.', 'VIEW_BILLING', '2026-03-19 00:35:50'),
(52, 'manage_billing', 'billing', 'Manage billing.', 'MANAGE_BILLING', '2026-03-19 00:35:50'),
(53, 'view_subscriptions', 'billing', 'View subscriptions.', 'VIEW_SUBSCRIPTIONS', '2026-03-19 00:35:50'),
(54, 'manage_subscriptions', 'billing', 'Manage subscriptions.', 'MANAGE_SUBSCRIPTIONS', '2026-03-19 00:35:50'),
(55, 'view_platform_settings', 'platform_config', 'View platform settings.', 'VIEW_PLATFORM_SETTINGS', '2026-03-19 00:35:50'),
(56, 'edit_platform_settings', 'platform_config', 'Edit platform settings.', 'EDIT_PLATFORM_SETTINGS', '2026-03-19 00:35:50'),
(57, 'manage_integrations', 'platform_config', 'Manage platform integrations.', 'MANAGE_INTEGRATIONS', '2026-03-19 00:35:50'),
(58, 'manage_feature_flags', 'platform_config', 'Manage feature flags.', 'MANAGE_FEATURE_FLAGS', '2026-03-19 00:35:50'),
(59, 'manage_platform_owners', 'owner_only', 'Manage platform owner accounts.', 'MANAGE_PLATFORM_OWNERS', '2026-03-19 00:35:50'),
(60, 'delete_platform_admins', 'owner_only', 'Delete platform admins.', 'DELETE_PLATFORM_ADMINS', '2026-03-19 00:35:50'),
(61, 'edit_platform_core_settings', 'owner_only', 'Edit core platform settings.', 'EDIT_PLATFORM_CORE_SETTINGS', '2026-03-19 00:35:50'),
(62, 'manage_secret_integrations', 'owner_only', 'Manage sensitive integration secrets.', 'MANAGE_SECRET_INTEGRATIONS', '2026-03-19 00:35:50'),
(63, 'assign_owner_role', 'owner_only', 'Assign the PlatformOwner role.', 'ASSIGN_OWNER_ROLE', '2026-03-19 00:35:50'),
(64, 'test', 'test', 'test', 'TEST', '2026-03-19 05:40:28'),
(65, 'View Plans', 'plans', 'View the list of subscription plans and their details', 'VIEW_PLANS', '2026-03-19 19:51:39'),
(66, 'Manage Plans', 'plans', 'Create, edit and delete subscription plans and their pricing', 'MANAGE_PLANS', '2026-03-19 19:51:39'),
(67, 'Manage Plan Features', 'plans', 'Assign and remove features from plans', 'MANAGE_PLAN_FEATURES', '2026-03-19 19:51:39'),
(68, 'Manage Plan Limits', 'plans', 'Set quantitative limits (max users, branches, SMS) per plan', 'MANAGE_PLAN_LIMITS', '2026-03-19 19:51:39'),
(69, 'Manage Modules', 'plans', 'Create, edit and delete modules, submodules and features', 'MANAGE_MODULES', '2026-03-19 19:51:39'),
(70, 'Manage Tenant Overrides', 'plans', 'Apply per-tenant feature overrides on top of their assigned plan', 'MANAGE_TENANT_OVERRIDES', '2026-03-19 19:51:39'),
(71, 'create_platform_roles', 'platform_admins', 'Create platform role definitions.', 'CREATE_PLATFORM_ROLES', '2026-03-20 00:00:00'),
(72, 'edit_platform_roles', 'platform_admins', 'Edit platform role definitions.', 'EDIT_PLATFORM_ROLES', '2026-03-20 00:00:00'),
(73, 'delete_platform_roles', 'platform_admins', 'Delete platform role definitions.', 'DELETE_PLATFORM_ROLES', '2026-03-20 00:00:00'),
(74, 'suspend_platform_admins', 'platform_admins', 'Suspend platform admin accounts.', 'SUSPEND_PLATFORM_ADMINS', '2026-03-20 00:00:00'),
(75, 'view_superadmin_activity_logs', 'audit_security', 'View activity logs for platform admins.', 'VIEW_SUPERADMIN_ACTIVITY_LOGS', '2026-03-20 00:00:00'),
(76, 'view_owner_activity_logs', 'audit_security', 'View activity logs for the platform owner.', 'VIEW_OWNER_ACTIVITY_LOGS', '2026-03-20 00:00:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `platform_permissions`
--
ALTER TABLE `platform_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_platform_permission_name` (`name`),
  ADD UNIQUE KEY `uq_platform_permission_action_key` (`action_key`),
  ADD KEY `idx_platform_permission_category` (`category`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `platform_permissions`
--
ALTER TABLE `platform_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
