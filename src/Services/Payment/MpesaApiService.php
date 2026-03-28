<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Payment\DTO\PaymentConfig;

/**
 * Handles all Safaricom M-Pesa API calls.
 *
 * Credentials are passed in as a decrypted PaymentConfig — never hardcoded here.
 * All calls are to the live Safaricom API (use environment=sandbox for testing).
 */
final class MpesaApiService
{
    private const OAUTH_URL_LIVE    = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    private const OAUTH_URL_SANDBOX = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

    private const STK_URL_LIVE      = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    private const STK_URL_SANDBOX   = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

    private const STK_QUERY_URL_LIVE    = 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query';
    private const STK_QUERY_URL_SANDBOX = 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';

    // =========================================================================
    // STK PUSH
    // =========================================================================

    /**
     * Initiate an STK push.
     *
     * @param PaymentConfig $config     Decrypted mpesa config
     * @param string        $phone      Normalized E.164 phone e.g. 254712345678
     * @param float         $amount     Amount in KES (must be >= 1)
     * @param string        $accountRef Account reference / bill number
     * @param string        $callbackUrl Where Safaricom posts the result
     *
     * @return array{
     *   success: bool,
     *   checkout_request_id: string|null,
     *   merchant_request_id: string|null,
     *   response_code: string|null,
     *   response_description: string|null,
     *   raw: array
     * }
     */
    public function stkPush(
        PaymentConfig $config,
        string        $phone,
        float         $amount,
        string        $accountRef,
        string        $callbackUrl,
    ): array {
        $token = $this->fetchOAuthToken($config);

        if ($token === null) {
            return $this->failure('Failed to obtain Safaricom OAuth token.');
        }

        $env       = $config->cfg('environment', 'sandbox');
        $shortcode = (string) $config->cfg('shortcode');
        $passkey   = $config->credential('passkey');
        $type      = $config->cfg('type', 'paybill');

        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format('YmdHis');
        $password  = base64_encode($shortcode . $passkey . $timestamp);

        $partyB = $type === 'till'
            ? (string) $config->cfg('till_number', $shortcode)
            : $shortcode;

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $type === 'paybill' ? 'CustomerPayBillOnline' : 'CustomerBuyGoodsOnline',
            'Amount'            => (int) ceil($amount), // Safaricom requires integer
            'PartyA'            => $phone,
            'PartyB'            => $partyB,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => substr($accountRef, 0, 12), // Safaricom max 12 chars
            'TransactionDesc'   => 'Payment',
        ];

        $url = $env === 'production' ? self::STK_URL_LIVE : self::STK_URL_SANDBOX;
        $raw = $this->post($url, $payload, $token);

        if ($raw === null) {
            return $this->failure('STK push HTTP request failed.');
        }

        $success = isset($raw['ResponseCode']) && $raw['ResponseCode'] === '0';

        return [
            'success'              => $success,
            'checkout_request_id'  => $raw['CheckoutRequestID']   ?? null,
            'merchant_request_id'  => $raw['MerchantRequestID']   ?? null,
            'response_code'        => $raw['ResponseCode']        ?? null,
            'response_description' => $raw['ResponseDescription'] ?? null,
            'raw'                  => $raw,
        ];
    }

    /**
     * Query the status of an STK push by CheckoutRequestID.
     *
     * @return array{found: bool, result_code: string|null, result_description: string|null, raw: array}
     */
    public function queryStkStatus(PaymentConfig $config, string $checkoutRequestId): array
    {
        $token = $this->fetchOAuthToken($config);

        if ($token === null) {
            return ['found' => false, 'result_code' => null, 'result_description' => 'OAuth failed.', 'raw' => []];
        }

        $env       = $config->cfg('environment', 'sandbox');
        $shortcode = (string) $config->cfg('shortcode');
        $passkey   = $config->credential('passkey');
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format('YmdHis');
        $password  = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $url = $env === 'production' ? self::STK_QUERY_URL_LIVE : self::STK_QUERY_URL_SANDBOX;
        $raw = $this->post($url, $payload, $token);

        if ($raw === null) {
            return ['found' => false, 'result_code' => null, 'result_description' => 'Query request failed.', 'raw' => []];
        }

        return [
            'found'              => isset($raw['ResultCode']),
            'result_code'        => $raw['ResultCode']        ?? null,
            'result_description' => $raw['ResultDesc']        ?? null,
            'raw'                => $raw,
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function fetchOAuthToken(PaymentConfig $config): ?string
    {
        $env    = $config->cfg('environment', 'sandbox');
        $url    = $env === 'production' ? self::OAUTH_URL_LIVE : self::OAUTH_URL_SANDBOX;
        $key    = $config->credential('consumer_key');
        $secret = $config->credential('consumer_secret');

        if ($key === '' || $secret === '') {
            return null;
        }

        $credentials = base64_encode("{$key}:{$secret}");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Basic {$credentials}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        if ($res === false || $res === '') {
            return null;
        }

        $data = json_decode($res, true);
        return $data['access_token'] ?? null;
    }

    private function post(string $url, array $payload, string $bearerToken): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$bearerToken}",
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return null;
        }

        return json_decode($raw, true) ?? null;
    }

    private function failure(string $message): array
    {
        return [
            'success'              => false,
            'checkout_request_id'  => null,
            'merchant_request_id'  => null,
            'response_code'        => null,
            'response_description' => $message,
            'raw'                  => [],
        ];
    }
}
