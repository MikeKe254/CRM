<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

// =======================
// PIN → ROLE MAP
// =======================
$pinRoles = [
    '2039' => 'junior',
    '1759' => 'admin'
];

// =======================
// Validate input
// =======================
$pin = $_POST['pin'] ?? null;

if (!$pin || !isset($pinRoles[$pin])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid PIN'
    ]);
    exit;
}

// =======================
// Unlock + set level
// =======================
unset($_SESSION['user_locked']);

$_SESSION['user_unlocked'] = true;
$_SESSION['user_role']     = $pinRoles[$pin];

echo json_encode([
    'success' => true,
    'role'    => $_SESSION['user_role']
]);
