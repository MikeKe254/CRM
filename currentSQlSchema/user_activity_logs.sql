-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 20, 2026 at 02:40 AM
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
-- Table structure for table `user_activity_logs`
--

CREATE TABLE `user_activity_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `actor_type` enum('tenant','superadmin') NOT NULL DEFAULT 'tenant',
  `module_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → modules.id',
  `submodule_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → module_submodules.id',
  `feature_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK → module_features.id',
  `action` varchar(50) NOT NULL COMMENT 'Verb: VIEW, CREATE, UPDATE, DELETE, SEND, EXPORT, etc.',
  `permission` varchar(120) DEFAULT NULL COMMENT 'Permission that covered this action',
  `description` text NOT NULL COMMENT 'Human-readable narrative of the action',
  `subject_type` varchar(100) DEFAULT NULL COMMENT 'Entity acted on: transaction, user, role, terminal',
  `subject_id` int(11) DEFAULT NULL COMMENT 'ID of the subject entity',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Structured context: amounts, phone numbers, before/after values' CHECK (json_valid(`metadata`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_created` (`company_id`,`created_at`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_module_action` (`module_id`,`action`),
  ADD KEY `idx_submodule` (`submodule_id`),
  ADD KEY `idx_feature_id` (`feature_id`),
  ADD KEY `idx_subject` (`subject_type`,`subject_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD CONSTRAINT `fk_activity_feature` FOREIGN KEY (`feature_id`) REFERENCES `module_features` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_activity_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_activity_submodule` FOREIGN KEY (`submodule_id`) REFERENCES `module_submodules` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
