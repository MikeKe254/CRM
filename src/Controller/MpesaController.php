<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Auth\AuthService;
use App\Services\Auth\DTO\AuthResult;
use App\Services\Auth\Exception\AuthException;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║                    Angavu MPesa Controller                       ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Full Symfony migration of all legacy MPesa ajax endpoints.      ║
 * ║  All routes are guarded by AuthService session validation.       ║
 * ║                                                                  ║
 * ║  Routes:                                                         ║
 * ║   POST  /mpesa/lock                  — Lock POS session          ║
 * ║   POST  /mpesa/unlock                — Unlock via PIN            ║
 * ║   GET   /mpesa/transactions          — Fetch transactions        ║
 * ║   GET   /mpesa/transactions/search   — Advanced search           ║
 * ║   GET   /mpesa/transactions/summary  — Summary + analytics       ║
 * ║   POST  /mpesa/stk/push              — Initiate STK push         ║
 * ║   GET   /mpesa/stk/status            — Poll STK push result      ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
#[Route('/mpesa')]
final class MpesaController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Connection  $db,
        #[Autowire('%env(MPESA_PAYBILL_SHORTCODE)%')]
        private readonly string $paybillShortcode,
        #[Autowire('%env(MPESA_TILL_SHORTCODE)%')]
        private readonly string $tillShortcode,
        #[Autowire('%env(MPESA_PAYBILL_CONSUMER_KEY)%')]
        private readonly string $paybillConsumerKey,
        #[Autowire('%env(MPESA_PAYBILL_CONSUMER_SECRET)%')]
        private readonly string $paybillConsumerSecret,
        #[Autowire('%env(MPESA_PAYBILL_PASSKEY)%')]
        private readonly string $paybillPasskey,
        #[Autowire('%env(MPESA_TILL_CONSUMER_KEY)%')]
        private readonly string $tillConsumerKey,
        #[Autowire('%env(MPESA_TILL_CONSUMER_SECRET)%')]
        private readonly string $tillConsumerSecret,
        #[Autowire('%env(MPESA_TILL_PASSKEY)%')]
        private readonly string $tillPasskey,
        #[Autowire('%env(MPESA_CALLBACK_URL)%')]
        private readonly string $callbackUrl,
    ) {}

    // =========================================================================
    // LOCK / UNLOCK
    // =========================================================================

    /**
     * Lock the current POS session.
     * Revokes the active session token — device stays authorized.
     *
     * POST /mpesa/lock
     * Header: Authorization: Bearer <token>
     */
    #[Route('/lock', name: 'mpesa_lock', methods: ['POST'])]
    public function lock(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);
        if (!$token) {
            return $this->unauthorized();
        }

        $this->auth->logout($token);

        return $this->json(['success' => true]);
    }

    /**
     * Unlock via PIN on an authorized terminal (POS login).
     * Returns a fresh session token on success.
     *
     * POST /mpesa/unlock
     * Body: { subdomain, pin, terminal_identifier, device_name? }
     */
    #[Route('/unlock', name: 'mpesa_unlock', methods: ['POST'])]
    public function unlock(Request $request): JsonResponse
    {
        $data = $this->json($request->toArray())->getContent();
        $body = json_decode($request->getContent(), true) ?? [];

        $subdomain  = $body['subdomain']            ?? '';
        $pin        = $body['pin']                  ?? '';
        $terminal   = $body['terminal_identifier']  ?? '';
        $deviceName = $body['device_name']           ?? 'POS Terminal';

        if (!$subdomain || !$pin || !$terminal) {
            return $this->json(['success' => false, 'message' => 'subdomain, pin and terminal_identifier are required.'], 400);
        }

        try {
            $result = $this->auth->loginPos(
                subdomain:            $subdomain,
                pin:                  $pin,
                terminalIdentifier:   $terminal,
                ipAddress:            $request->getClientIp() ?? '',
                userAgent:            $request->headers->get('User-Agent') ?? '',
                deviceName:           $deviceName,
            );

            return $this->json([
                'success' => true,
                'data'    => $result->toArray(),
            ]);

        } catch (AuthException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }
    }

    // =========================================================================
    // TRANSACTIONS
    // =========================================================================

    /**
     * Fetch recent transactions.
     * - Super admin / Owner / Admin: latest 500, no time limit
     * - All other roles: time-limited (last 12 hours + today), max 50, phone masked
     *
     * GET /mpesa/transactions
     * Header: Authorization: Bearer <token>
     */
    #[Route('/transactions', name: 'mpesa_transactions', methods: ['GET'])]
    public function transactions(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) return $session;

        $isAdmin = $this->isAdminSession($session);

        if ($isAdmin) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM mpesa_payments
                 WHERE  company_id = :company_id
                 ORDER  BY created_at DESC
                 LIMIT  500',
                ['company_id' => $session->company->id],
            );
        } else {
            $now            = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi'));
            $todayStart     = new \DateTimeImmutable('today 00:00:00', new \DateTimeZone('Africa/Nairobi'));
            $windowStart    = $todayStart->sub(new \DateInterval('PT12H'));

            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM mpesa_payments
                 WHERE  company_id = :company_id
                   AND  created_at BETWEEN :from AND :to
                 ORDER  BY created_at DESC
                 LIMIT  50',
                [
                    'company_id' => $session->company->id,
                    'from'       => $windowStart->format('Y-m-d H:i:s'),
                    'to'         => $now->format('Y-m-d H:i:s'),
                ],
            );

            // Mask phone for non-admin roles: 254796763792 → 2547967***92
            $rows = array_map(function (array $row): array {
                if (!empty($row['msisdn'])) {
                    $row['msisdn'] = substr($row['msisdn'], 0, 7) . '***' . substr($row['msisdn'], -2);
                }
                return $row;
            }, $rows);
        }

        return $this->json($rows);
    }

    /**
     * Advanced transaction search with filters.
     * Admin-only.
     *
     * GET /mpesa/transactions/search
     * Query: date_from, date_to, time_from, time_to, msisdn, transaction_id,
     *        reference, name, amount, type (all|paybill|till)
     * Header: Authorization: Bearer <token>
     */
    #[Route('/transactions/search', name: 'mpesa_transactions_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) return $session;

        if (!$this->isAdminSession($session)) {
            return $this->json(['success' => false, 'message' => 'No permission to access.', 'data' => []], 403);
        }

        [$where, $params] = $this->buildWhereClause($request, $session->company->id);

        $sql = 'SELECT mp.*
                FROM   mpesa_payments mp'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY mp.created_at DESC LIMIT 500';

        $results = $this->db->fetchAllAssociative($sql, $params);

        return $this->json([
            'success' => !empty($results),
            'message' => !empty($results)
                ? 'Found ' . count($results) . ' transactions'
                : 'No transactions found',
            'data'    => $results,
        ]);
    }

    /**
     * Transaction summary + analytics (totals, customers, gender breakdown).
     * Admin-only.
     *
     * GET /mpesa/transactions/summary
     * Query: same filters as /search
     * Header: Authorization: Bearer <token>
     */
    #[Route('/transactions/summary', name: 'mpesa_transactions_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) return $session;

        if (!$this->isAdminSession($session)) {
            return $this->json(['success' => false, 'message' => 'No permission to access.'], 403);
        }

        [$where, $params] = $this->buildWhereClause($request, $session->company->id);
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // ── Period boundaries for new/returning customer queries ─────────────
        $periodStart = !empty($request->query->get('date_from'))
            ? $request->query->get('date_from') . ' 00:00:00'
            : null;
        $periodEnd   = !empty($request->query->get('date_to'))
            ? $request->query->get('date_to') . ' 23:59:59'
            : null;

        // ── Main totals ───────────────────────────────────────────────────────
        $summary = $this->db->fetchAssociative(
            "SELECT SUM(p.amount)    AS total_amount,
                    COUNT(*)         AS total_transactions,
                    MIN(p.created_at) AS date_from,
                    MAX(p.created_at) AS date_to
             FROM   mpesa_payments p $whereSQL",
            $params,
        );

        // ── Distinct customers ────────────────────────────────────────────────
        $totalCustomers = (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT p.msisdn) FROM mpesa_payments p $whereSQL",
            $params,
        );

        // ── New customers (first payment within period) ───────────────────────
        $newCustomers = 0;
        if ($periodStart && $periodEnd) {
            $historySql = 'SELECT msisdn, MIN(created_at) AS first_payment FROM mpesa_payments GROUP BY msisdn';
            $newSql = "
                SELECT COUNT(*) FROM (
                    SELECT s.msisdn
                    FROM   (SELECT DISTINCT p.msisdn FROM mpesa_payments p $whereSQL) s
                    JOIN   ($historySql) h ON h.msisdn = s.msisdn
                    LEFT JOIN mpesa_payments p2
                           ON  p2.msisdn    = s.msisdn
                           AND p2.created_at > h.first_payment
                           AND p2.created_at <= :period_end
                           AND TIMESTAMPDIFF(HOUR, h.first_payment, p2.created_at) >= 24
                    WHERE  h.first_payment BETWEEN :period_start AND :period_end
                      AND  p2.id IS NULL
                ) t";

            $newCustomers = (int) $this->db->fetchOne(
                $newSql,
                array_merge($params, [':period_start' => $periodStart, ':period_end' => $periodEnd]),
            );
        }

        // ── Returning customers ───────────────────────────────────────────────
        $returningCustomers = 0;
        if ($periodStart) {
            $returningSql = "
                SELECT COUNT(DISTINCT p.msisdn)
                FROM   mpesa_payments p
                JOIN   (SELECT msisdn, MIN(created_at) AS first_payment FROM mpesa_payments GROUP BY msisdn) h
                       ON h.msisdn = p.msisdn
                $whereSQL
                AND h.first_payment < :period_start";

            $returningCustomers = (int) $this->db->fetchOne(
                $returningSql,
                array_merge($params, [':period_start' => $periodStart]),
            );
        }

        // ── Gender breakdown ──────────────────────────────────────────────────
        $genderSql = "
            SELECT
                SUM(CASE WHEN gender_norm = 'male'      THEN 1 ELSE 0 END) AS total_males,
                SUM(CASE WHEN gender_norm = 'female'    THEN 1 ELSE 0 END) AS total_females,
                SUM(CASE WHEN gender_norm = 'unchecked' THEN 1 ELSE 0 END) AS unchecked_genders
            FROM (
                SELECT p.msisdn,
                    CASE
                        WHEN LOWER(TRIM(p.gender)) = 'male'   THEN 'male'
                        WHEN LOWER(TRIM(p.gender)) = 'female' THEN 'female'
                        ELSE 'unchecked'
                    END AS gender_norm
                FROM mpesa_payments p
                $whereSQL
                GROUP BY p.msisdn
            ) t";

        $gender     = $this->db->fetchAssociative($genderSql, $params);
        $males      = (int) ($gender['total_males']     ?? 0);
        $females    = (int) ($gender['total_females']   ?? 0);
        $unchecked  = (int) ($gender['unchecked_genders'] ?? 0);
        $knownTotal = $males + $females;

        return $this->json([
            'success' => true,
            'data'    => [
                'total_amount'            => (float) ($summary['total_amount']       ?? 0),
                'total_transactions'      => (int)   ($summary['total_transactions'] ?? 0),
                'date_from'               => $summary['date_from'],
                'date_to'                 => $summary['date_to'],
                'total_customers'         => $totalCustomers,
                'new_customers'           => $newCustomers,
                'returning_customers'     => $returningCustomers,
                'total_males'             => $males,
                'total_females'           => $females,
                'unchecked_genders'       => $unchecked,
                'known_gender_total'      => $knownTotal,
                'male_percentage_known'   => $knownTotal > 0 ? round(($males   / $knownTotal) * 100, 2) : 0,
                'female_percentage_known' => $knownTotal > 0 ? round(($females / $knownTotal) * 100, 2) : 0,
            ],
        ]);
    }

    // =========================================================================
    // STK PUSH
    // =========================================================================

    /**
     * Initiate an STK push request (paybill or till).
     *
     * POST /mpesa/stk/push
     * Body: { type: 'paybill'|'till', phone, amount, account? }
     * Header: Authorization: Bearer <token>
     */
    #[Route('/stk/push', name: 'mpesa_stk_push', methods: ['POST'])]
    public function stkPush(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) return $session;

        $body   = json_decode($request->getContent(), true) ?? [];
        $type   = $body['type'] ?? '';
        $amount = (float) ($body['amount'] ?? 0);
        $account = $body['account'] ?? 'ANGAVU';

        // ── Validate type ─────────────────────────────────────────────────────
        if (!in_array($type, ['till', 'paybill'], true)) {
            return $this->json(['error' => 'Invalid type. Use paybill or till.'], 400);
        }

        // ── Normalize phone ───────────────────────────────────────────────────
        $phone = preg_replace('/\D/', '', $body['phone'] ?? '');
        if (strlen($phone) === 9 && str_starts_with($phone, '7')) {
            $phone = '254' . $phone;
        } elseif (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }

        if (!preg_match('/^254[17]\d{8}$/', $phone)) {
            return $this->json(['error' => 'Invalid phone number.'], 400);
        }

        if ($amount <= 0) {
            return $this->json(['error' => 'Amount must be greater than 0.'], 400);
        }

        // ── MPesa credentials for selected type ───────────────────────────────
        $consumerKey    = $type === 'paybill' ? $this->paybillConsumerKey    : $this->tillConsumerKey;
        $consumerSecret = $type === 'paybill' ? $this->paybillConsumerSecret : $this->tillConsumerSecret;
        $passkey        = $type === 'paybill' ? $this->paybillPasskey        : $this->tillPasskey;
        $shortcode      = $type === 'paybill' ? $this->paybillShortcode      : $this->tillShortcode;

        // ── OAuth token ───────────────────────────────────────────────────────
        $token = $this->fetchMpesaToken($consumerKey, $consumerSecret);
        if (!$token) {
            return $this->json(['error' => 'Failed to fetch MPesa token.'], 500);
        }

        // ── STK push payload ──────────────────────────────────────────────────
        $timestamp = date('YmdHis');
        $password  = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $type === 'paybill' ? 'CustomerPayBillOnline' : 'CustomerBuyGoodsOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $this->callbackUrl,
            'AccountReference'  => $account,
            'TransactionDesc'   => 'Angavu Payment',
        ];

        $ch = curl_init('https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            return $this->json(['error' => 'STK push failed.', 'details' => curl_error($ch)], 500);
        }

        $response = json_decode($raw, true);

        // ── Log to database ───────────────────────────────────────────────────
        $this->db->insert('stk_push_logs', [
            'company_id'          => $session->company->id,
            'channel'             => strtoupper($type),
            'shortcode'           => $shortcode,
            'phone'               => $phone,
            'amount'              => $amount,
            'account_reference'   => $account,
            'checkout_request_id' => $response['CheckoutRequestID']   ?? '',
            'merchant_request_id' => $response['MerchantRequestID']   ?? '',
            'status_code'         => (int) ($response['ResponseCode'] ?? 0),
            'status_description'  => $response['ResponseDescription'] ?? '',
            'initiated_by_user_id'=> $session->user->id,
        ]);

        return $this->json($response);
    }

    /**
     * Poll STK push result by CheckoutRequestID.
     *
     * GET /mpesa/stk/status?checkout_id=xxx
     * Header: Authorization: Bearer <token>
     */
    #[Route('/stk/status', name: 'mpesa_stk_status', methods: ['GET'])]
    public function stkStatus(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) return $session;

        $checkoutId = $request->query->get('checkout_id', '');
        if (!$checkoutId) {
            return $this->json(['error' => 'Missing checkout_id.'], 400);
        }

        $row = $this->db->fetchAssociative(
            'SELECT result_code, result_description, amount, mpesa_receipt, transaction_date
             FROM   stk_push_logs
             WHERE  checkout_request_id = :checkout_id
               AND  company_id          = :company_id
             ORDER  BY id DESC
             LIMIT  1',
            ['checkout_id' => $checkoutId, 'company_id' => $session->company->id],
        );

        if (!$row) {
            return $this->json(['found' => false]);
        }

        $response = [
            'found'              => true,
            'result_code'        => $row['result_code'],
            'result_description' => $row['result_description'],
        ];

        if ((int) $row['result_code'] === 0) {
            $response['amount']           = $row['amount'];
            $response['mpesa_receipt']    = $row['mpesa_receipt'];
            $response['transaction_date'] = $row['transaction_date'];
        }

        return $this->json($response);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Validate session from Authorization header.
     * Returns AuthResult on success or a JsonResponse error on failure.
     */
    private function requireSession(Request $request): AuthResult|JsonResponse
    {
        $token = $this->extractToken($request);
        if (!$token) {
            return $this->unauthorized();
        }

        try {
            return $this->auth->validateSession($token);
        } catch (AuthException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }
    }

    /**
     * Extract Bearer token from Authorization header.
     */
    private function extractToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    /**
     * Determine if the session has admin-level access.
     * Super admin, Owner, Admin, and Manager are considered admin.
     */
    private function isAdminSession(AuthResult $session): bool
    {
        if ($session->user->isSuperAdmin) {
            return true;
        }

        $adminRoles = ['Owner', 'Admin', 'Manager'];
        foreach ($session->user->roles as $role) {
            if (in_array($role, $adminRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a reusable WHERE clause + params array from request query filters.
     * Uses alias `p` for mpesa_payments.
     */
    private function buildWhereClause(Request $request, int $companyId): array
    {
        $where  = ['p.company_id = :company_id'];
        $params = [':company_id' => $companyId];

        $q = $request->query;

        // Date range
        if ($q->get('date_from')) {
            $where[] = 'p.created_at >= :date_from';
            $params[':date_from'] = $q->get('date_from') . ' 00:00:00';
        }
        if ($q->get('date_to')) {
            $where[] = 'p.created_at <= :date_to';
            $params[':date_to'] = $q->get('date_to') . ' 23:59:59';
        }

        // Time of day
        if ($q->get('time_from')) {
            $where[] = 'TIME(p.created_at) >= :time_from';
            $params[':time_from'] = $q->get('time_from');
        }
        if ($q->get('time_to')) {
            $where[] = 'TIME(p.created_at) <= :time_to';
            $params[':time_to'] = $q->get('time_to');
        }

        // Channel type
        $type = $q->get('type', 'all');
        if ($type === 'paybill') {
            $where[] = 'p.short_code = :shortcode';
            $params[':shortcode'] = $this->paybillShortcode;
        } elseif ($type === 'till') {
            $where[] = 'p.short_code = :shortcode';
            $params[':shortcode'] = $this->tillShortcode;
        }

        // Phone (normalized)
        if ($q->get('msisdn')) {
            $msisdn = preg_replace('/\D+/', '', $q->get('msisdn'));
            if (str_starts_with($msisdn, '0')) {
                $msisdn = '254' . substr($msisdn, 1);
            }
            if (preg_match('/^254[17]\d{8}$/', $msisdn)) {
                $where[] = 'p.msisdn LIKE :msisdn';
                $params[':msisdn'] = $msisdn . '%';
            }
        }

        // Other filters
        if ($q->get('transaction_id')) {
            $where[] = 'p.transaction_id LIKE :transaction_id';
            $params[':transaction_id'] = '%' . $q->get('transaction_id') . '%';
        }
        if ($q->get('reference')) {
            $where[] = 'p.reference LIKE :reference';
            $params[':reference'] = '%' . $q->get('reference') . '%';
        }
        if ($q->get('amount')) {
            $where[] = 'p.amount = :amount';
            $params[':amount'] = $q->get('amount');
        }
        if ($q->get('name')) {
            $where[] = '(p.first_name LIKE :name OR p.last_name LIKE :name)';
            $params[':name'] = '%' . $q->get('name') . '%';
        }

        return [$where, $params];
    }

    /**
     * Fetch a Safaricom OAuth access token.
     */
    private function fetchMpesaToken(string $consumerKey, string $consumerSecret): ?string
    {
        $credentials = base64_encode("$consumerKey:$consumerSecret");
        $ch = curl_init('https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Basic $credentials"],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $res = curl_exec($ch);
        if ($res === false) return null;

        $data = json_decode($res, true);
        return $data['access_token'] ?? null;
    }

    private function unauthorized(): JsonResponse
    {
        return $this->json(['success' => false, 'message' => 'Unauthorized. Provide a valid Bearer token.'], 401);
    }
}
