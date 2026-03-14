<?php
//session_start();
$config = require __DIR__ . '/../config/mpesa.php';

$type = $_GET['type'] ?? 'till';
$creds = $config[$type];

$credentials = base64_encode($creds['consumer_key'] . ':' . $creds['consumer_secret']);

$ch = curl_init('https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ["Authorization: Basic $credentials"],
    CURLOPT_RETURNTRANSFER => true
]);

$res = json_decode(curl_exec($ch), true);
echo json_encode($res);
