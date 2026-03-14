<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// =======================
// Access rules (admin + junior)
// =======================
if (
    empty($_SESSION['user_unlocked']) ||
    !empty($_SESSION['user_locked']) ||
    empty($_SESSION['user_role'])
) {
    echo json_encode([]);
    exit;
}

$isJunior = ($_SESSION['user_role'] === 'junior');
$isAdmin  = ($_SESSION['user_role'] === 'admin');

// =======================
// Database connection
// =======================
$host = 'localhost';
$db_name = 'koma_transactions';
$db_user = 'koma_trans';
$db_pass = 'Komaresort@1';

$conn = new mysqli($host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode([]);
    exit;
}

// =======================
// Timezone
// =======================
date_default_timezone_set('Africa/Nairobi');

// =======================
// Fetch transactions
// =======================
if ($isAdmin) {

    // =======================
    // ADMIN: latest 500 only
    // =======================
    $sql = "
        SELECT *
        FROM mpesa_payments
        ORDER BY created_at DESC
        LIMIT 500
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();

} else {

    // =======================
    // JUNIOR: time-limited
    // =======================
    $now = new DateTime();
    $today_start = new DateTime('today 00:00:00');
    $yesterday_8hrs_back = (clone $today_start)->sub(new DateInterval('PT12H'));

    $start_time = $yesterday_8hrs_back->format('Y-m-d H:i:s');
    $end_time   = $now->format('Y-m-d H:i:s');

    $sql = "
        SELECT *
        FROM mpesa_payments
        WHERE created_at BETWEEN ? AND ?
        ORDER BY created_at DESC
        LIMIT 50
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start_time, $end_time);
    $stmt->execute();
}

// =======================
// Process results
// =======================
$res = $stmt->get_result();
$transactions = [];

while ($row = $res->fetch_assoc()) {

    // =======================
    // Mask phone for junior
    // =======================
    if ($isJunior && !empty($row['msisdn'])) {
        // 254796763792 → 2547967***92
        $row['msisdn'] =
            substr($row['msisdn'], 0, 7) .
            '***' .
            substr($row['msisdn'], -2);
    }

    $transactions[] = $row;
}

// =======================
// Output JSON (array only)
// =======================
echo json_encode($transactions);
