<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// =======================
// Lock user & clear access
// =======================
$_SESSION['user_locked'] = true;

unset(
    $_SESSION['user_unlocked'],
    $_SESSION['user_role']
);

echo json_encode([
    'success' => true
]);
