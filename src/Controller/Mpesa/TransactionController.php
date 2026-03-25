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
 * Handles transaction fetching.
 * Replaces: Legacy/mpesa/ajax/fetch_transactions.php
 *
 * Routes:
 *   GET /mpesa/transactions         → fetch transactions (permission-gated)
 *   GET /mpesa/transactions/check   → check STK push status by checkout_request_id
 */
#[Route('/mpesa', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+'])]
class TransactionController extends AbstractController
{
    public function __construct(
        private readonly AuthService           $auth,
        private readonly CheckPermissionService $can,
        private readonly Connection            $db,
    ) {}

    // =========================================================================
    // GET /mpesa/transactions
    // Replaces: fetch_transactions.php
    // Permission: view_transactions
    // Constraints: max_hours_history, max_transactions_visible
    // =========================================================================

    #[Route('/transactions', name: 'mpesa_transactions', methods: ['GET'])]
    public function fetch(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        // ── Permission check ──────────────────────────────────────────────────
        if (!$this->can->check($session, 'view_transactions')) {
            return $this->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        // ── Constraints ───────────────────────────────────────────────────────
        $maxHours        = (int) $this->can->constraint($session, 'view_transactions', 'max_hours_history', 24);
        $maxTransactions = (int) $this->can->constraint($session, 'view_transaction_cards', 'max_transactions_visible', 50);

        // ── Quick search filter ───────────────────────────────────────────────
        $search = trim((string) $request->query->get('q', ''));

        // ── Build query ───────────────────────────────────────────────────────
        $where  = ['mp.company_id = :company_id'];
        $params = ['company_id' => $session->company->id];

        // Apply time constraint
        $where[]              = 'mp.created_at >= NOW() - INTERVAL :hours HOUR';
        $params['hours']      = $maxHours;

        // Quick search (phone, reference, transaction_id, first_name)
        if ($search !== '') {
            $where[]          = '(mp.msisdn LIKE :search OR mp.reference LIKE :search OR mp.transaction_id LIKE :search OR mp.first_name LIKE :search)';
            $params['search'] = "%{$search}%";
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $transactions = $this->db->fetchAllAssociative(
            "SELECT mp.*,
                    cp.spending_segment,
                    cp.loyalty_tier,
                    cp.all_time_spend,
                    cp.average_spend,
                    cp.all_time_transactions AS total_visits
             FROM   mpesa_payments mp
             LEFT JOIN customer_profiles cp ON cp.msisdn = mp.msisdn
             {$whereSQL}
             ORDER  BY mp.created_at DESC
             LIMIT  :limit",
            array_merge($params, ['limit' => $maxTransactions]),
        );

        // ── Mask phone if missing view_full_customer_phone ────────────────────
        $canSeeFullPhone = $this->can->check($session, 'view_full_customer_phone');

        if (!$canSeeFullPhone) {
            foreach ($transactions as &$tx) {
                if (!empty($tx['msisdn'])) {
                    $tx['msisdn'] = $this->maskPhone($tx['msisdn']);
                }
            }
            unset($tx);
        }

        return $this->json($transactions);
    }

    // =========================================================================
    // GET /mpesa/transactions/check?checkout_id=xxx
    // Replaces: stk_status_check.php
    // Permission: send_stk_push
    // =========================================================================

    #[Route('/transactions/check', name: 'mpesa_transaction_check', methods: ['GET'])]
    public function checkStkStatus(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        if (!$this->can->check($session, 'send_stk_push')) {
            return $this->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        $checkoutId = trim((string) $request->query->get('checkout_id', ''));

        if ($checkoutId === '') {
            return $this->json(['success' => false, 'message' => 'checkout_id is required.'], 400);
        }

        $row = $this->db->fetchAssociative(
            'SELECT result_code, result_description, amount, mpesa_receipt, transaction_date
             FROM   stk_push_logs
             WHERE  checkout_request_id = :id
               AND  company_id = :company_id
             ORDER  BY id DESC
             LIMIT  1',
            ['id' => $checkoutId, 'company_id' => $session->company->id],
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
    // PRIVATE
    // =========================================================================

    private function requireSession(Request $request): mixed
    {
        $token = $this->resolveToken($request);

        if (!$token) {
            return $this->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        try {
            return $this->auth->validateSession($token);
        } catch (AuthException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
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

    /**
     * Mask phone: 254796763792 → 2547967***92
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 9) {
            return $phone;
        }

        return substr($phone, 0, 7) . '***' . substr($phone, -2);
    }
}
