-- Tenant role templates seed (company_id=1)
-- Generated: 2026-03-24

INSERT INTO `roles` (`id`, `company_id`, `branch_id`, `name`, `description`, `is_system_role`, `is_head_role`, `scope`, `created_at`) VALUES
(1, 1, NULL, 'Overall Manager', 'Leads company-wide execution from head office and oversees regional performance.', 1, 1, 'hq', '2026-03-21 16:01:52'),
(2, 1, NULL, 'Branch Manager', 'Leads a branch team, daily operations, customer experience, and branch performance.', 1, 1, 'branch', '2026-03-21 16:01:52'),
(3, 1, NULL, 'Cashier', 'Handles front-desk transactions, payments, and customer receipts at branch level.', 1, 0, 'branch', '2026-03-21 16:01:52'),
(4, 1, NULL, 'Viewer', 'Read-only branch access for reports, dashboards, and operational visibility.', 1, 0, 'branch', '2026-03-21 16:01:52'),
(5, 1, NULL, 'Service Staff', 'Delivers branch services directly to customers as part of daily operations.', 0, 0, 'branch', '2026-03-21 16:01:52'),
(6, 1, NULL, 'Retail Staff', 'Handles branch retail activity, merchandising, and customer sales support.', 0, 0, 'branch', '2026-03-21 16:01:52'),
(7, 1, NULL, 'Housekeeping', 'Maintains branch cleanliness, readiness, and service environment standards.', 0, 0, 'branch', '2026-03-21 16:01:52'),
(8, 1, NULL, 'Car Wash Attendant', 'Performs branch car wash operations and related customer service tasks.', 0, 0, 'branch', '2026-03-21 16:01:52'),
(9, 1, NULL, 'Regional Manager', 'Leads a region, supports branch managers, and coordinates multi-branch execution.', 1, 1, 'region', '2026-03-21 22:30:12'),
(157, 1, NULL, 'Assistant Manager', 'Supports the branch manager in daily branch leadership, staff coordination, and operational follow-through.', 1, 0, 'branch', '2026-03-24 10:00:00'),
(158, 1, NULL, 'Department Manager', 'Leads a functional area within a branch such as service, kitchen, inventory, or operations.', 1, 0, 'branch', '2026-03-24 10:00:00'),
(173, 1, NULL, 'Supervisor', 'Leads day-to-day frontline execution within a branch team under the department manager.', 1, 0, 'branch', '2026-03-24 10:30:00'),
(182, 1, NULL, 'Support Functions', 'Handles shared branch support work such as admin, coordination, stock support, and back-office execution under assistant management.', 1, 0, 'branch', '2026-03-24 11:00:00'),
(155, 1, NULL, 'Owner', 'Highest company authority with final oversight of the organisation.', 1, 1, 'hq', '2026-03-22 12:27:08'),
(156, 1, NULL, 'Director', 'Head-office leadership role overseeing overall strategy, governance, and senior management.', 1, 1, 'hq', '2026-03-22 12:27:08');
