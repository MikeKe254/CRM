<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=koma_transactions;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== PHASE 1: Add user_type and deleted_at to users ===\n";
try {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `user_type` ENUM('office', 'branch', 'both') DEFAULT 'branch' NOT NULL");
    echo "✓ Added user_type\n";
} catch (Exception $e) {
    echo "✓ user_type already exists\n";
}

try {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `deleted_at` TIMESTAMP NULL");
    echo "✓ Added deleted_at\n";
} catch (Exception $e) {
    echo "✓ deleted_at already exists\n";
}

echo "\n=== PHASE 2: Create departments table ===\n";
try {
    $pdo->exec("
        CREATE TABLE `departments` (
          `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `company_id` INT(11) NOT NULL,
          `name` VARCHAR(120) NOT NULL,
          `description` VARCHAR(255) DEFAULT NULL,
          `status` ENUM('active', 'inactive') DEFAULT 'active',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
          `deleted_at` TIMESTAMP NULL,
          UNIQUE KEY `uk_company_department_name` (`company_id`, `name`, `deleted_at`),
          KEY `idx_departments_company` (`company_id`),
          KEY `idx_departments_status` (`status`),
          CONSTRAINT `fk_departments_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ Created departments table\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n=== PHASE 3: Add department_id to users ===\n";
try {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `department_id` INT(11) NULL");
    echo "✓ Added department_id\n";
} catch (Exception $e) {
    echo "✓ department_id already exists\n";
}

try {
    $pdo->exec("ALTER TABLE `users` ADD CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL");
    echo "✓ Added FK constraint\n";
} catch (Exception $e) {
    echo "✓ FK already exists\n";
}

echo "\n=== PHASE 4: Create role_hierarchy table ===\n";
try {
    $pdo->exec("
        CREATE TABLE `role_hierarchy` (
          `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `company_id` INT(11) NOT NULL,
          `role_id` INT(11) NOT NULL,
          `parent_role_id` INT(11) NULL,
          `level` INT(3) DEFAULT 0,
          `scope` ENUM('any', 'hq', 'region', 'branch') DEFAULT 'any',
          UNIQUE KEY `uk_company_role_hierarchy` (`company_id`, `role_id`),
          KEY `idx_hierarchy_parent` (`parent_role_id`),
          KEY `idx_hierarchy_level` (`level`),
          KEY `idx_hierarchy_scope` (`scope`),
          CONSTRAINT `fk_hierarchy_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_hierarchy_parent` FOREIGN KEY (`parent_role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
          CONSTRAINT `fk_hierarchy_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ Created role_hierarchy table\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n=== PHASE 5: Add enable_branches to companies ===\n";
try {
    $pdo->exec("ALTER TABLE `companies` ADD COLUMN `enable_branches` TINYINT(1) DEFAULT 1");
    echo "✓ Added enable_branches\n";
} catch (Exception $e) {
    echo "✓ enable_branches already exists\n";
}

echo "\n=== VERIFICATION ===\n";
$userCols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC);
$userColNames = array_column($userCols, 'Field');
echo "users.user_type: " . (in_array('user_type', $userColNames) ? "✓" : "✗") . "\n";
echo "users.department_id: " . (in_array('department_id', $userColNames) ? "✓" : "✗") . "\n";
echo "users.deleted_at: " . (in_array('deleted_at', $userColNames) ? "✓" : "✗") . "\n";

$depts = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name='departments' AND table_schema=DATABASE()")->fetchColumn();
echo "departments table: " . ($depts ? "✓" : "✗") . "\n";

$hier = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name='role_hierarchy' AND table_schema=DATABASE()")->fetchColumn();
echo "role_hierarchy table: " . ($hier ? "✓" : "✗") . "\n";

$companies = $pdo->query("DESCRIBE companies")->fetchAll(PDO::FETCH_ASSOC);
$companyCols = array_column($companies, 'Field');
echo "companies.enable_branches: " . (in_array('enable_branches', $companyCols) ? "✓" : "✗") . "\n";

echo "\n✓ Phase 1 complete\n";
