<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Feature\TenantFeatureAccessService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin panel — Loyalty module management.
 *
 * All routes are gated by:
 *   1. requireAdmin($request, $permission) — standard session + tenant permission guard
 *   2. isLoyaltyAccessible() — feature flag + module toggle check
 *
 * Platform admins bypass tenant permission checks in requireAdmin() via the
 * !isSuperAdmin shortcut, but two actions carry an extra explicit platform check:
 *   - adjustPoints  → requires PERFORM_COMPANY_SUPPORT_ACTIONS or MANAGE_COMPANY_LOYALTY
 *   - toggleModule  → handled in SettingsController under EDIT_COMPANY_SETTINGS
 *
 * Query bodies for list/paginated/aggregation methods are implemented by Codex.
 * See docs/loyalty-module.md §8 for the division of work.
 */
#[Route('/{branch}/dashboard/loyalty', host: '{subdomain}.{domain}', requirements: [
    'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
    'domain'    => '.+',
    'branch'    => '[A-Za-z0-9-]+',
])]
class LoyaltyController extends AdminBaseController
{
    public function __construct(
        AuthService $auth,
        CheckPermissionService $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService $branchResolver,
        Connection $db,
        private readonly LoyaltyService $loyalty,
        private readonly TenantFeatureAccessService $features,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // OVERVIEW
    // =========================================================================

    #[Route('', name: 'admin_loyalty_overview', methods: ['GET'])]
    public function overview(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'VIEW_LOYALTY_PROGRAM');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        $companyId = $session->company->id;
        $branchId  = $session->branch?->id;
        $program   = $this->loyalty->getProgram($companyId, $branchId);

        $stats     = $this->fetchOverviewStats($companyId, $branchId, $program);
        $signals   = $this->fetchOverviewSignals($companyId, $program);

        return $this->render('admin/loyalty/overview.html.twig', [
            'session'  => $session,
            'program'  => $program,
            'stats'    => $stats,
            'signals'  => $signals,
            'can'      => [
                'edit_program'    => $this->canDo($session, 'EDIT_LOYALTY_PROGRAM'),
                'view_members'    => $this->canDo($session, 'VIEW_LOYALTY_MEMBERS'),
                'view_tiers'      => $this->canDo($session, 'VIEW_LOYALTY_TIERS'),
                'view_ledger'     => $this->canDo($session, 'VIEW_LOYALTY_LEDGER'),
                'view_reports'    => $this->canDo($session, 'VIEW_LOYALTY_REPORTS'),
                'view_segments'   => $this->canDo($session, 'VIEW_LOYALTY_SEGMENTS'),
                'send_messages'   => $this->canDo($session, 'SEND_LOYALTY_MESSAGES'),
            ],
        ]);
    }

    // =========================================================================
    // TIERS
    // =========================================================================

    #[Route('/tiers', name: 'admin_loyalty_tiers', methods: ['GET'])]
    public function tiers(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'VIEW_LOYALTY_TIERS');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        $companyId = $session->company->id;
        $program   = $this->loyalty->getProgram($companyId, $session->branch?->id);

        $tiers = $program ? $this->db->fetchAllAssociative(
            'SELECT lt.*, COUNT(la.id) AS member_count
               FROM loyalty_tiers lt
          LEFT JOIN loyalty_accounts la ON la.loyalty_tier_id = lt.id
                                       AND la.company_id = lt.company_id
              WHERE lt.loyalty_program_id = :program_id
           GROUP BY lt.id
           ORDER BY lt.sort_order ASC, lt.min_points ASC',
            ['program_id' => $program['id']],
        ) : [];

        return $this->render('admin/loyalty/tiers.html.twig', [
            'session' => $session,
            'program' => $program,
            'tiers'   => $tiers,
            'can'     => [
                'manage' => $this->canDo($session, 'MANAGE_LOYALTY_TIERS'),
            ],
        ]);
    }

    #[Route('/tiers', name: 'admin_loyalty_tiers_create', methods: ['POST'])]
    public function tiersCreate(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_TIERS');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        // ── Codex implements: extract fields, validate, insert tier ──────────
        return $this->handleTierCreate($request, $session);
    }

    #[Route('/tiers/{id}/update', name: 'admin_loyalty_tiers_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function tiersUpdate(Request $request, int $id): JsonResponse
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_TIERS');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        // ── Codex implements: validate ownership, update tier ────────────────
        return $this->handleTierUpdate($request, $session, $id);
    }

    #[Route('/tiers/{id}/delete', name: 'admin_loyalty_tiers_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function tiersDelete(Request $request, int $id): JsonResponse
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_TIERS');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        // ── Codex implements: block if members on tier, soft-protect entry tier, delete ──
        return $this->handleTierDelete($request, $session, $id);
    }

    #[Route('/tiers/reorder', name: 'admin_loyalty_tiers_reorder', methods: ['POST'])]
    public function tiersReorder(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_TIERS');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        // ── Codex implements: accept ordered id[] array, update sort_order ───
        return $this->handleTierReorder($request, $session);
    }

    // =========================================================================
    // MEMBERS
    // =========================================================================

    #[Route('/members', name: 'admin_loyalty_members', methods: ['GET'])]
    public function members(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'VIEW_LOYALTY_MEMBERS');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        $companyId = $session->company->id;
        $branchId  = $session->branch?->id;
        $program   = $this->loyalty->getProgram($companyId, $branchId);

        // Filters from query string
        $search      = trim((string) $request->query->get('q', ''));
        $tierId      = (int) $request->query->get('tier', 0) ?: null;
        $page        = max(1, (int) $request->query->get('page', 1));
        $perPage     = 30;

        // Tiers for filter dropdown
        $tiers = $program ? $this->db->fetchAllAssociative(
            'SELECT id, name, color FROM loyalty_tiers WHERE loyalty_program_id = :p ORDER BY sort_order ASC',
            ['p' => $program['id']],
        ) : [];

        // ── Codex implements: members paginated query ────────────────────────
        [$members, $total] = $this->fetchMembersList($companyId, $branchId, $program, $search, $tierId, $page, $perPage);

        return $this->render('admin/loyalty/members.html.twig', [
            'session'  => $session,
            'program'  => $program,
            'members'  => $members,
            'tiers'    => $tiers,
            'search'   => $search,
            'tier_id'  => $tierId,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'can'      => [
                'view_detail'    => $this->canDo($session, 'VIEW_LOYALTY_MEMBER_DETAIL'),
                'enroll'         => $this->canDo($session, 'ENROLL_LOYALTY_MEMBER'),
                'adjust'         => $this->canDo($session, 'ADJUST_LOYALTY_POINTS'),
                'export'         => $this->canDo($session, 'EXPORT_LOYALTY_DATA'),
                'view_full_phone'=> $this->canDo($session, 'VIEW_FULL_CUSTOMER_PHONE'),
            ],
        ]);
    }

    #[Route('/members/export', name: 'admin_loyalty_members_export', methods: ['GET'])]
    public function membersExport(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'EXPORT_LOYALTY_DATA');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        // ── Codex implements: same filters as list, stream CSV ───────────────
        return $this->streamMembersCsv($request, $session);
    }

    #[Route('/members/enroll', name: 'admin_loyalty_member_enroll', methods: ['POST'])]
    public function memberEnroll(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'ENROLL_LOYALTY_MEMBER');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        // ── Codex implements: validate phone, call loyalty->findOrEnroll ─────
        return $this->handleMemberEnroll($request, $session);
    }

    #[Route('/members/{id}', name: 'admin_loyalty_member_profile', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function memberProfile(Request $request, int $id): Response
    {
        $session = $this->requireAdmin($request, 'VIEW_LOYALTY_MEMBER_DETAIL');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        $companyId = $session->company->id;

        $account = $this->db->fetchAssociative(
            'SELECT la.*,
                    c.first_name, c.msisdn AS phone, c.gender, c.created_at AS customer_since,
                    lp.program_name, lp.points_name, lp.points_symbol,
                    lp.kes_per_point, lp.redemption_enabled,
                    lt.name AS tier_name, lt.color AS tier_color,
                    nt.name AS next_tier_name, nt.min_points AS next_tier_min_points
               FROM loyalty_accounts la
               JOIN loyalty_programs lp ON lp.id = la.loyalty_program_id
               JOIN customers c         ON c.id  = la.customer_id
          LEFT JOIN loyalty_tiers lt    ON lt.id = la.loyalty_tier_id
          LEFT JOIN loyalty_tiers nt    ON nt.loyalty_program_id = lp.id
                                       AND nt.min_points > la.points_balance
              WHERE la.id         = :id
                AND la.company_id = :company_id
           ORDER BY nt.min_points ASC
              LIMIT 1',
            ['id' => $id, 'company_id' => $companyId],
        );

        if (!$account) {
            throw $this->createNotFoundException('Loyalty account not found.');
        }

        $ledgerPage = max(1, (int) $request->query->get('page', 1));
        $ledgerPerPage = 20;

        // ── Codex implements: paginated ledger query for this account ────────
        [$ledger, $ledgerTotal] = $this->fetchMemberLedger($id, $companyId, $ledgerPage, $ledgerPerPage);

        return $this->render('admin/loyalty/member-profile.html.twig', [
            'session'         => $session,
            'account'         => $account,
            'ledger'          => $ledger,
            'ledger_page'     => $ledgerPage,
            'ledger_per_page' => $ledgerPerPage,
            'ledger_total'    => $ledgerTotal,
            'can'             => [
                'adjust'         => $this->canDo($session, 'ADJUST_LOYALTY_POINTS'),
                'view_full_phone'=> $this->canDo($session, 'VIEW_FULL_CUSTOMER_PHONE'),
            ],
        ]);
    }

    #[Route('/members/{id}/adjust', name: 'admin_loyalty_member_adjust', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function memberAdjust(Request $request, int $id): JsonResponse
    {
        $session = $this->requireAdmin($request, 'ADJUST_LOYALTY_POINTS');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        // Explicit platform-level gate for point adjustments (sensitive write)
        if ($session->user->isSuperAdmin) {
            if (!$this->platformCan->check($session, 'adjust_loyalty_points')) {
                return $this->error('You do not have permission to adjust loyalty points for this company.', 403);
            }
        }

        // ── Codex implements: validate note+amount, call DB update + writeLedger ──
        return $this->handlePointAdjust($request, $session, $id);
    }

    // =========================================================================
    // LEDGER
    // =========================================================================

    #[Route('/ledger', name: 'admin_loyalty_ledger', methods: ['GET'])]
    public function ledger(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'VIEW_LOYALTY_LEDGER');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        $companyId = $session->company->id;
        $branchId  = $session->branch?->id;

        $typeFilter  = $request->query->get('type', '');
        $search      = trim((string) $request->query->get('q', ''));
        $dateFrom    = trim((string) $request->query->get('from', ''));
        $dateTo      = trim((string) $request->query->get('to', ''));
        $page        = max(1, (int) $request->query->get('page', 1));
        $perPage     = 40;

        // ── Codex implements: paginated ledger query with filters ────────────
        [$entries, $total] = $this->fetchLedger($companyId, $branchId, $typeFilter, $search, $dateFrom, $dateTo, $page, $perPage);

        return $this->render('admin/loyalty/ledger.html.twig', [
            'session'   => $session,
            'entries'   => $entries,
            'type'      => $typeFilter,
            'search'    => $search,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'can'       => [
                'export'         => $this->canDo($session, 'EXPORT_LOYALTY_DATA'),
                'view_full_phone'=> $this->canDo($session, 'VIEW_FULL_CUSTOMER_PHONE'),
            ],
        ]);
    }

    #[Route('/ledger/export', name: 'admin_loyalty_ledger_export', methods: ['GET'])]
    public function ledgerExport(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'EXPORT_LOYALTY_DATA');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        // ── Codex implements: same filters as ledger, stream CSV ─────────────
        return $this->streamLedgerCsv($request, $session);
    }

    // =========================================================================
    // MULTIPLIERS
    // =========================================================================

    #[Route('/multipliers', name: 'admin_loyalty_multipliers', methods: ['GET'])]
    public function multipliers(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_MULTIPLIERS');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        $companyId = $session->company->id;
        $branchId  = $session->branch?->id;
        $program   = $this->loyalty->getProgram($companyId, $branchId);

        // Guard: multipliers table may not exist yet (segment 42)
        $multipliers = [];
        if ($program) {
            try {
                $multipliers = $this->db->fetchAllAssociative(
                    'SELECT * FROM loyalty_point_multipliers
                      WHERE loyalty_program_id = :p
                      ORDER BY is_active DESC, created_at DESC',
                    ['p' => $program['id']],
                );
            } catch (\Throwable) {
                // Table not yet created — show empty state
            }
        }

        return $this->render('admin/loyalty/multipliers.html.twig', [
            'session'     => $session,
            'program'     => $program,
            'multipliers' => $multipliers,
        ]);
    }

    #[Route('/multipliers', name: 'admin_loyalty_multipliers_create', methods: ['POST'])]
    public function multipliersCreate(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_MULTIPLIERS');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        // ── Codex implements: validate, insert multiplier row ────────────────
        return $this->handleMultiplierCreate($request, $session);
    }

    #[Route('/multipliers/{id}/update', name: 'admin_loyalty_multipliers_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function multipliersUpdate(Request $request, int $id): JsonResponse
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_MULTIPLIERS');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        // ── Codex implements: validate ownership, update multiplier ──────────
        return $this->handleMultiplierUpdate($request, $session, $id);
    }

    #[Route('/multipliers/{id}/toggle', name: 'admin_loyalty_multipliers_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function multipliersToggle(Request $request, int $id): JsonResponse
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_MULTIPLIERS');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        // ── Codex implements: flip is_active ─────────────────────────────────
        return $this->handleMultiplierToggle($request, $session, $id);
    }

    #[Route('/multipliers/{id}/delete', name: 'admin_loyalty_multipliers_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function multipliersDelete(Request $request, int $id): JsonResponse
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_MULTIPLIERS');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        // ── Codex implements: verify ownership, delete row ───────────────────
        return $this->handleMultiplierDelete($request, $session, $id);
    }

    // =========================================================================
    // REPORTS
    // =========================================================================

    #[Route('/reports', name: 'admin_loyalty_reports', methods: ['GET'])]
    public function reports(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'VIEW_LOYALTY_REPORTS');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        $companyId = $session->company->id;
        $branchId  = $session->branch?->id;
        $program   = $this->loyalty->getProgram($companyId, $branchId);

        $period = $request->query->get('period', '30');
        $validPeriods = ['7', '30', '90'];
        if (!in_array($period, $validPeriods, true)) {
            $period = '30';
        }

        $tab = $request->query->get('tab', 'programme');
        $validTabs = ['programme', 'retention', 'revenue', 'automations'];
        if (!in_array($tab, $validTabs, true)) {
            $tab = 'programme';
        }

        $data = $this->fetchReportsData($companyId, $branchId, $program, (int) $period);

        return $this->render('admin/loyalty/reports.html.twig', [
            'session' => $session,
            'program' => $program,
            'period'  => $period,
            'tab'     => $tab,
            'data'    => $data,
        ]);
    }

    // =========================================================================
    // AUTOMATIONS
    // =========================================================================

    #[Route('/automations', name: 'admin_loyalty_automations', methods: ['GET', 'POST'])]
    public function automations(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'MANAGE_LOYALTY_AUTOMATIONS');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        $companyId = $session->company->id;
        $branchId  = $session->branch?->id;
        $program   = $this->loyalty->getProgram($companyId, $branchId);

        if (!$program) {
            return $this->render('admin/loyalty/automations.html.twig', [
                'session'     => $session,
                'program'     => null,
                'automations' => [],
            ]);
        }

        $pid = (int) $program['id'];

        if ($request->isMethod('POST')) {
            return $this->handleAutomationSave($request, $session, $pid, $companyId);
        }

        // Load existing automation configs keyed by trigger_type
        $rows = [];
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM loyalty_automations WHERE loyalty_program_id = :pid',
                ['pid' => $pid],
            );
        } catch (\Throwable) {}

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['trigger_type']] = $row;
        }

        return $this->render('admin/loyalty/automations.html.twig', [
            'session'     => $session,
            'program'     => $program,
            'automations' => $byType,
        ]);
    }

    // =========================================================================
    // SEGMENTS
    // =========================================================================

    #[Route('/segments', name: 'admin_loyalty_segments', methods: ['GET'])]
    public function segments(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'VIEW_LOYALTY_SEGMENTS');
        if ($session instanceof Response) return $session;

        if ($r = $this->requireLoyaltyAccess($request, $session)) return $r;

        $companyId = $session->company->id;
        $branchId  = $session->branch?->id;
        $program   = $this->loyalty->getProgram($companyId, $branchId);

        $segments = $program ? $this->fetchSegmentCounts($companyId, $program) : [];

        return $this->render('admin/loyalty/segments.html.twig', [
            'session'  => $session,
            'program'  => $program,
            'segments' => $segments,
            'can'      => [
                'send_messages'       => $this->canDo($session, 'SEND_LOYALTY_MESSAGES'),
                'manage_automations'  => $this->canDo($session, 'MANAGE_LOYALTY_AUTOMATIONS'),
                'view_members'        => $this->canDo($session, 'VIEW_LOYALTY_MEMBERS'),
            ],
        ]);
    }

    #[Route('/segments/send', name: 'admin_loyalty_segments_send', methods: ['POST'])]
    public function segmentsSend(Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'SEND_LOYALTY_MESSAGES');
        if ($session instanceof Response) return $this->errorFromResponse($session);

        if ($r = $this->requireLoyaltyAccess($request, $session)) {
            return $this->json(['success' => false, 'message' => 'Loyalty not available.'], 403);
        }

        $companyId = $session->company->id;
        $program   = $this->loyalty->getProgram($companyId, $session->branch?->id);

        if (!$program) {
            return $this->json(['success' => false, 'message' => 'No loyalty programme configured.'], 422);
        }

        $segment     = trim((string) $request->request->get('segment', ''));
        $triggerType = trim((string) $request->request->get('trigger_type', ''));
        $message     = trim((string) $request->request->get('message', ''));

        if ($message === '') {
            return $this->json(['success' => false, 'message' => 'Message is required.'], 422);
        }
        if (mb_strlen($message) > 320) {
            return $this->json(['success' => false, 'message' => 'Message must be 320 characters or fewer.'], 422);
        }

        // Resolve the account IDs for this segment
        $accountIds = $this->resolveSegmentAccountIds($companyId, $program, $segment);

        if (empty($accountIds)) {
            return $this->json(['success' => false, 'message' => 'No members in this segment.'], 422);
        }

        // Queue a notification for each member, personalising {first_name}
        $sent = 0;
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($accountIds as $accountId) {
            try {
                $member = $this->db->fetchAssociative(
                    'SELECT la.id, c.first_name FROM loyalty_accounts la
                       JOIN customers c ON c.id = la.customer_id
                      WHERE la.id = :id AND la.company_id = :cid LIMIT 1',
                    ['id' => $accountId, 'cid' => $companyId],
                );
                if (!$member) continue;

                $personalised = str_replace('{first_name}', $member['first_name'] ?? '', $message);

                $this->db->insert('loyalty_notifications', [
                    'company_id'         => $companyId,
                    'loyalty_account_id' => $accountId,
                    'trigger_type'       => $triggerType ?: 'manual_campaign',
                    'channel'            => 'sms',
                    'message_text'       => $personalised,
                    'sent_at'            => $now,
                ]);
                $sent++;
            } catch (\Throwable) {
                // Continue processing remaining members
            }
        }

        return $this->json([
            'success' => true,
            'message' => "Message queued for {$sent} member(s).",
            'sent'    => $sent,
        ]);
    }

    // =========================================================================
    // PRIVATE — GUARDS & HELPERS
    // =========================================================================

    /**
     * Require that loyalty is accessible for the current company.
     * Returns a Response (redirect/403) to deny access, or null to allow.
     */
    private function requireLoyaltyAccess(Request $request, object $session): ?Response
    {
        $companyId = $session->company->id;

        $hasFeature = $this->features->canAny(
            $companyId,
            TenantFeatureAccessService::FEATURE_EARN_POINTS,
            TenantFeatureAccessService::FEATURE_REDEEM_POINTS,
            TenantFeatureAccessService::FEATURE_REWARD_SETUP,
            TenantFeatureAccessService::FEATURE_LOYALTY_BALANCE,
        );

        if (!$hasFeature) {
            if ($request->isXmlHttpRequest() || str_contains($request->headers->get('Accept', ''), 'application/json')) {
                return $this->json(['success' => false, 'message' => 'Loyalty is not available on your plan.'], 403);
            }
            return $this->redirectToRoute('app_dashboard', [
                'subdomain' => (string) $request->attributes->get('subdomain', ''),
                'domain'    => (string) $request->attributes->get('domain', ''),
                'branch'    => (string) $request->attributes->get('branch', ''),
            ]);
        }

        return null;
    }

    /**
     * Check a permission for both platform and tenant sessions.
     * For platform admins, delegates via APP_PERMISSION_MAP in PlatformCheckPermissionService.
     * For tenant users, uses CheckPermissionService directly.
     */
    private function canDo(object $session, string $permission): bool
    {
        return $this->can->check($session, $permission);
    }

    /**
     * Convert a Response (from requireAdmin failure) into a JsonResponse error
     * for fetch endpoints. Preserves the status code.
     */
    private function errorFromResponse(Response $response): JsonResponse
    {
        $status = $response->getStatusCode();
        $msg = match ($status) {
            401     => 'Authentication required.',
            403     => 'You do not have permission to perform this action.',
            default => 'An error occurred.',
        };
        return $this->json(['success' => false, 'message' => $msg], $status);
    }

    // =========================================================================
    // PRIVATE — STUBS FOR CODEX TO IMPLEMENT
    // =========================================================================

    // =========================================================================
    // PRIVATE — QUERY IMPLEMENTATIONS
    // =========================================================================

    private function fetchOverviewStats(int $companyId, ?int $branchId, ?array $program): array
    {
        if ($program === null) {
            return ['total_members' => 0, 'points_this_month' => 0.0, 'points_last_month' => 0.0, 'redeemed_this_month' => 0.0, 'tier_counts' => []];
        }

        $pid = (int) $program['id'];

        $totalMembers = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM loyalty_accounts WHERE loyalty_program_id = :pid AND company_id = :cid',
            ['pid' => $pid, 'cid' => $companyId],
        );

        $pointsThisMonth = (float) ($this->db->fetchOne(
            "SELECT COALESCE(SUM(ll.points), 0)
               FROM loyalty_ledger ll
               JOIN loyalty_accounts la ON la.id = ll.loyalty_account_id
              WHERE la.loyalty_program_id = :pid AND ll.company_id = :cid AND ll.type = 'earn'
                AND YEAR(ll.created_at) = YEAR(NOW()) AND MONTH(ll.created_at) = MONTH(NOW())",
            ['pid' => $pid, 'cid' => $companyId],
        ) ?? 0.0);

        $pointsLastMonth = (float) ($this->db->fetchOne(
            "SELECT COALESCE(SUM(ll.points), 0)
               FROM loyalty_ledger ll
               JOIN loyalty_accounts la ON la.id = ll.loyalty_account_id
              WHERE la.loyalty_program_id = :pid AND ll.company_id = :cid AND ll.type = 'earn'
                AND YEAR(ll.created_at)  = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))
                AND MONTH(ll.created_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))",
            ['pid' => $pid, 'cid' => $companyId],
        ) ?? 0.0);

        $redeemedThisMonth = (float) abs((float) ($this->db->fetchOne(
            "SELECT COALESCE(SUM(ll.points), 0)
               FROM loyalty_ledger ll
               JOIN loyalty_accounts la ON la.id = ll.loyalty_account_id
              WHERE la.loyalty_program_id = :pid AND ll.company_id = :cid AND ll.type = 'redeem'
                AND YEAR(ll.created_at) = YEAR(NOW()) AND MONTH(ll.created_at) = MONTH(NOW())",
            ['pid' => $pid, 'cid' => $companyId],
        ) ?? 0.0));

        $tierCounts = $this->db->fetchAllAssociative(
            'SELECT lt.name, lt.color, COUNT(la.id) AS count
               FROM loyalty_tiers lt
          LEFT JOIN loyalty_accounts la ON la.loyalty_tier_id = lt.id AND la.company_id = :cid
              WHERE lt.loyalty_program_id = :pid
             GROUP BY lt.id, lt.name, lt.color
             ORDER BY lt.sort_order ASC, lt.min_points ASC',
            ['pid' => $pid, 'cid' => $companyId],
        );

        return [
            'total_members'       => $totalMembers,
            'points_this_month'   => $pointsThisMonth,
            'points_last_month'   => $pointsLastMonth,
            'redeemed_this_month' => $redeemedThisMonth,
            'tier_counts'         => $tierCounts,
        ];
    }

    private function fetchSegmentCounts(int $companyId, array $program): array
    {
        $pid = (int) $program['id'];

        // Lifecycle counts from the denormalized column — fast
        $lifecycleRows = $this->db->fetchAllAssociative(
            'SELECT lifecycle_stage, COUNT(*) AS cnt
               FROM loyalty_accounts
              WHERE loyalty_program_id = :pid AND company_id = :cid
             GROUP BY lifecycle_stage',
            ['pid' => $pid, 'cid' => $companyId],
        );
        $byStage = array_column($lifecycleRows, 'cnt', 'lifecycle_stage');

        // Upgrade candidates — within 100 pts of next tier
        $upgradeCandidates = (int) ($this->db->fetchOne(
            'SELECT COUNT(DISTINCT la.id)
               FROM loyalty_accounts la
               JOIN loyalty_tiers nt ON nt.loyalty_program_id = la.loyalty_program_id
                                    AND nt.min_points > la.points_balance
              WHERE la.loyalty_program_id = :pid AND la.company_id = :cid
                AND (nt.min_points - la.points_balance) <= 100',
            ['pid' => $pid, 'cid' => $companyId],
        ) ?? 0);

        // Never redeemed — enrolled 60+ days, has balance, zero redemptions
        $neverRedeemed = (int) ($this->db->fetchOne(
            "SELECT COUNT(*)
               FROM loyalty_accounts
              WHERE loyalty_program_id    = :pid
                AND company_id           = :cid
                AND points_balance        > 0
                AND total_points_redeemed = 0
                AND enrolled_at          <= DATE_SUB(NOW(), INTERVAL 60 DAY)",
            ['pid' => $pid, 'cid' => $companyId],
        ) ?? 0);

        // High value — top 10% by total_points_earned
        // Compute offset in PHP and embed as integer literal — PDO binds OFFSET as a quoted
        // string on some drivers, which MariaDB rejects.
        $totalAccountCount   = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) FROM loyalty_accounts WHERE loyalty_program_id = :p AND company_id = :c',
            ['p' => $pid, 'c' => $companyId],
        ) ?? 0);
        $topTenPctOffset     = max(0, (int) round($totalAccountCount * 0.1) - 1);
        $topEarnerThreshold  = (float) ($this->db->fetchOne(
            "SELECT total_points_earned FROM loyalty_accounts
              WHERE loyalty_program_id = :pid AND company_id = :cid
             ORDER BY total_points_earned DESC
             LIMIT 1 OFFSET {$topTenPctOffset}",
            ['pid' => $pid, 'cid' => $companyId],
        ) ?? 0.0);
        $highValue = $topEarnerThreshold > 0 ? (int) ($this->db->fetchOne(
            'SELECT COUNT(*) FROM loyalty_accounts
              WHERE loyalty_program_id = :pid AND company_id = :cid
                AND total_points_earned >= :threshold',
            ['pid' => $pid, 'cid' => $companyId, 'threshold' => $topEarnerThreshold],
        ) ?? 0) : 0;

        // Expiring points (only if expiry enabled)
        // Compute the inactivity threshold in PHP — MariaDB rejects arithmetic on bound params.
        $expiringPoints = 0;
        if (!empty($program['points_expiry_enabled']) && !empty($program['points_expiry_days'])) {
            $expiryThresholdDays = max(0, (int) $program['points_expiry_days'] - 30);
            $expiringPoints = (int) ($this->db->fetchOne(
                "SELECT COUNT(*) FROM loyalty_accounts
                  WHERE loyalty_program_id = :pid AND company_id = :cid
                    AND points_balance > 0
                    AND last_transaction_at IS NOT NULL
                    AND DATEDIFF(NOW(), last_transaction_at) >= {$expiryThresholdDays}",
                ['pid' => $pid, 'cid' => $companyId],
            ) ?? 0);
        }

        return [
            ['key' => 'new',                'label' => 'New Members',         'description' => 'Enrolled in the last 14 days',                    'count' => (int) ($byStage['new']      ?? 0), 'color' => 'indigo',  'action' => 'welcome'],
            ['key' => 'active',             'label' => 'Active',              'description' => 'Transacted within last 60 days',                  'count' => (int) ($byStage['active']   ?? 0), 'color' => 'emerald', 'action' => null],
            ['key' => 'at_risk',            'label' => 'At-Risk',             'description' => 'No visit in 61–90 days',                         'count' => (int) ($byStage['at_risk']  ?? 0), 'color' => 'amber',   'action' => 'win_back'],
            ['key' => 'lapsing',            'label' => 'Lapsing',             'description' => 'No visit in 91–180 days',                        'count' => (int) ($byStage['lapsing']  ?? 0), 'color' => 'orange',  'action' => 'win_back'],
            ['key' => 'churned',            'label' => 'Churned',             'description' => 'No visit in 180+ days',                          'count' => (int) ($byStage['churned']  ?? 0), 'color' => 'red',     'action' => 'win_back'],
            ['key' => 'upgrade_candidates', 'label' => 'Upgrade Candidates',  'description' => 'Within 100 pts of next tier',                    'count' => $upgradeCandidates,                 'color' => 'violet',  'action' => 'almost_tier'],
            ['key' => 'high_value',         'label' => 'High Value',          'description' => 'Top 10% by lifetime points earned',              'count' => $highValue,                         'color' => 'yellow',  'action' => null],
            ['key' => 'never_redeemed',     'label' => 'Never Redeemed',      'description' => 'Balance > 0, enrolled 60+ days, never redeemed', 'count' => $neverRedeemed,                     'color' => 'sky',     'action' => 'manual_campaign'],
            ['key' => 'expiring_points',    'label' => 'Points Expiring Soon','description' => 'Points expiring within 30 days',                 'count' => $expiringPoints,                    'color' => 'rose',    'action' => 'expiry_warning'],
        ];
    }

    /**
     * Returns account IDs belonging to a named segment for bulk messaging.
     * Mirrors the logic in fetchSegmentCounts() — keep in sync.
     *
     * @return int[]
     */
    private function resolveSegmentAccountIds(int $companyId, array $program, string $segment): array
    {
        $pid = (int) $program['id'];

        return match ($segment) {
            'new' => $this->db->fetchFirstColumn(
                "SELECT id FROM loyalty_accounts
                  WHERE loyalty_program_id = :pid AND company_id = :cid
                    AND lifecycle_stage = 'new'",
                ['pid' => $pid, 'cid' => $companyId],
            ),
            'at_risk' => $this->db->fetchFirstColumn(
                "SELECT id FROM loyalty_accounts
                  WHERE loyalty_program_id = :pid AND company_id = :cid
                    AND lifecycle_stage = 'at_risk'",
                ['pid' => $pid, 'cid' => $companyId],
            ),
            'lapsing' => $this->db->fetchFirstColumn(
                "SELECT id FROM loyalty_accounts
                  WHERE loyalty_program_id = :pid AND company_id = :cid
                    AND lifecycle_stage = 'lapsing'",
                ['pid' => $pid, 'cid' => $companyId],
            ),
            'churned' => $this->db->fetchFirstColumn(
                "SELECT id FROM loyalty_accounts
                  WHERE loyalty_program_id = :pid AND company_id = :cid
                    AND lifecycle_stage = 'churned'",
                ['pid' => $pid, 'cid' => $companyId],
            ),
            'upgrade_candidates' => $this->db->fetchFirstColumn(
                'SELECT DISTINCT la.id FROM loyalty_accounts la
                   JOIN loyalty_tiers nt ON nt.loyalty_program_id = la.loyalty_program_id
                                       AND nt.min_points > la.points_balance
                  WHERE la.loyalty_program_id = :pid AND la.company_id = :cid
                    AND (nt.min_points - la.points_balance) <= 100',
                ['pid' => $pid, 'cid' => $companyId],
            ),
            'never_redeemed' => $this->db->fetchFirstColumn(
                "SELECT id FROM loyalty_accounts
                  WHERE loyalty_program_id    = :pid
                    AND company_id           = :cid
                    AND points_balance        > 0
                    AND total_points_redeemed = 0
                    AND enrolled_at          <= DATE_SUB(NOW(), INTERVAL 60 DAY)",
                ['pid' => $pid, 'cid' => $companyId],
            ),
            'expiring_points' => (function () use ($pid, $companyId, $program): array {
                if (empty($program['points_expiry_enabled']) || empty($program['points_expiry_days'])) {
                    return [];
                }
                $threshold = max(0, (int) $program['points_expiry_days'] - 30);
                return $this->db->fetchFirstColumn(
                    "SELECT id FROM loyalty_accounts
                      WHERE loyalty_program_id = :pid AND company_id = :cid
                        AND points_balance > 0
                        AND last_transaction_at IS NOT NULL
                        AND DATEDIFF(NOW(), last_transaction_at) >= {$threshold}",
                    ['pid' => $pid, 'cid' => $companyId],
                );
            })(),
            default => [],
        };
    }

    private function fetchOverviewSignals(int $companyId, ?array $program): array
    {
        $empty = [
            'at_risk_count'          => 0,
            'lapsing_count'          => 0,
            'upgrade_candidates'     => 0,
            'expiring_points_kes'    => 0.0,
            'active_rate'            => 0.0,
            'loyalty_spend_multiple' => null,
        ];

        if ($program === null) {
            return $empty;
        }

        $pid = (int) $program['id'];

        $lifecycleCounts = $this->db->fetchAllAssociative(
            "SELECT lifecycle_stage, COUNT(*) AS cnt
               FROM loyalty_accounts
              WHERE loyalty_program_id = :pid AND company_id = :cid
             GROUP BY lifecycle_stage",
            ['pid' => $pid, 'cid' => $companyId],
        );
        $byStage = array_column($lifecycleCounts, 'cnt', 'lifecycle_stage');

        $totalMembers = array_sum($byStage);
        $activeCount  = (int) ($byStage['active'] ?? 0);
        $activeRate   = $totalMembers > 0 ? round($activeCount / $totalMembers * 100, 1) : 0.0;

        // Members within threshold of next tier (100 pts default)
        $upgradeCandidates = (int) ($this->db->fetchOne(
            "SELECT COUNT(DISTINCT la.id)
               FROM loyalty_accounts la
               JOIN loyalty_tiers nt ON nt.loyalty_program_id = la.loyalty_program_id
                                    AND nt.min_points > la.points_balance
              WHERE la.loyalty_program_id = :pid AND la.company_id = :cid
                AND (nt.min_points - la.points_balance) <= 100",
            ['pid' => $pid, 'cid' => $companyId],
        ) ?? 0);

        // KES value of points expiring within 30 days (only when expiry enabled)
        $expiringKes = 0.0;
        if (!empty($program['points_expiry_enabled']) && !empty($program['points_expiry_days'])) {
            $expiryThreshold = max(0, (int) $program['points_expiry_days'] - 30);
            $expiringPoints  = (float) ($this->db->fetchOne(
                "SELECT COALESCE(SUM(la.points_balance), 0)
                   FROM loyalty_accounts la
                  WHERE la.loyalty_program_id = :pid
                    AND la.company_id         = :cid
                    AND la.points_balance      > 0
                    AND la.last_transaction_at IS NOT NULL
                    AND DATEDIFF(NOW(), la.last_transaction_at) >= {$expiryThreshold}",
                ['pid' => $pid, 'cid' => $companyId],
            ) ?? 0.0);
            $expiringKes = round($expiringPoints * (float) ($program['kes_per_point'] ?? 1.0), 0);
        }

        // Loyalty vs non-loyalty spend multiple (requires POS data)
        $spendMultiple = null;
        try {
            $loyaltyAvg = (float) ($this->db->fetchOne(
                'SELECT AVG(pt.total_amount)
                   FROM pos_transactions pt
                   JOIN loyalty_accounts la ON la.customer_id = pt.customer_id
                  WHERE pt.company_id = :cid
                    AND pt.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    AND pt.status = \'completed\'',
                ['cid' => $companyId],
            ) ?? 0.0);

            $nonLoyaltyAvg = (float) ($this->db->fetchOne(
                'SELECT AVG(pt.total_amount)
                   FROM pos_transactions pt
                  WHERE pt.company_id = :cid
                    AND pt.customer_id NOT IN (SELECT customer_id FROM loyalty_accounts WHERE company_id = :cid2)
                    AND pt.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    AND pt.status = \'completed\'',
                ['cid' => $companyId, 'cid2' => $companyId],
            ) ?? 0.0);

            if ($nonLoyaltyAvg > 0 && $loyaltyAvg > 0) {
                $spendMultiple = round($loyaltyAvg / $nonLoyaltyAvg, 1);
            }
        } catch (\Throwable) {
            // POS table may not exist on all deployments
        }

        return [
            'at_risk_count'          => (int) ($byStage['at_risk'] ?? 0),
            'lapsing_count'          => (int) ($byStage['lapsing'] ?? 0),
            'churned_count'          => (int) ($byStage['churned'] ?? 0),
            'upgrade_candidates'     => $upgradeCandidates,
            'expiring_points_kes'    => $expiringKes,
            'active_rate'            => $activeRate,
            'loyalty_spend_multiple' => $spendMultiple,
        ];
    }

    private function fetchMembersList(
        int $companyId,
        ?int $branchId,
        ?array $program,
        string $search,
        ?int $tierId,
        int $page,
        int $perPage,
    ): array {
        if ($program === null) {
            return [[], 0];
        }

        $pid    = (int) $program['id'];
        $offset = ($page - 1) * $perPage;
        $where  = ['la.loyalty_program_id = :pid', 'la.company_id = :cid'];
        $params = ['pid' => $pid, 'cid' => $companyId];

        if ($search !== '') {
            $where[]          = '(c.first_name LIKE :search OR la.msisdn LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($tierId !== null) {
            $where[]           = 'la.loyalty_tier_id = :tier_id';
            $params['tier_id'] = $tierId;
        }

        $wc = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT la.id)
               FROM loyalty_accounts la
               JOIN customers c ON c.id = la.customer_id
              WHERE {$wc}",
            $params,
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT la.id AS account_id, la.customer_id,
                    c.first_name, la.msisdn,
                    la.points_balance, la.total_points_earned, la.total_points_redeemed,
                    la.enrolled_at, la.lifecycle_stage, la.last_transaction_at,
                    lt.name  AS tier_name,
                    lt.color AS tier_color,
                    MAX(ll.created_at) AS last_activity
               FROM loyalty_accounts la
               JOIN customers c       ON c.id  = la.customer_id
          LEFT JOIN loyalty_tiers lt  ON lt.id = la.loyalty_tier_id
          LEFT JOIN loyalty_ledger ll ON ll.loyalty_account_id = la.id
              WHERE {$wc}
             GROUP BY la.id, la.customer_id, c.first_name, la.msisdn,
                      la.points_balance, la.total_points_earned, la.total_points_redeemed,
                      la.enrolled_at, lt.name, lt.color
             ORDER BY la.points_balance DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [$rows, $total];
    }

    private function fetchMemberLedger(int $accountId, int $companyId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM loyalty_ledger WHERE loyalty_account_id = :id AND company_id = :cid',
            ['id' => $accountId, 'cid' => $companyId],
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT ll.id, ll.type, ll.points, ll.balance_after, ll.note,
                    ll.pos_transaction_id, ll.mpesa_payment_id, ll.created_at,
                    u.name AS cashier_name
               FROM loyalty_ledger ll
          LEFT JOIN users u ON u.id = ll.created_by_user_id
              WHERE ll.loyalty_account_id = :id AND ll.company_id = :cid
             ORDER BY ll.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            ['id' => $accountId, 'cid' => $companyId],
        );

        return [$rows, $total];
    }

    private function fetchLedger(
        int $companyId,
        ?int $branchId,
        string $typeFilter,
        string $search,
        string $dateFrom,
        string $dateTo,
        int $page,
        int $perPage,
    ): array {
        $offset = ($page - 1) * $perPage;
        $where  = ['ll.company_id = :cid'];
        $params = ['cid' => $companyId];

        if ($branchId !== null) {
            $where[]             = 'lp.branch_id = :branch_id';
            $params['branch_id'] = $branchId;
        }

        if ($typeFilter !== '') {
            $where[]        = 'll.type = :type';
            $params['type'] = $typeFilter;
        }

        if ($search !== '') {
            $where[]          = '(c.first_name LIKE :search OR la.msisdn LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($dateFrom !== '') {
            $where[]              = 'DATE(ll.created_at) >= :date_from';
            $params['date_from']  = $dateFrom;
        }

        if ($dateTo !== '') {
            $where[]            = 'DATE(ll.created_at) <= :date_to';
            $params['date_to']  = $dateTo;
        }

        $wc   = implode(' AND ', $where);
        $from = 'FROM loyalty_ledger ll
                   JOIN loyalty_accounts la ON la.id  = ll.loyalty_account_id
                   JOIN loyalty_programs lp ON lp.id  = la.loyalty_program_id
                   JOIN customers c         ON c.id   = la.customer_id
              LEFT JOIN users u             ON u.id   = ll.created_by_user_id';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) {$from} WHERE {$wc}", $params);

        $rows = $this->db->fetchAllAssociative(
            "SELECT ll.id, ll.type, ll.points, ll.balance_after, ll.note,
                    ll.pos_transaction_id, ll.mpesa_payment_id, ll.created_at,
                    c.first_name, la.msisdn,
                    u.name AS cashier_name,
                    lp.branch_id
               {$from}
              WHERE {$wc}
             ORDER BY ll.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [$rows, $total];
    }

    private function fetchReportsData(int $companyId, ?int $branchId, ?array $program, int $days): array
    {
        $empty = [
            // Programme tab
            'enrollment_trend'       => [],
            'tier_distribution'      => [],
            'points_flow_monthly'    => [],
            'auto_award_rate'        => 0.0,
            // Retention tab
            'lifecycle_breakdown'    => [],
            'cohort_retention'       => [],
            // Revenue tab
            'redemption_rate'        => 0.0,
            'total_redemptions'      => 0.0,
            'kes_redeemed'           => 0.0,
            'top_earners'            => [],
            'avg_value_by_tier'      => [],
            'redemption_return_14d'  => 0.0,
            'redemption_return_30d'  => 0.0,
            // Automations tab
            'automation_performance' => [],
        ];

        if ($program === null) {
            return $empty;
        }

        $pid    = (int) $program['id'];
        $params = ['pid' => $pid, 'cid' => $companyId, 'days' => $days];

        // ── Programme tab ─────────────────────────────────────────────────────

        $enrollmentTrend = $this->db->fetchAllAssociative(
            'SELECT DATE(enrolled_at) AS date, COUNT(*) AS count
               FROM loyalty_accounts
              WHERE loyalty_program_id = :pid AND company_id = :cid
                AND enrolled_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY DATE(enrolled_at)
             ORDER BY date ASC',
            $params,
        );

        $tierDistribution = $this->db->fetchAllAssociative(
            'SELECT lt.name, lt.color, COUNT(la.id) AS count
               FROM loyalty_tiers lt
          LEFT JOIN loyalty_accounts la ON la.loyalty_tier_id = lt.id AND la.company_id = :cid
              WHERE lt.loyalty_program_id = :pid
             GROUP BY lt.id, lt.name, lt.color
             ORDER BY lt.sort_order ASC',
            ['pid' => $pid, 'cid' => $companyId],
        );

        // Points earned vs redeemed: last 6 calendar months
        $pointsFlowMonthly = $this->db->fetchAllAssociative(
            "SELECT DATE_FORMAT(ll.created_at, '%Y-%m') AS month,
                    COALESCE(SUM(CASE WHEN ll.type = 'earn'   THEN ll.points      ELSE 0 END), 0) AS earned,
                    COALESCE(SUM(CASE WHEN ll.type = 'redeem' THEN ABS(ll.points) ELSE 0 END), 0) AS redeemed
               FROM loyalty_ledger ll
               JOIN loyalty_accounts la ON la.id = ll.loyalty_account_id
              WHERE la.loyalty_program_id = :pid AND ll.company_id = :cid
                AND ll.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(ll.created_at, '%Y-%m')
             ORDER BY month ASC",
            ['pid' => $pid, 'cid' => $companyId],
        );

        $autoAwardRate = 0.0;
        if (!empty($program['auto_award_enabled'])) {
            try {
                $totalPay = (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM mpesa_payments WHERE company_id = :cid AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)',
                    ['cid' => $companyId, 'days' => $days],
                );
                if ($totalPay > 0) {
                    $awarded = (int) $this->db->fetchOne(
                        'SELECT COUNT(*) FROM mpesa_payments WHERE company_id = :cid AND loyalty_auto_awarded = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)',
                        ['cid' => $companyId, 'days' => $days],
                    );
                    $autoAwardRate = round($awarded / $totalPay * 100, 1);
                }
            } catch (\Throwable) {}
        }

        // ── Retention tab ─────────────────────────────────────────────────────

        $lifecycleRows = $this->db->fetchAllAssociative(
            'SELECT lifecycle_stage, COUNT(*) AS cnt
               FROM loyalty_accounts
              WHERE loyalty_program_id = :pid AND company_id = :cid
             GROUP BY lifecycle_stage',
            ['pid' => $pid, 'cid' => $companyId],
        );
        $stageCounts  = array_column($lifecycleRows, 'cnt', 'lifecycle_stage');
        $totalMembers = (int) array_sum($stageCounts);

        $stageMeta = [
            'new'     => ['label' => 'New',     'color' => '#6366f1'],
            'active'  => ['label' => 'Active',  'color' => '#10b981'],
            'at_risk' => ['label' => 'At-Risk', 'color' => '#f59e0b'],
            'lapsing' => ['label' => 'Lapsing', 'color' => '#f97316'],
            'churned' => ['label' => 'Churned', 'color' => '#ef4444'],
        ];
        $lifecycleBreakdown = [];
        foreach ($stageMeta as $stage => $meta) {
            $cnt = (int) ($stageCounts[$stage] ?? 0);
            $lifecycleBreakdown[] = [
                'stage' => $stage,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'count' => $cnt,
                'pct'   => $totalMembers > 0 ? round($cnt / $totalMembers * 100, 1) : 0.0,
            ];
        }

        // Cohort retention: enrolled month x current lifecycle (last 12 months)
        $cohortRows = $this->db->fetchAllAssociative(
            "SELECT DATE_FORMAT(enrolled_at, '%Y-%m') AS cohort_month,
                    lifecycle_stage,
                    COUNT(*) AS cnt
               FROM loyalty_accounts
              WHERE loyalty_program_id = :pid AND company_id = :cid
                AND enrolled_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY DATE_FORMAT(enrolled_at, '%Y-%m'), lifecycle_stage
             ORDER BY cohort_month ASC",
            ['pid' => $pid, 'cid' => $companyId],
        );
        $cohortMap = [];
        foreach ($cohortRows as $row) {
            $m = $row['cohort_month'];
            if (!isset($cohortMap[$m])) {
                $cohortMap[$m] = ['month' => $m, 'total' => 0, 'new' => 0, 'active' => 0, 'at_risk' => 0, 'lapsing' => 0, 'churned' => 0];
            }
            $cohortMap[$m][$row['lifecycle_stage']] = (int) $row['cnt'];
            $cohortMap[$m]['total'] += (int) $row['cnt'];
        }
        $cohortRetention = array_values($cohortMap);

        // ── Revenue tab ───────────────────────────────────────────────────────

        $redeemingMembers = (int) ($this->db->fetchOne(
            "SELECT COUNT(DISTINCT ll.loyalty_account_id)
               FROM loyalty_ledger ll
               JOIN loyalty_accounts la ON la.id = ll.loyalty_account_id
              WHERE la.loyalty_program_id = :pid AND ll.company_id = :cid AND ll.type = 'redeem'
                AND ll.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)",
            $params,
        ) ?? 0);

        $redemptionRate = $totalMembers > 0 ? round($redeemingMembers / $totalMembers * 100, 1) : 0.0;

        $totalRedemptions = (float) abs((float) ($this->db->fetchOne(
            "SELECT COALESCE(SUM(ll.points), 0)
               FROM loyalty_ledger ll
               JOIN loyalty_accounts la ON la.id = ll.loyalty_account_id
              WHERE la.loyalty_program_id = :pid AND ll.company_id = :cid AND ll.type = 'redeem'
                AND ll.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)",
            $params,
        ) ?? 0.0));

        $kesRedeemed = $totalRedemptions * (float) ($program['kes_per_point'] ?? 1.0);

        $topEarners = $this->db->fetchAllAssociative(
            "SELECT c.first_name, la.msisdn, COALESCE(SUM(ll.points), 0) AS points_earned
               FROM loyalty_ledger ll
               JOIN loyalty_accounts la ON la.id = ll.loyalty_account_id
               JOIN customers c         ON c.id  = la.customer_id
              WHERE la.loyalty_program_id = :pid AND ll.company_id = :cid AND ll.type = 'earn'
                AND ll.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY la.id, c.first_name, la.msisdn
             ORDER BY points_earned DESC
             LIMIT 10",
            $params,
        );

        // Average transaction value by tier (requires POS)
        $avgValueByTier = [];
        try {
            $avgValueByTier = $this->db->fetchAllAssociative(
                "SELECT COALESCE(lt.name, 'No Tier') AS tier_name,
                        COALESCE(lt.color, '#94a3b8') AS color,
                        COUNT(DISTINCT pt.id) AS tx_count,
                        ROUND(AVG(pt.total_amount), 2) AS avg_value
                   FROM pos_transactions pt
                   JOIN loyalty_accounts la ON la.customer_id = pt.customer_id
                                           AND la.company_id  = pt.company_id
                                           AND la.loyalty_program_id = :pid
              LEFT JOIN loyalty_tiers lt ON lt.id = la.loyalty_tier_id
                  WHERE pt.company_id = :cid AND pt.status = 'completed'
                    AND pt.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                 GROUP BY lt.id, lt.name, lt.color
                 ORDER BY avg_value DESC",
                $params,
            );
        } catch (\Throwable) {}

        // Redemption return rate: members who redeemed then earned again within 14d / 30d
        $redemptionReturn14d    = 0.0;
        $redemptionReturn30d    = 0.0;
        $totalRedemptionAccounts = (int) ($this->db->fetchOne(
            "SELECT COUNT(DISTINCT r.loyalty_account_id)
               FROM loyalty_ledger r
               JOIN loyalty_accounts la ON la.id = r.loyalty_account_id
              WHERE la.loyalty_program_id = :pid AND r.company_id = :cid AND r.type = 'redeem'
                AND r.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)",
            $params,
        ) ?? 0);

        if ($totalRedemptionAccounts > 0) {
            $returned14d = (int) ($this->db->fetchOne(
                "SELECT COUNT(DISTINCT r.loyalty_account_id)
                   FROM loyalty_ledger r
                   JOIN loyalty_accounts la ON la.id = r.loyalty_account_id
                  WHERE la.loyalty_program_id = :pid AND r.company_id = :cid AND r.type = 'redeem'
                    AND r.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                    AND EXISTS (
                        SELECT 1 FROM loyalty_ledger e
                         WHERE e.loyalty_account_id = r.loyalty_account_id
                           AND e.type = 'earn'
                           AND e.created_at > r.created_at
                           AND e.created_at <= DATE_ADD(r.created_at, INTERVAL 14 DAY)
                    )",
                $params,
            ) ?? 0);
            $returned30d = (int) ($this->db->fetchOne(
                "SELECT COUNT(DISTINCT r.loyalty_account_id)
                   FROM loyalty_ledger r
                   JOIN loyalty_accounts la ON la.id = r.loyalty_account_id
                  WHERE la.loyalty_program_id = :pid AND r.company_id = :cid AND r.type = 'redeem'
                    AND r.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                    AND EXISTS (
                        SELECT 1 FROM loyalty_ledger e
                         WHERE e.loyalty_account_id = r.loyalty_account_id
                           AND e.type = 'earn'
                           AND e.created_at > r.created_at
                           AND e.created_at <= DATE_ADD(r.created_at, INTERVAL 30 DAY)
                    )",
                $params,
            ) ?? 0);
            $redemptionReturn14d = round($returned14d / $totalRedemptionAccounts * 100, 1);
            $redemptionReturn30d = round($returned30d / $totalRedemptionAccounts * 100, 1);
        }

        // ── Automations tab ───────────────────────────────────────────────────

        $automationPerformance = [];
        try {
            $automationPerformance = $this->db->fetchAllAssociative(
                "SELECT la.trigger_type,
                        la.is_active,
                        COUNT(ln.id) AS sent_count,
                        SUM(CASE WHEN ln.returned_at IS NOT NULL THEN 1 ELSE 0 END) AS returned_count,
                        ROUND(AVG(CASE WHEN ln.returned_at IS NOT NULL
                            THEN TIMESTAMPDIFF(HOUR, ln.sent_at, ln.returned_at) END), 1) AS avg_hours_to_return
                   FROM loyalty_automations la
              LEFT JOIN loyalty_notifications ln ON ln.trigger_type = la.trigger_type
                                                 AND ln.company_id  = la.company_id
                  WHERE la.loyalty_program_id = :pid
                 GROUP BY la.id, la.trigger_type, la.is_active
                 ORDER BY sent_count DESC",
                ['pid' => $pid],
            );
        } catch (\Throwable) {}

        return [
            // Programme tab
            'enrollment_trend'       => $enrollmentTrend,
            'tier_distribution'      => $tierDistribution,
            'points_flow_monthly'    => $pointsFlowMonthly,
            'auto_award_rate'        => $autoAwardRate,
            // Retention tab
            'lifecycle_breakdown'    => $lifecycleBreakdown,
            'cohort_retention'       => $cohortRetention,
            // Revenue tab
            'redemption_rate'        => $redemptionRate,
            'total_redemptions'      => $totalRedemptions,
            'kes_redeemed'           => $kesRedeemed,
            'top_earners'            => $topEarners,
            'avg_value_by_tier'      => $avgValueByTier,
            'redemption_return_14d'  => $redemptionReturn14d,
            'redemption_return_30d'  => $redemptionReturn30d,
            // Automations tab
            'automation_performance' => $automationPerformance,
        ];
    }

    // =========================================================================
    // PRIVATE — FORM HANDLERS
    // =========================================================================

    private function handleTierCreate(Request $request, object $session): JsonResponse
    {
        $companyId = $session->company->id;
        $program   = $this->loyalty->getProgram($companyId, $session->branch?->id);
        if (!$program) {
            return $this->error('No loyalty programme found.', 404);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') return $this->error('Tier name is required.');
        if (mb_strlen($name) > 50) return $this->error('Tier name must be 50 characters or fewer.');

        $minPoints = max(0, (int) $request->request->get('min_points', 0));
        $color     = trim((string) $request->request->get('color', '')) ?: null;
        $perks     = trim((string) $request->request->get('perks_description', '')) ?: null;

        $maxSort = (int) ($this->db->fetchOne(
            'SELECT COALESCE(MAX(sort_order), -1) FROM loyalty_tiers WHERE loyalty_program_id = :pid',
            ['pid' => $program['id']],
        ) ?? -1);

        $this->db->insert('loyalty_tiers', [
            'loyalty_program_id' => $program['id'],
            'company_id'         => $companyId,
            'name'               => $name,
            'min_points'         => $minPoints,
            'color'              => $color,
            'perks_description'  => $perks,
            'sort_order'         => $maxSort + 1,
        ]);

        return $this->success('Tier created.', ['id' => (int) $this->db->lastInsertId()]);
    }

    private function handleTierUpdate(Request $request, object $session, int $tierId): JsonResponse
    {
        $companyId = $session->company->id;

        $tier = $this->db->fetchAssociative(
            'SELECT id FROM loyalty_tiers WHERE id = :id AND company_id = :cid LIMIT 1',
            ['id' => $tierId, 'cid' => $companyId],
        );
        if (!$tier) return $this->error('Tier not found.', 404);

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') return $this->error('Tier name is required.');
        if (mb_strlen($name) > 50) return $this->error('Tier name must be 50 characters or fewer.');

        $this->db->executeStatement(
            'UPDATE loyalty_tiers SET name = :name, min_points = :pts, color = :color, perks_description = :perks
              WHERE id = :id AND company_id = :cid',
            [
                'name'  => $name,
                'pts'   => max(0, (int) $request->request->get('min_points', 0)),
                'color' => trim((string) $request->request->get('color', '')) ?: null,
                'perks' => trim((string) $request->request->get('perks_description', '')) ?: null,
                'id'    => $tierId,
                'cid'   => $companyId,
            ],
        );

        return $this->success('Tier updated.');
    }

    private function handleTierDelete(Request $request, object $session, int $tierId): JsonResponse
    {
        $companyId = $session->company->id;

        $tier = $this->db->fetchAssociative(
            'SELECT id, loyalty_program_id FROM loyalty_tiers WHERE id = :id AND company_id = :cid LIMIT 1',
            ['id' => $tierId, 'cid' => $companyId],
        );
        if (!$tier) return $this->error('Tier not found.', 404);

        $memberCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM loyalty_accounts WHERE loyalty_tier_id = :id AND company_id = :cid',
            ['id' => $tierId, 'cid' => $companyId],
        );
        if ($memberCount > 0) {
            return $this->error("Cannot delete: {$memberCount} member(s) are on this tier.", 409);
        }

        $tierCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM loyalty_tiers WHERE loyalty_program_id = :pid',
            ['pid' => $tier['loyalty_program_id']],
        );
        if ($tierCount <= 1) {
            return $this->error('Cannot delete the only tier in the programme.', 422);
        }

        $this->db->delete('loyalty_tiers', ['id' => $tierId, 'company_id' => $companyId]);

        return $this->success('Tier deleted.');
    }

    private function handleTierReorder(Request $request, object $session): JsonResponse
    {
        $companyId = $session->company->id;
        $ids       = array_map('intval', (array) ($request->request->all()['ids'] ?? []));

        if (empty($ids)) return $this->error('No IDs provided.');

        // Verify all IDs belong to this company
        $valid = $this->db->fetchFirstColumn(
            'SELECT id FROM loyalty_tiers WHERE company_id = :cid AND id IN (' . implode(',', $ids) . ')',
            ['cid' => $companyId],
        );

        if (count($valid) !== count($ids)) {
            return $this->error('One or more tier IDs are invalid.', 403);
        }

        foreach ($ids as $sortOrder => $tierId) {
            $this->db->executeStatement(
                'UPDATE loyalty_tiers SET sort_order = :sort WHERE id = :id AND company_id = :cid',
                ['sort' => $sortOrder, 'id' => $tierId, 'cid' => $companyId],
            );
        }

        return $this->success('Order saved.');
    }

    private function handleMemberEnroll(Request $request, object $session): JsonResponse
    {
        $companyId = $session->company->id;
        $branchId  = $session->branch?->id;
        $phone     = trim((string) $request->request->get('phone', ''));
        $firstName = trim((string) $request->request->get('first_name', '')) ?: null;

        if ($phone === '') return $this->error('Phone number is required.');

        // findOrEnroll normalizes the phone internally; null means invalid phone or no program
        $account = $this->loyalty->findOrEnroll($companyId, $phone, $firstName, $branchId);

        if ($account === null) {
            return $this->error('Invalid phone number or no loyalty programme configured.');
        }

        return $this->success('Member enrolled.', [
            'account_id'     => $account->id,
            'points_balance' => $account->pointsBalance,
            'tier_name'      => $account->tierName,
        ]);
    }

    private function handlePointAdjust(Request $request, object $session, int $accountId): JsonResponse
    {
        $companyId = $session->company->id;
        $direction = $request->request->get('direction', '');
        $amount    = (float) $request->request->get('amount', 0);
        $note      = trim((string) $request->request->get('note', ''));

        if (!in_array($direction, ['add', 'deduct'], true)) return $this->error('Invalid direction.');
        if ($amount <= 0)  return $this->error('Amount must be greater than zero.');
        if ($note === '')  return $this->error('A reason is required for point adjustments.');
        if (mb_strlen($note) > 255) return $this->error('Reason must be 255 characters or fewer.');

        // Verify account ownership
        $account = $this->db->fetchAssociative(
            'SELECT id, points_balance FROM loyalty_accounts WHERE id = :id AND company_id = :cid LIMIT 1',
            ['id' => $accountId, 'cid' => $companyId],
        );
        if (!$account) return $this->error('Account not found.', 404);

        if ($direction === 'deduct') {
            if ((float) $account['points_balance'] < $amount) {
                return $this->error('Insufficient points balance for this deduction.');
            }
            $this->db->executeStatement(
                'UPDATE loyalty_accounts SET points_balance = points_balance - :amt, updated_at = NOW()
                  WHERE id = :id AND company_id = :cid AND points_balance >= :amt',
                ['amt' => $amount, 'id' => $accountId, 'cid' => $companyId],
            );
        } else {
            $this->db->executeStatement(
                'UPDATE loyalty_accounts SET points_balance = points_balance + :amt,
                        total_points_earned = total_points_earned + :amt, updated_at = NOW()
                  WHERE id = :id AND company_id = :cid',
                ['amt' => $amount, 'id' => $accountId, 'cid' => $companyId],
            );
        }

        $newBalance = (float) $this->db->fetchOne(
            'SELECT points_balance FROM loyalty_accounts WHERE id = :id',
            ['id' => $accountId],
        );

        $this->db->insert('loyalty_ledger', [
            'company_id'         => $companyId,
            'loyalty_account_id' => $accountId,
            'type'               => 'adjust',
            'points'             => $direction === 'add' ? $amount : -$amount,
            'balance_after'      => $newBalance,
            'note'               => $note,
            'created_by_user_id' => $session->user->id,
            'created_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->loyalty->resolveAndUpdateTier($accountId, $companyId);

        return $this->success('Points adjusted.', ['new_balance' => $newBalance]);
    }

    // =========================================================================
    // PRIVATE — CSV EXPORTS
    // =========================================================================

    private function streamMembersCsv(Request $request, object $session): StreamedResponse
    {
        $companyId = $session->company->id;
        $branchId  = $session->branch?->id;
        $program   = $this->loyalty->getProgram($companyId, $branchId);
        $search    = trim((string) $request->query->get('q', ''));
        $tierId    = (int) $request->query->get('tier', 0) ?: null;
        $canPhone  = $this->canDo($session, 'VIEW_FULL_CUSTOMER_PHONE');
        $date      = date('Y-m-d');

        [$rows] = $this->fetchMembersList($companyId, $branchId, $program, $search, $tierId, 1, 10000);

        return new StreamedResponse(function () use ($rows, $canPhone) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['Name', 'Phone', 'Tier', 'Balance', 'Total Earned', 'Total Redeemed', 'Enrolled Date']);
            foreach ($rows as $m) {
                $phone = $canPhone
                    ? ($m['phone'] ?? $m['msisdn'])
                    : (substr($m['msisdn'], 0, 5) . '••••' . substr($m['msisdn'], -3));
                fputcsv($f, [
                    $m['first_name'] ?? '',
                    $phone,
                    $m['tier_name']  ?? '',
                    $m['points_balance'],
                    $m['total_points_earned'],
                    $m['total_points_redeemed'],
                    $m['enrolled_at'] ? date('d M Y', strtotime($m['enrolled_at'])) : '',
                ]);
            }
            fclose($f);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"loyalty-members-{$date}.csv\"",
        ]);
    }

    private function streamLedgerCsv(Request $request, object $session): StreamedResponse
    {
        $companyId   = $session->company->id;
        $branchId    = $session->branch?->id;
        $typeFilter  = $request->query->get('type', '');
        $search      = trim((string) $request->query->get('q', ''));
        $dateFrom    = trim((string) $request->query->get('from', ''));
        $dateTo      = trim((string) $request->query->get('to', ''));
        $canPhone    = $this->canDo($session, 'VIEW_FULL_CUSTOMER_PHONE');
        $date        = date('Y-m-d');

        [$rows] = $this->fetchLedger($companyId, $branchId, $typeFilter, $search, $dateFrom, $dateTo, 1, 50000);

        return new StreamedResponse(function () use ($rows, $canPhone) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['Date', 'Member', 'Phone', 'Type', 'Points', 'Balance After', 'Source', 'Cashier', 'Note']);
            foreach ($rows as $e) {
                $phone = $canPhone
                    ? $e['msisdn']
                    : (substr($e['msisdn'], 0, 5) . '••••' . substr($e['msisdn'], -3));
                $source = $e['pos_transaction_id'] ? 'POS #' . $e['pos_transaction_id'] : ($e['mpesa_payment_id'] ? 'M-Pesa' : 'Manual');
                fputcsv($f, [
                    date('d M Y H:i', strtotime($e['created_at'])),
                    $e['first_name'] ?? '',
                    $phone,
                    $e['type'],
                    $e['points'],
                    $e['balance_after'],
                    $source,
                    $e['cashier_name'] ?? '',
                    $e['note'] ?? '',
                ]);
            }
            fclose($f);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"loyalty-ledger-{$date}.csv\"",
        ]);
    }

    // =========================================================================
    // PRIVATE — AUTOMATION HANDLER
    // =========================================================================

    private function handleAutomationSave(Request $request, object $session, int $programId, int $companyId): Response
    {
        $types = ['win_back', 'tier_upgrade', 'almost_tier', 'expiry_warning', 'birthday_bonus'];

        foreach ($types as $type) {
            $isActive = $request->request->get("automations[{$type}][is_active]") === '1' ? 1 : 0;
            $template = trim((string) $request->request->get("automations[{$type}][message_template]", ''));
            $thresholdDays   = (int) $request->request->get("automations[{$type}][threshold_days]", 0) ?: null;
            $thresholdPoints = (int) $request->request->get("automations[{$type}][threshold_points]", 0) ?: null;

            if ($template === '') {
                continue; // Don't save an automation with no message
            }

            $existing = $this->db->fetchOne(
                'SELECT id FROM loyalty_automations WHERE loyalty_program_id = :pid AND trigger_type = :type',
                ['pid' => $programId, 'type' => $type],
            );

            if ($existing) {
                $this->db->executeStatement(
                    'UPDATE loyalty_automations
                        SET is_active = :active, message_template = :tpl,
                            threshold_days = :days, threshold_points = :pts
                      WHERE loyalty_program_id = :pid AND trigger_type = :type',
                    [
                        'active' => $isActive,
                        'tpl'    => $template,
                        'days'   => $thresholdDays,
                        'pts'    => $thresholdPoints,
                        'pid'    => $programId,
                        'type'   => $type,
                    ],
                );
            } else {
                $this->db->insert('loyalty_automations', [
                    'company_id'         => $companyId,
                    'loyalty_program_id' => $programId,
                    'trigger_type'       => $type,
                    'is_active'          => $isActive,
                    'message_template'   => $template,
                    'threshold_days'     => $thresholdDays,
                    'threshold_points'   => $thresholdPoints,
                ]);
            }
        }

        $this->addFlash('success', 'Automation settings saved.');

        return $this->redirectToRoute('admin_loyalty_automations', [
            'subdomain' => (string) $request->attributes->get('subdomain', ''),
            'domain'    => (string) $request->attributes->get('domain', ''),
            'branch'    => (string) $request->attributes->get('branch', ''),
        ]);
    }

    // =========================================================================
    // PRIVATE — MULTIPLIER HANDLERS
    // =========================================================================

    private function handleMultiplierCreate(Request $request, object $session): JsonResponse
    {
        $companyId = $session->company->id;
        $program   = $this->loyalty->getProgram($companyId, $session->branch?->id);
        if (!$program) return $this->error('No loyalty programme found.', 404);

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') return $this->error('Name is required.');
        if (mb_strlen($name) > 100) return $this->error('Name must be 100 characters or fewer.');

        $multiplier = (float) $request->request->get('multiplier', 2.0);
        if ($multiplier < 1.01 || $multiplier > 10.0) {
            return $this->error('Multiplier must be between 1.01 and 10.00.');
        }

        $appliesOn = trim((string) $request->request->get('applies_on', '')) ?: null;
        $timeFrom  = trim((string) $request->request->get('time_from', '')) ?: null;
        $timeTo    = trim((string) $request->request->get('time_to', '')) ?: null;
        $validFrom = trim((string) $request->request->get('valid_from', '')) ?: null;
        $validTo   = trim((string) $request->request->get('valid_to', '')) ?: null;

        $this->db->insert('loyalty_point_multipliers', [
            'company_id'         => $companyId,
            'loyalty_program_id' => $program['id'],
            'name'               => $name,
            'multiplier'         => $multiplier,
            'applies_on'         => $appliesOn,
            'time_from'          => $timeFrom,
            'time_to'            => $timeTo,
            'valid_from'         => $validFrom,
            'valid_to'           => $validTo,
            'is_active'          => 1,
        ]);

        return $this->success('Multiplier created.', ['id' => (int) $this->db->lastInsertId()]);
    }

    private function handleMultiplierUpdate(Request $request, object $session, int $multiplierId): JsonResponse
    {
        $companyId = $session->company->id;

        $row = $this->db->fetchAssociative(
            'SELECT id FROM loyalty_point_multipliers WHERE id = :id AND company_id = :cid LIMIT 1',
            ['id' => $multiplierId, 'cid' => $companyId],
        );
        if (!$row) return $this->error('Multiplier not found.', 404);

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') return $this->error('Name is required.');

        $multiplier = (float) $request->request->get('multiplier', 2.0);
        if ($multiplier < 1.01 || $multiplier > 10.0) {
            return $this->error('Multiplier must be between 1.01 and 10.00.');
        }

        $this->db->executeStatement(
            'UPDATE loyalty_point_multipliers
                SET name = :name, multiplier = :mult, applies_on = :on,
                    time_from = :tfrom, time_to = :tto, valid_from = :vfrom, valid_to = :vto
              WHERE id = :id AND company_id = :cid',
            [
                'name'  => $name,
                'mult'  => $multiplier,
                'on'    => trim((string) $request->request->get('applies_on', '')) ?: null,
                'tfrom' => trim((string) $request->request->get('time_from', '')) ?: null,
                'tto'   => trim((string) $request->request->get('time_to', '')) ?: null,
                'vfrom' => trim((string) $request->request->get('valid_from', '')) ?: null,
                'vto'   => trim((string) $request->request->get('valid_to', '')) ?: null,
                'id'    => $multiplierId,
                'cid'   => $companyId,
            ],
        );

        return $this->success('Multiplier updated.');
    }

    private function handleMultiplierToggle(Request $request, object $session, int $multiplierId): JsonResponse
    {
        $companyId = $session->company->id;

        $row = $this->db->fetchAssociative(
            'SELECT id, is_active FROM loyalty_point_multipliers WHERE id = :id AND company_id = :cid LIMIT 1',
            ['id' => $multiplierId, 'cid' => $companyId],
        );
        if (!$row) return $this->error('Multiplier not found.', 404);

        $newState = $row['is_active'] ? 0 : 1;

        $this->db->executeStatement(
            'UPDATE loyalty_point_multipliers SET is_active = :state WHERE id = :id AND company_id = :cid',
            ['state' => $newState, 'id' => $multiplierId, 'cid' => $companyId],
        );

        return $this->success($newState ? 'Multiplier activated.' : 'Multiplier paused.', ['is_active' => $newState]);
    }

    private function handleMultiplierDelete(Request $request, object $session, int $multiplierId): JsonResponse
    {
        $companyId = $session->company->id;

        $affected = $this->db->executeStatement(
            'DELETE FROM loyalty_point_multipliers WHERE id = :id AND company_id = :cid',
            ['id' => $multiplierId, 'cid' => $companyId],
        );

        if ($affected === 0) return $this->error('Multiplier not found.', 404);

        return $this->success('Multiplier deleted.');
    }
}
