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
 * Handles advanced search and analytics summary.
 * Replaces: Legacy/mpesa/ajax/advanced_search.php
 *           Legacy/mpesa/ajax/transaction_summary.php
 *
 * Routes:
 *   GET /mpesa/search          → advanced search with filters
 *   GET /mpesa/search/summary  → analytics summary for a search result set
 */
#[Route('/mpesa')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly AuthService            $auth,
        private readonly CheckPermissionService $can,
        private readonly Connection             $db,
    ) {}

    // =========================================================================
    // GET /mpesa/search
    // Replaces: advanced_search.php
    // Permission: access_advanced_search
    // Constraints: per-filter permissions, max_hours_history
    // =========================================================================

    #[Route('/search', name: 'mpesa_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        if (!$this->can->check($session, 'access_advanced_search')) {
            return $this->json(['success' => false, 'message' => 'Access denied to advanced search.'], 403);
        }

        $q = $request->query;

        $where  = ['mp.company_id = :company_id'];
        $params = ['company_id' => $session->company->id];

        $fromDateTime = null;

        // ── Date range ────────────────────────────────────────────────────────
        if ($this->can->check($session, 'search_by_date_range')) {
            $dateFrom  = $q->get('date_from');
            $dateTo    = $q->get('date_to');
            $timeFrom  = preg_match('/^\d{2}:\d{2}$/', (string) $q->get('time_from', '')) ? $q->get('time_from') : '00:00';
            $timeTo    = preg_match('/^\d{2}:\d{2}$/', (string) $q->get('time_to', ''))   ? $q->get('time_to')   : '23:59';

            if ($dateFrom && $dateTo) {
                $fromDateTime           = "{$dateFrom} {$timeFrom}:00";
                $toDateTime             = "{$dateTo} {$timeTo}:59";
                $where[]                = 'mp.created_at BETWEEN :from_datetime AND :to_datetime';
                $params['from_datetime'] = $fromDateTime;
                $params['to_datetime']   = $toDateTime;
            }
        }

        // ── Phone ─────────────────────────────────────────────────────────────
        if ($this->can->check($session, 'search_by_phone') && $q->get('msisdn')) {
            $msisdn = preg_replace('/\D/', '', (string) $q->get('msisdn'));
            if (str_starts_with($msisdn, '0')) {
                $msisdn = '254' . substr($msisdn, 1);
            }
            if ($msisdn !== '') {
                $where[]          = 'mp.msisdn LIKE :msisdn';
                $params['msisdn'] = "%{$msisdn}%";
            }
        }

        // ── Shortcode ─────────────────────────────────────────────────────────
        if ($this->can->check($session, 'search_by_shortcode') && $q->get('type')) {
            $allowedShortcodes = $this->can->constraint($session, 'send_stk_push', 'allowed_shortcodes', '');
            $type              = $q->get('type');

            if ($type === 'paybill') {
                $where[] = 'mp.short_code = 5548218';
            } elseif ($type === 'till') {
                $where[] = 'mp.short_code = 5548220';
            } elseif ($type !== 'all' && $allowedShortcodes !== '') {
                // Restrict to allowed shortcodes if constrained
                $codes             = array_map('trim', explode(',', $allowedShortcodes));
                $placeholders      = implode(',', array_map(fn($i) => ":sc{$i}", array_keys($codes)));
                $where[]           = "mp.short_code IN ({$placeholders})";
                foreach ($codes as $i => $code) {
                    $params["sc{$i}"] = $code;
                }
            }
        }

        // ── Transaction ID ────────────────────────────────────────────────────
        if ($this->can->check($session, 'search_by_transaction_id') && $q->get('transaction_id')) {
            $where[]                    = 'mp.transaction_id LIKE :transaction_id';
            $params['transaction_id']   = '%' . $q->get('transaction_id') . '%';
        }

        // ── Reference ─────────────────────────────────────────────────────────
        if ($this->can->check($session, 'search_by_reference') && $q->get('reference')) {
            $where[]              = 'mp.reference LIKE :reference';
            $params['reference']  = '%' . $q->get('reference') . '%';
        }

        // ── Amount ────────────────────────────────────────────────────────────
        if ($this->can->check($session, 'search_by_amount') && $q->get('amount')) {
            $where[]           = 'mp.amount = :amount';
            $params['amount']  = (float) $q->get('amount');
        }

        // ── Name (no separate permission — part of access_advanced_search) ───
        if ($q->get('name')) {
            $where[]         = '(mp.first_name LIKE :name OR mp.middle_name LIKE :name OR mp.last_name LIKE :name)';
            $params['name']  = '%' . $q->get('name') . '%';
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

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
            LEFT JOIN customer_profiles cp ON cp.msisdn = mp.msisdn
            LEFT JOIN (
                SELECT msisdn, MIN(created_at) AS first_payment
                FROM mpesa_payments
                GROUP BY msisdn
            ) fp ON fp.msisdn = mp.msisdn
            {$whereSQL}
            ORDER BY mp.created_at DESC
            LIMIT 500
        ";

        $params['search_start'] = $fromDateTime ?? '1970-01-01 00:00:00';

        $results = $this->db->fetchAllAssociative($sql, $params);

        // ── Mask phone if no full phone permission ────────────────────────────
        if (!$this->can->check($session, 'view_full_customer_phone')) {
            foreach ($results as &$row) {
                if (!empty($row['msisdn'])) {
                    $row['msisdn'] = substr($row['msisdn'], 0, 7) . '***' . substr($row['msisdn'], -2);
                }
            }
            unset($row);
        }

        if ($results) {
            return $this->json(['success' => true, 'message' => 'Found ' . count($results) . ' transactions', 'data' => $results]);
        }

        return $this->json(['success' => false, 'message' => 'No transactions found.', 'data' => []]);
    }

    // =========================================================================
    // GET /mpesa/search/summary
    // Replaces: transaction_summary.php
    // Permission: view_search_summary
    // Sub-permissions: view_total_amount, view_total_transactions, etc.
    // =========================================================================

    #[Route('/search/summary', name: 'mpesa_search_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        if (!$this->can->check($session, 'view_search_summary')) {
            return $this->json(['success' => false, 'message' => 'Access denied to search summary.'], 403);
        }

        $q = $request->query;

        // ── Build same WHERE as search ────────────────────────────────────────
        $where  = ['p.company_id = :company_id'];
        $params = ['company_id' => $session->company->id];

        $periodStart = null;
        $periodEnd   = null;

        if ($q->get('date_from')) {
            $periodStart              = $q->get('date_from') . ' 00:00:00';
            $where[]                  = 'p.created_at >= :date_from';
            $params['date_from']      = $periodStart;
        }

        if ($q->get('date_to')) {
            $periodEnd              = $q->get('date_to') . ' 23:59:59';
            $where[]                = 'p.created_at <= :date_to';
            $params['date_to']      = $periodEnd;
        }

        if ($q->get('time_from')) {
            $where[]               = 'TIME(p.created_at) >= :time_from';
            $params['time_from']   = $q->get('time_from');
        }

        if ($q->get('time_to')) {
            $where[]             = 'TIME(p.created_at) <= :time_to';
            $params['time_to']   = $q->get('time_to');
        }

        if ($q->get('type') === 'paybill') {
            $where[] = 'p.short_code = 5548218';
        } elseif ($q->get('type') === 'till') {
            $where[] = 'p.short_code = 5548220';
        }

        if ($q->get('msisdn')) {
            $msisdn = preg_replace('/\D/', '', (string) $q->get('msisdn'));
            if (str_starts_with($msisdn, '0')) {
                $msisdn = '254' . substr($msisdn, 1);
            }
            $where[]          = 'p.msisdn LIKE :msisdn';
            $params['msisdn'] = $msisdn . '%';
        }

        if ($q->get('transaction_id')) {
            $where[]                  = 'p.transaction_id LIKE :transaction_id';
            $params['transaction_id'] = '%' . $q->get('transaction_id') . '%';
        }

        if ($q->get('reference')) {
            $where[]             = 'p.reference LIKE :reference';
            $params['reference'] = '%' . $q->get('reference') . '%';
        }

        if ($q->get('amount')) {
            $where[]          = 'p.amount = :amount';
            $params['amount'] = (float) $q->get('amount');
        }

        if ($q->get('name')) {
            $where[]         = '(p.first_name LIKE :name OR p.last_name LIKE :name)';
            $params['name']  = '%' . $q->get('name') . '%';
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $data = [];

        // ── Total amount ──────────────────────────────────────────────────────
        if ($this->can->check($session, 'view_total_amount')) {
            $row              = $this->db->fetchAssociative("SELECT SUM(p.amount) AS total_amount, MIN(p.created_at) AS date_from, MAX(p.created_at) AS date_to FROM mpesa_payments p {$whereSQL}", $params);
            $data['total_amount'] = (float) ($row['total_amount'] ?? 0);
            $data['date_from']    = $row['date_from'];
            $data['date_to']      = $row['date_to'];
        }

        // ── Total transactions ────────────────────────────────────────────────
        if ($this->can->check($session, 'view_total_transactions')) {
            $data['total_transactions'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mpesa_payments p {$whereSQL}", $params);
        }

        // ── Total customers ───────────────────────────────────────────────────
        if ($this->can->check($session, 'view_total_customers')) {
            $data['total_customers'] = (int) $this->db->fetchOne("SELECT COUNT(DISTINCT p.msisdn) FROM mpesa_payments p {$whereSQL}", $params);
        }

        // ── New customers ─────────────────────────────────────────────────────
        if ($this->can->check($session, 'view_new_customers') && $periodStart && $periodEnd) {
            $newSql = "
                SELECT COUNT(*) FROM (
                    SELECT s.msisdn
                    FROM (SELECT DISTINCT p.msisdn FROM mpesa_payments p {$whereSQL}) s
                    JOIN (SELECT msisdn, MIN(created_at) AS first_payment FROM mpesa_payments GROUP BY msisdn) h ON h.msisdn = s.msisdn
                    WHERE h.first_payment BETWEEN :period_start AND :period_end
                ) t
            ";
            $data['new_customers'] = (int) $this->db->fetchOne($newSql, array_merge($params, ['period_start' => $periodStart, 'period_end' => $periodEnd]));
        }

        // ── Returning customers ───────────────────────────────────────────────
        if ($this->can->check($session, 'view_returning_customers') && $periodStart) {
            $returnSql = "
                SELECT COUNT(DISTINCT p.msisdn)
                FROM mpesa_payments p
                JOIN (SELECT msisdn, MIN(created_at) AS first_payment FROM mpesa_payments GROUP BY msisdn) h ON h.msisdn = p.msisdn
                {$whereSQL}
                AND h.first_payment < :period_start
            ";
            $data['returning_customers'] = (int) $this->db->fetchOne($returnSql, array_merge($params, ['period_start' => $periodStart]));
        }

        // ── Gender breakdown ──────────────────────────────────────────────────
        if ($this->can->check($session, 'view_gender_breakdown')) {
            $genderSql = "
                SELECT
                    SUM(CASE WHEN gender_norm = 'male'   THEN 1 ELSE 0 END) AS total_males,
                    SUM(CASE WHEN gender_norm = 'female' THEN 1 ELSE 0 END) AS total_females,
                    SUM(CASE WHEN gender_norm = 'unchecked' THEN 1 ELSE 0 END) AS unchecked_genders
                FROM (
                    SELECT p.msisdn,
                        CASE
                            WHEN LOWER(TRIM(p.gender)) = 'male'   THEN 'male'
                            WHEN LOWER(TRIM(p.gender)) = 'female' THEN 'female'
                            ELSE 'unchecked'
                        END AS gender_norm
                    FROM mpesa_payments p
                    {$whereSQL}
                    GROUP BY p.msisdn
                ) t
            ";
            $gender = $this->db->fetchAssociative($genderSql, $params);

            $males   = (int) ($gender['total_males']   ?? 0);
            $females = (int) ($gender['total_females'] ?? 0);
            $known   = $males + $females;

            $data['total_males']             = $males;
            $data['total_females']           = $females;
            $data['unchecked_genders']       = (int) ($gender['unchecked_genders'] ?? 0);
            $data['known_gender_total']      = $known;
            $data['male_percentage_known']   = $known > 0 ? round(($males / $known) * 100, 2) : 0;
            $data['female_percentage_known'] = $known > 0 ? round(($females / $known) * 100, 2) : 0;
        }

        return $this->json(['success' => true, 'data' => $data]);
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
}
