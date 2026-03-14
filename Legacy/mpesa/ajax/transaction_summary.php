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
} catch (PDOException $e) {
    echo json_encode(['success' => false]);
    exit;
}

/**
 * WHERE builder (alias p)
 */
$where  = [];
$params = [];

/**
 * Period boundaries
 */
$periodStart = !empty($_GET['date_from'])
    ? $_GET['date_from'] . ' 00:00:00'
    : null;

$periodEnd = !empty($_GET['date_to'])
    ? $_GET['date_to'] . ' 23:59:59'
    : null;

if ($periodStart) {
    $where[] = 'p.created_at >= :date_from';
    $params[':date_from'] = $periodStart;
}

if ($periodEnd) {
    $where[] = 'p.created_at <= :date_to';
    $params[':date_to'] = $periodEnd;
}

if (!empty($_GET['time_from'])) {
    $where[] = 'TIME(p.created_at) >= :time_from';
    $params[':time_from'] = $_GET['time_from'];
}

if (!empty($_GET['time_to'])) {
    $where[] = 'TIME(p.created_at) <= :time_to';
    $params[':time_to'] = $_GET['time_to'];
}

/**
 * TYPE logic
 */
if (!empty($_GET['type'])) {
    if ($_GET['type'] === 'paybill') {
        $where[] = 'p.short_code = 5548218';
    } elseif ($_GET['type'] === 'till') {
        $where[] = 'p.short_code = 5548220';
    }
}

/**
 * MSISDN normalization
 */
if (!empty($_GET['msisdn'])) {
    $msisdn = preg_replace('/\D+/', '', $_GET['msisdn']);

    if (preg_match('/^0[17]\d{8}$/', $msisdn)) {
        $msisdn = '254' . substr($msisdn, 1);
    }

    if (preg_match('/^254[17]\d{8}$/', $msisdn)) {
        $where[] = 'p.msisdn LIKE :msisdn';
        $params[':msisdn'] = $msisdn . '%';
    }
}

if (!empty($_GET['transaction_id'])) {
    $where[] = 'p.transaction_id LIKE :transaction_id';
    $params[':transaction_id'] = '%' . $_GET['transaction_id'] . '%';
}

if (!empty($_GET['reference'])) {
    $where[] = 'p.reference LIKE :reference';
    $params[':reference'] = '%' . $_GET['reference'] . '%';
}

if (!empty($_GET['amount'])) {
    $where[] = 'p.amount = :amount';
    $params[':amount'] = $_GET['amount'];
}

if (!empty($_GET['name'])) {
    $where[] = '(p.first_name LIKE :name OR p.last_name LIKE :name)';
    $params[':name'] = '%' . $_GET['name'] . '%';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/**
 * MAIN SUMMARY
 */
$summarySql = "
    SELECT
        SUM(p.amount) AS total_amount,
        COUNT(*) AS total_transactions,
        MIN(p.created_at) AS date_from,
        MAX(p.created_at) AS date_to
    FROM mpesa_payments p
    $whereSQL
";

$stmt = $pdo->prepare($summarySql);
$stmt->execute($params);
$summary = $stmt->fetch();

/**
 * DISTINCT CUSTOMERS
 */
$distinctSql = "
    SELECT COUNT(DISTINCT p.msisdn)
    FROM mpesa_payments p
    $whereSQL
";

$distinctStmt = $pdo->prepare($distinctSql);
$distinctStmt->execute($params);
$totalCustomers = (int)$distinctStmt->fetchColumn();

/**
 * CUSTOMER HISTORY
 */
$historySql = "
    SELECT msisdn, MIN(created_at) AS first_payment
    FROM mpesa_payments
    GROUP BY msisdn
";

/**
 * NEW CUSTOMERS
 */
$newSql = "
    SELECT COUNT(*) FROM (
        SELECT s.msisdn
        FROM (
            SELECT DISTINCT p.msisdn
            FROM mpesa_payments p
            $whereSQL
        ) s
        JOIN ($historySql) h ON h.msisdn = s.msisdn
        LEFT JOIN mpesa_payments p2
            ON p2.msisdn = s.msisdn
           AND p2.created_at > h.first_payment
           AND p2.created_at <= :period_end
           AND TIMESTAMPDIFF(HOUR, h.first_payment, p2.created_at) >= 24
        WHERE h.first_payment BETWEEN :period_start AND :period_end
          AND p2.id IS NULL
    ) t
";

$newParams = $params;
$newParams[':period_start'] = $periodStart;
$newParams[':period_end']   = $periodEnd;

$newStmt = $pdo->prepare($newSql);
$newStmt->execute($newParams);
$newCustomers = (int)$newStmt->fetchColumn();

/**
 * RETURNING CUSTOMERS
 */
$returningSql = "
    SELECT COUNT(DISTINCT p.msisdn)
    FROM mpesa_payments p
    JOIN (
        SELECT msisdn, MIN(created_at) AS first_payment
        FROM mpesa_payments
        GROUP BY msisdn
    ) h ON h.msisdn = p.msisdn
    $whereSQL
    AND h.first_payment < :period_start
";

$returningParams = $params;
$returningParams[':period_start'] = $periodStart;

$returningStmt = $pdo->prepare($returningSql);
$returningStmt->execute($returningParams);
$returningCustomers = (int)$returningStmt->fetchColumn();

/**
 * GENDER SUMMARY (CUSTOMER-BASED)
 * One phone number = one person
 */
$genderSql = "
    SELECT
        SUM(CASE WHEN gender_norm = 'male' THEN 1 ELSE 0 END) AS total_males,
        SUM(CASE WHEN gender_norm = 'female' THEN 1 ELSE 0 END) AS total_females,
        SUM(CASE WHEN gender_norm = 'unchecked' THEN 1 ELSE 0 END) AS unchecked_genders
    FROM (
        SELECT
            p.msisdn,
            CASE
                WHEN LOWER(TRIM(p.gender)) = 'male' THEN 'male'
                WHEN LOWER(TRIM(p.gender)) = 'female' THEN 'female'
                ELSE 'unchecked'
            END AS gender_norm
        FROM mpesa_payments p
        $whereSQL
        GROUP BY p.msisdn
    ) t
";

$genderStmt = $pdo->prepare($genderSql);
$genderStmt->execute($params);
$gender = $genderStmt->fetch();

$totalMales   = (int)$gender['total_males'];
$totalFemales = (int)$gender['total_females'];
$unchecked    = (int)$gender['unchecked_genders'];

$knownTotal = $totalMales + $totalFemales;

$malePercentKnown   = $knownTotal > 0 ? round(($totalMales / $knownTotal) * 100, 2) : 0;
$femalePercentKnown = $knownTotal > 0 ? round(($totalFemales / $knownTotal) * 100, 2) : 0;

/**
 * FINAL RESPONSE (flat)
 */
echo json_encode([
    'success' => true,
    'data' => [
        'total_amount'        => (float)($summary['total_amount'] ?? 0),
        'total_transactions'  => (int)($summary['total_transactions'] ?? 0),
        'date_from'           => $summary['date_from'],
        'date_to'             => $summary['date_to'],
        'total_customers'     => $totalCustomers,
        'new_customers'       => $newCustomers,
        'returning_customers' => $returningCustomers,

        'total_males'             => $totalMales,
        'total_females'           => $totalFemales,
        'unchecked_genders'       => $unchecked,
        'known_gender_total'      => $knownTotal,
        'male_percentage_known'   => $malePercentKnown,
        'female_percentage_known' => $femalePercentKnown
    ]
]);
