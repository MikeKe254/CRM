<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=koma_transactions;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== SEED PHASE 1: Foundation Data ===\n\n";

// ─── STEP 1: Infer user_type from current assignments ───
echo "Step 1: Inferring user_type from user_node_roles...\n";
$pdo->exec("
    UPDATE `users` u
    SET u.`user_type` = (
      CASE
        WHEN EXISTS (
          SELECT 1 FROM `user_node_roles` unr
          JOIN `branches` b ON b.id = unr.node_id
          WHERE unr.user_id = u.id AND b.depth = 0
        ) AND NOT EXISTS (
          SELECT 1 FROM `user_node_roles` unr
          JOIN `branches` b ON b.id = unr.node_id
          WHERE unr.user_id = u.id AND b.depth > 0
        ) THEN 'office'

        WHEN EXISTS (
          SELECT 1 FROM `user_node_roles` unr
          JOIN `branches` b ON b.id = unr.node_id
          WHERE unr.user_id = u.id AND b.depth > 0
        ) AND NOT EXISTS (
          SELECT 1 FROM `user_node_roles` unr
          JOIN `branches` b ON b.id = unr.node_id
          WHERE unr.user_id = u.id AND b.depth = 0
        ) THEN 'branch'

        WHEN EXISTS (
          SELECT 1 FROM `user_node_roles` unr
          JOIN `branches` b ON b.id = unr.node_id
          WHERE unr.user_id = u.id AND b.depth = 0
        ) AND EXISTS (
          SELECT 1 FROM `user_node_roles` unr
          JOIN `branches` b ON b.id = unr.node_id
          WHERE unr.user_id = u.id AND b.depth > 0
        ) THEN 'both'

        ELSE 'branch'
      END
    )
    WHERE u.`deleted_at` IS NULL
");

$counts = $pdo->query("SELECT user_type, COUNT(*) as count FROM users WHERE deleted_at IS NULL GROUP BY user_type")->fetchAll(PDO::FETCH_ASSOC);
foreach ($counts as $c) {
    echo "  - {$c['user_type']}: {$c['count']} users\n";
}

// ─── STEP 2: Create seed departments ───
echo "\nStep 2: Creating seed departments for company_id=1...\n";
$departments = [
    'Unassigned' => 'Default department for users pending assignment',
    'Operations' => 'Daily business operations and service delivery',
    'Sales' => 'Customer acquisition and sales team',
    'Finance' => 'Accounting, payroll, financial management',
    'Management' => 'Executive and middle management',
    'Support' => 'Customer support and service excellence',
];

$stmt = $pdo->prepare("
    INSERT IGNORE INTO `departments` (company_id, name, description, status, created_at)
    VALUES (1, :name, :desc, 'active', NOW())
");

foreach ($departments as $name => $desc) {
    $stmt->execute(['name' => $name, 'desc' => $desc]);
    echo "  ✓ {$name}\n";
}

// ─── STEP 3: Assign all users to 'Unassigned' department ───
echo "\nStep 3: Assigning all users to 'Unassigned' department...\n";
$unassignedId = $pdo->query("SELECT id FROM departments WHERE company_id=1 AND name='Unassigned' LIMIT 1")->fetchColumn();
$pdo->exec("UPDATE users SET department_id = $unassignedId WHERE deleted_at IS NULL AND department_id IS NULL");
$assigned = $pdo->query("SELECT COUNT(*) FROM users WHERE department_id = $unassignedId")->fetchColumn();
echo "  ✓ {$assigned} users assigned\n";

// ─── STEP 4: Seed role_hierarchy with system roles ───
echo "\nStep 4: Seeding role_hierarchy with system roles...\n";

// Get role IDs
$roles = $pdo->query("
    SELECT id, name FROM roles WHERE company_id=1 AND is_system_role=1 AND deleted_at IS NULL
")->fetchAll(PDO::FETCH_KEY_PAIR);

$roleIds = array_flip($roles);  // name => id
$roleMap = [];
foreach ($roleIds as $name => $id) {
    $roleMap[$name] = $id;
}

echo "  Found roles:\n";
foreach ($roles as $id => $name) {
    echo "    - [$id] {$name}\n";
}

// Define hierarchy: role_name => [parent_name, level, scope]
$hierarchy = [
    'Owner' => [null, 4, 'any'],
    'Director' => ['Owner', 3, 'any'],
    'Overall Manager' => ['Director', 2, 'any'],
    'Regional Manager' => ['Overall Manager', 1, 'region'],
    'Branch Manager' => ['Overall Manager', 1, 'branch'],
    'Cashier' => ['Branch Manager', 0, 'branch'],
    'Viewer' => ['Branch Manager', 0, 'any'],
];

$stmt = $pdo->prepare("
    INSERT IGNORE INTO `role_hierarchy` (company_id, role_id, parent_role_id, level, scope)
    VALUES (1, :role_id, :parent_id, :level, :scope)
");

foreach ($hierarchy as $roleName => [$parentName, $level, $scope]) {
    $roleId = $roleMap[$roleName] ?? null;
    $parentId = $parentName ? ($roleMap[$parentName] ?? null) : null;

    if (!$roleId) {
        echo "  ✗ Role '{$roleName}' not found\n";
        continue;
    }

    $stmt->execute([
        'role_id' => $roleId,
        'parent_id' => $parentId,
        'level' => $level,
        'scope' => $scope,
    ]);
    echo "  ✓ {$roleName} (level=$level, scope=$scope)\n";
}

// ─── VERIFICATION ───
echo "\n=== VERIFICATION ===\n";

$deptCount = $pdo->query("SELECT COUNT(*) FROM departments WHERE company_id=1 AND deleted_at IS NULL")->fetchColumn();
echo "Departments created: {$deptCount}\n";

$userDeptCount = $pdo->query("SELECT COUNT(*) FROM users WHERE department_id IS NOT NULL")->fetchColumn();
echo "Users assigned to departments: {$userDeptCount}\n";

$hierCount = $pdo->query("SELECT COUNT(*) FROM role_hierarchy WHERE company_id=1")->fetchColumn();
echo "Role hierarchy entries: {$hierCount}\n";

// Show hierarchy tree
echo "\nRole Hierarchy Tree:\n";
$hier = $pdo->query("
    SELECT
      r.name AS role_name,
      rh.level,
      rh.scope,
      COALESCE(pr.name, 'TOP') AS parent_role
    FROM `role_hierarchy` rh
    LEFT JOIN `roles` r ON r.id = rh.role_id
    LEFT JOIN `roles` pr ON pr.id = rh.parent_role_id
    WHERE rh.company_id = 1
    ORDER BY rh.level DESC, r.name
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($hier as $row) {
    $indent = str_repeat('  ', 4 - $row['level']);
    echo "{$indent}→ {$row['role_name']} (parent: {$row['parent_role']}, scope: {$row['scope']})\n";
}

echo "\n✓ Phase 1 data seeding complete\n";
