-- ============================================================
-- Seed: Users for Koma Gardens & Resort (company_id = 1)
-- Generated: 2026-03-22
-- Password for all seeded users: Mike@132 (bcrypt cost 12)
-- ============================================================

-- Overall Manager (keep — do not re-insert if exists)
-- User ID 1: Mike Njagi <mike1@angavu.test>
-- Assigned: Overall Manager at HQ (node_id = 2)

-- Seeded test users (IDs 7–16 after cleanup)
INSERT INTO `users` (`company_id`, `name`, `email`, `password`, `status`, `can_dashboard_login`) VALUES
(1, 'James Kariuki',  'james@angavu.test',   '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1),
(1, 'Faith Otieno',   'faith@angavu.test',   '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1),
(1, 'Peter Mwangi',   'peter@angavu.test',   '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1),
(1, 'Sarah Kamau',    'sarah@angavu.test',   '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1),
(1, 'David Ochieng',  'david@angavu.test',   '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1),
(1, 'Mary Njeru',     'mary@angavu.test',    '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1),
(1, 'John Mutua',     'john@angavu.test',    '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1),
(1, 'Agnes Wambui',   'agnes@angavu.test',   '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1),
(1, 'Robert Kirui',   'robert@angavu.test',  '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1),
(1, 'Diana Auma',     'diana@angavu.test',   '$2y$12$/g7s4uKBUHl0CEf6ZojShOGtGwTNHB5Ab8AfXd4Nn1s.IIKZMO41.', 'active', 1);
