<?php
//session_start();
date_default_timezone_set('Africa/Nairobi');

// ===== AUTH CHECK =====
if (!isset($_SESSION['admin_logged_in']) || isset($_SESSION['user_locked'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ===== LOAD CONFIG =====
$config = require __DIR__ . '/../config/mpesa.php';

// ===== VALIDATE INPUTS =====
$type = $_POST['type'] ?? '';
if (!in_array($type, ['till','paybill'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid type']);
    exit;
}

$phone = preg_replace('/\D/', '', $_POST['phone'] ?? ''); // remove non-digits

// ===== NORMALIZE PHONE =====
if (strlen($phone) == 9 && substr($phone,0,1) == '7') {
    $phone = '254' . $phone; // 796763792 -> 254796763792
} elseif (strlen($phone) == 10 && substr($phone,0,1) == '0') {
    $phone = '254' . substr($phone,1); // 0796763792 -> 254796763792
} elseif (strlen($phone) == 12 && substr($phone,0,3) == '254') {
    // already correct
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid phone number']);
    exit;
}

$amount = (float)($_POST['amount'] ?? 0);
if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Amount must be greater than 0']);
    exit;
}

$account = $_POST['account'] ?? 'KOMA';

// ===== MPESA CREDENTIALS =====
$mpesa = $config[$type];
$timestamp = date('YmdHis');
$password = base64_encode($mpesa['shortcode'] . $mpesa['passkey'] . $timestamp);

// ===== GET OAUTH TOKEN =====
$token_ch = curl_init("https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials");
$credentials = base64_encode($mpesa['consumer_key'] . ':' . $mpesa['consumer_secret']);

curl_setopt_array($token_ch, [
    CURLOPT_HTTPHEADER => ["Authorization: Basic $credentials"],
    CURLOPT_RETURNTRANSFER => true
]);

$token_res = curl_exec($token_ch);
if ($token_res === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch token', 'details' => curl_error($token_ch)]);
    exit;
}

$token_data = json_decode($token_res, true);
if (!isset($token_data['access_token'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid token response', 'details' => $token_data]);
    exit;
}

$token = $token_data['access_token'];

// ===== STK PUSH PAYLOAD =====
$payload = [
    "BusinessShortCode" => $mpesa['shortcode'], // store OR paybill
    "Password" => $password,
    "Timestamp" => $timestamp,
    "TransactionType" => $type === 'paybill'
        ? "CustomerPayBillOnline"
        : "CustomerBuyGoodsOnline",
    "Amount" => $amount,
    "PartyA" => $phone,
    "PartyB" => $type === 'till'
        ? $mpesa['till_number']
        : $mpesa['shortcode'],
    "PhoneNumber" => $phone,
    "CallBackURL" => $config['callback_url'],
    "AccountReference" => $account,
    "TransactionDesc" => "Koma Gardens Payment"
];

// ===== EXECUTE STK PUSH =====
$ch = curl_init('https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload)
]);

$response_raw = curl_exec($ch);
if ($response_raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'STK Push failed', 'details' => curl_error($ch)]);
    exit;
}

$response = json_decode($response_raw, true);

// ===== LOG TO DATABASE =====
$conn = new mysqli('localhost', 'koma_trans', 'Komaresort@1', 'koma_transactions');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// Create variables for bind_param
$channel = strtoupper($type);
$shortcode = $mpesa['shortcode'];
$phone_var = $phone;
$amount_var = $amount;
$account_var = $account;
$checkout_id_var = $response['CheckoutRequestID'] ?? '';
$merchant_id_var = $response['MerchantRequestID'] ?? '';
$status_code_var = (int)($response['ResponseCode'] ?? 0);
$status_desc_var = $response['ResponseDescription'] ?? '';

$stmt = $conn->prepare("
    INSERT INTO stk_push_logs 
    (channel, shortcode, phone, amount, account_reference, checkout_request_id, merchant_request_id, status_code, status_description)
    VALUES (?,?,?,?,?,?,?,?,?)
");

$stmt->bind_param(
    "sisdssiss",
    $channel,
    $shortcode,
    $phone_var,
    $amount_var,
    $account_var,
    $checkout_id_var,
    $merchant_id_var,
    $status_code_var,
    $status_desc_var
);

$stmt->execute();
$stmt->close();
$conn->close();

// ===== RETURN RESPONSE =====
echo json_encode($response);
