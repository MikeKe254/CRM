<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../../../vendor/autoload.php';

// =======================
// Database Connection
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
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

} catch (PDOException $e) {

    echo json_encode([
        'success' => false,
        'data' => '<p class="text-red-500">Database connection failed.</p>'
    ]);

    exit;
}

// =======================
// Get Phone Number
// =======================

$msisdn = $_POST['msisdn'] ?? '';

if (!$msisdn) {

    echo json_encode([
        'success' => false,
        'data' => '<p class="text-red-500">Phone number missing.</p>'
    ]);

    exit;
}

// =======================
// Fetch Customer Profile
// =======================

$stmt = $pdo->prepare("
    SELECT *
    FROM customer_profiles
    WHERE msisdn = :msisdn
    LIMIT 1
");

$stmt->execute([
    ':msisdn' => $msisdn
]);

$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {

    echo json_encode([
        'success' => false,
        'data' => '<p class="text-red-500">Customer profile not found.</p>'
    ]);

    exit;
}

// =======================
// Load Metric Definitions
// =======================

$definitions = require __DIR__ . '/../config/customer_metrics.php';

$sections = [];

foreach ($definitions as $section_name => $metrics) {

    $section_fields = [];

    foreach ($metrics as $key => $meta) {

        $value = $customer[$key] ?? '';

        $section_fields[] = [
            'key' => $key,
            'label' => $meta['label'],
            'value' => $value,
            'definition' => $meta['definition']
        ];
    }

    $sections[] = [
        'title' => $section_name,
        'fields' => $section_fields
    ];
}

// =======================
// Twig Setup
// =======================

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../../../templates/Legacy');
$twig = new \Twig\Environment($loader);

// =======================
// Render HTML
// =======================

$html = $twig->render('customer_profile.twig', [
    'customer' => $customer,
    'sections' => $sections
]);

// =======================
// Return JSON
// =======================

echo json_encode([
    'success' => true,
    'data' => $html
]);
