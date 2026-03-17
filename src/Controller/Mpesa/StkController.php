<?php

declare(strict_types=1);

namespace App\Controller\Mpesa;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Permission\CheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles STK push requests to Safaricom and status polling.
 * Replaces: Legacy/mpesa/ajax/stk_push.php
 *           Legacy/mpesa/ajax/stk_status_check.php
 *           Legacy/mpesa/ajax/skt_token.php
 *
 * Routes:
 *   POST /mpesa/stk/push    → send STK push
 *   GET  /mpesa/stk/status  → check STK push status
 */
#[Route('/mpesa/stk')]
class StkController extends AbstractController
{
    // Safaricom API endpoints
    private const OAUTH_URL   = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    private const STK_URL     = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    private const CALLBACK_URL = 'https://komagardensresort.co.ke/payments/stk_callback.php';

    // Shortcode → type map (matches legacy config)
    private const SHORTCODES = [
        'paybill' => [
            'shortcode'       => '5548218',
            'consumer_key'    => 'MgtAybkxnpN0rn2KoXfTFV85Jpm6TGmISgVGLQpWPpmuzKr3',
            'consumer_secret' => 'beEbOmG8wrEWVLEC6ELuTRY3eBqOJhtkFkjB6BrUakPUlqIA1LZQbxGPk2tSWZNb',
            'passkey'         => '25cf91cb68d9b18973649c96e062a1b06f4aa6e0984cb1b382b5781e9cf6e5f9',
            'till_number'     => null,
        ],
        'till' => [
            'shortcode'       => '5548220',
            'consumer_key'    => 'smeylHA2N8hzUlybnOgaF8hRbwef4G0DsdM3CvDtdBKrw5ge',
            'consumer_secret' => 'AtNvMavxd4AiqnWXRxKDrQ8zR7yVrUtZ0AfctZT36kofEHPBmaFQnFZ7AtNGeb3q',
            'passkey'         => 'b9d5a7c155b7f5371249cd9d65ddfad515e536358885f1601f9974780efa77dd',
            'till_number'     => '5620608',
        ],
    ];

    public function __construct(
        private readonly AuthService            $auth,
        private readonly CheckPermissionService $can,
        private readonly Connection             $db,
    ) {}

    // =========================================================================
    // POST /mpesa/stk/push
    // Replaces: stk_push.php
    // Permission: send_stk_push
    // Constraint: allowed_shortcodes (comma-separated list of allowed shortcodes)
    // =========================================================================

    #[Route('/push', name: 'mpesa_stk_push', methods: ['POST'])]
    public function push(Request $request): JsonResponse
    {
        date_default_timezone_set('Africa/Nairobi');

        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        if (!$this->can->check($session, 'send_stk_push')) {
            return $this->json(['error' => 'Access denied. You cannot send STK push.'], 403);
        }

        // ── Validate type ──────────────────────────────────────────────────────
        $type = $request->request->get('type', '');
        if (!in_array($type, ['till', 'paybill'], true)) {
            return $this->json(['error' => 'Invalid type. Must be till or paybill.'], 400);
        }

        // ── Constraint: allowed_shortcodes ────────────────────────────────────
        $allowedShortcodes = $this->can->constraint($session, 'send_stk_push', 'allowed_shortcodes', '');
        if ($allowedShortcodes !== '') {
            $allowed = array_map('trim', explode(',', $allowedShortcodes));
            $shortcode = self::SHORTCODES[$type]['shortcode'];
            if (!in_array($shortcode, $allowed, true)) {
                return $this->json(['error' => "You are not allowed to send STK push to shortcode {$shortcode}."], 403);
            }
        }

        // ── Validate phone ─────────────────────────────────────────────────────
        $phone = preg_replace('/\D/', '', (string) $request->request->get('phone', ''));
        $phone = $this->normalizePhone($phone);
        if (!$phone) {
            return $this->json(['error' => 'Invalid phone number.'], 400);
        }

        // ── Validate amount ────────────────────────────────────────────────────
        $amount = (float) $request->request->get('amount', 0);
        if ($amount <= 0) {
            return $this->json(['error' => 'Amount must be greater than 0.'], 400);
        }

        $account = (string) $request->request->get('account', 'KOMA');
        $config  = self::SHORTCODES[$type];

        // ── Get OAuth token ────────────────────────────────────────────────────
        $token = $this->getOAuthToken($config['consumer_key'], $config['consumer_secret']);
        if (!$token) {
            return $this->json(['error' => 'Failed to authenticate with Safaricom.'], 500);
        }

        // ── Build STK payload ──────────────────────────────────────────────────
        $timestamp = date('YmdHis');
        $password  = base64_encode($config['shortcode'] . $config['passkey'] . $timestamp);

        $payload = [
            'BusinessShortCode' => $config['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $type === 'paybill' ? 'CustomerPayBillOnline' : 'CustomerBuyGoodsOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $type === 'till' ? $config['till_number'] : $config['shortcode'],
            'PhoneNumber'       => $phone,
            'CallBackURL'       => self::CALLBACK_URL,
            'AccountReference'  => $account,
            'TransactionDesc'   => 'Koma Gardens Payment',
        ];

        // ── Execute STK push ───────────────────────────────────────────────────
        $ch = curl_init(self::STK_URL);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER    => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            return $this->json(['error' => 'STK Push failed.', 'details' => curl_error($ch)], 500);
        }
        curl_close($ch);

        $response = json_decode($raw, true);

        // ── Log to DB ──────────────────────────────────────────────────────────
        $this->db->insert('stk_push_logs', [
            'company_id'          => $session->company->id,
            'channel'             => strtoupper($type),
            'shortcode'           => $config['shortcode'],
            'phone'               => $phone,
            'amount'              => $amount,
            'account_reference'   => $account,
            'checkout_request_id' => $response['CheckoutRequestID'] ?? '',
            'merchant_request_id' => $response['MerchantRequestID'] ?? '',
            'status_code'         => (int) ($response['ResponseCode'] ?? 0),
            'status_description'  => $response['ResponseDescription'] ?? '',
            'initiated_by'        => $session->user->id,
            'created_at'          => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $this->json($response);
    }

    // =========================================================================
    // PRIVATE — OAuth token
    // =========================================================================

    private function getOAuthToken(string $consumerKey, string $consumerSecret): ?string
    {
        $credentials = base64_encode("{$consumerKey}:{$consumerSecret}");

        $ch = curl_init(self::OAUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Basic {$credentials}"],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        if ($res === false) {
            return null;
        }

        $data = json_decode($res, true);

        return $data['access_token'] ?? null;
    }

    private function normalizePhone(string $phone): ?string
    {
        if (strlen($phone) === 9 && str_starts_with($phone, '7')) {
            return '254' . $phone;
        }
        if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }
        if (strlen($phone) === 12 && str_starts_with($phone, '254')) {
            return $phone;
        }

        return null;
    }

    private function requireSession(Request $request): mixed
    {
        $token = $this->resolveToken($request);

        if (!$token) {
            return $this->json(['error' => 'Unauthenticated.'], 401);
        }

        try {
            return $this->auth->validateSession($token);
        } catch (AuthException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getHttpStatus());
        }
    }

    private function resolveToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->cookies->get('angavu_token') ?: null;
    }
}
