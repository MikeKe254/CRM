<?php
//session_start();
date_default_timezone_set('Africa/Nairobi');

if (!isset($_SESSION['admin_logged_in']) || isset($_SESSION['user_locked'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$checkoutID = $_GET['checkout_id'] ?? '';
if (!$checkoutID) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing CheckoutRequestID']);
    exit;
}

$conn = new mysqli('localhost', 'koma_trans', 'Komaresort@1', 'koma_transactions');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// Fetch latest log for this CheckoutRequestID
$stmt = $conn->prepare("
    SELECT result_code, result_description, amount, mpesa_receipt, transaction_date
    FROM stk_push_logs
    WHERE checkout_request_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$stmt->bind_param('s', $checkoutID);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();
$conn->close();

if ($data) {
    $response = [
        'found' => true,
        'result_code' => $data['result_code'],
        'result_description' => $data['result_description']
    ];

    // Include extra details only if successful
    if ((int)$data['result_code'] === 0) {
        $response['amount'] = $data['amount'];
        $response['mpesa_receipt'] = $data['mpesa_receipt'];
        $response['transaction_date'] = $data['transaction_date'];
    }

    echo json_encode($response);
} else {
    echo json_encode(['found' => false]);
}
