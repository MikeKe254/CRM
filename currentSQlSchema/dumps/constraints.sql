-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 20, 2026 at 02:49 AM
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

--
-- Dumping data for table `constraints`
--

INSERT INTO `constraints` (`id`, `name`, `constraint_key`, `constraint_type`, `description`, `created_at`) VALUES
(1, 'Max Hours History', 'max_hours_history', 'time', 'Maximum transaction history in seconds the user can view', '2026-03-17 22:06:19'),
(2, 'Max Transactions Visible', 'max_transactions_visible', 'number', 'Maximum number of transactions visible per page or session', '2026-03-17 22:06:19'),
(3, 'Allowed Shortcodes', 'allowed_shortcodes', 'list', 'Comma-separated list of shortcodes the user can access', '2026-03-17 22:06:19'),
(11, 'Require PIN Confirm', 'require_pin_confirm', 'boolean', 'Whether the user must confirm actions with their PIN', '2026-03-17 22:06:19'),
(12, 'Allow STK Push', 'allow_stk_push', 'boolean', 'Whether the user is allowed to initiate STK push requests', '2026-03-17 22:06:19');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `constraints`
--
ALTER TABLE `constraints`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `constraint_key` (`constraint_key`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `constraints`
--
ALTER TABLE `constraints`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
