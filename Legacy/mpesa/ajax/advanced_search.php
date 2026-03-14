<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

// =======================
// Admin-only access control
// =======================
if (
    empty($_SESSION['user_unlocked']) ||
    ($_SESSION['user_role'] ?? null) !== 'admin' ||
    !empty($_SESSION['user_locked'])
) {
    echo json_encode([
        'success' => false,
        'message' => 'No permission to access',
        'data'    => []
    ]);
    exit;
}

// =======================
// Database connection
// =======================
$host = 'localhost';
$db_name = 'koma_transactions';
$db_user = 'koma_trans';
$db_pass = 'Komaresort@1';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
        'data'    => []
    ]);
    exit;
}

// =======================
// Collect filters
// =======================
$date_from  = $_GET['date_from'] ?? null;
$date_to    = $_GET['date_to'] ?? null;
$time_from  = $_GET['time_from'] ?? '00:00';
$time_to    = $_GET['time_to'] ?? '23:59';

$msisdn         = $_GET['msisdn'] ?? null;
$transaction_id = $_GET['transaction_id'] ?? null;
$reference      = $_GET['reference'] ?? null;
$name           = $_GET['name'] ?? null;
$amount         = $_GET['amount'] ?? null;

$type = $_GET['type'] ?? 'all';

// =======================
// Normalize phone
// =======================
if (!empty($msisdn)) {
    $msisdn = preg_replace('/\D/', '', $msisdn);

    if (str_starts_with($msisdn, '0')) {
        $msisdn = '254' . substr($msisdn, 1);
    }
}

// =======================
// Build WHERE clause
// =======================
$where  = [];
$params = [];

$fromDateTime = null;

// =======================
// Date & Time
// =======================
if ($date_from && $date_to) {

    if (!preg_match('/^\d{2}:\d{2}$/', $time_from)) {
        $time_from = '00:00';
    }

    if (!preg_match('/^\d{2}:\d{2}$/', $time_to)) {
        $time_to = '23:59';
    }

    $fromDateTime = "$date_from $time_from:00";
    $toDateTime   = "$date_to $time_to:59";

    $where[] = "mp.created_at BETWEEN :from_datetime AND :to_datetime";
    $params[':from_datetime'] = $fromDateTime;
    $params[':to_datetime']   = $toDateTime;
}

// =======================
// Other filters
// =======================
if (!empty($msisdn)) {
    $where[] = "mp.msisdn LIKE :msisdn";
    $params[':msisdn'] = "%$msisdn%";
}

if (!empty($transaction_id)) {
    $where[] = "mp.transaction_id LIKE :transaction_id";
    $params[':transaction_id'] = "%$transaction_id%";
}

if (!empty($reference)) {
    $where[] = "mp.reference LIKE :reference";
    $params[':reference'] = "%$reference%";
}

if (!empty($amount)) {
    $where[] = "mp.amount = :amount";
    $params[':amount'] = $amount;
}

if (!empty($name)) {
    $where[] = "(mp.first_name LIKE :name OR mp.middle_name LIKE :name OR mp.last_name LIKE :name)";
    $params[':name'] = "%$name%";
}

if ($type === 'paybill') {
    $where[] = "mp.short_code = 5548218";
} elseif ($type === 'till') {
    $where[] = "mp.short_code = 5548220";
}

// =======================
// Final SQL
// =======================
$sql = "
SELECT
    mp.*,

    cp.gender,
    cp.all_time_spend,
    cp.average_spend,
    cp.all_time_transactions AS total_visits,
    cp.spending_segment,
    cp.loyalty_tier,

    fp.first_payment,

    CASE
        WHEN fp.first_payment < :search_start THEN 'RETURNING'
        ELSE 'NEW'
    END AS customer_status

FROM mpesa_payments mp

LEFT JOIN customer_profiles cp
    ON mp.msisdn = cp.msisdn

LEFT JOIN (
    SELECT msisdn, MIN(created_at) AS first_payment
    FROM mpesa_payments
    GROUP BY msisdn
) fp
    ON mp.msisdn = fp.msisdn
";

$params[':search_start'] = $fromDateTime ?? '1970-01-01 00:00:00';

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY mp.created_at DESC LIMIT 500";

// =======================
// Execute
// =======================
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// =======================
// Output
// =======================
if ($results) {
    echo json_encode([
        'success' => true,
        'message' => 'Found ' . count($results) . ' transactions',
        'data'    => $results
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'No transactions found',
        'data'    => []
    ]);
}
