<?php

declare(strict_types=1);

namespace App\Controller\Terminal;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Permission\CheckPermissionService;
use App\Services\Terminal\TerminalBranchAccessService;
use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Terminal M-Pesa Payments Feed
 *
 * Shows recent M-Pesa callback payments for this branch so cashiers can
 * quickly confirm incoming payments without leaving the terminal.
 *
 * Visibility is gated by pos_terminal_settings.show_mpesa_feed = 1 for this branch.
 * Volume is controlled by mpesa_feed_max_hours and mpesa_feed_max_visible (branch setting,
 * further narrowed by the user's VIEW_TRANSACTIONS permission constraints).
 * Auto-refresh interval is mpesa_feed_refresh_seconds (5 or 10).
 */
#[Route('/{branch}/terminal', host: '{subdomain}.{domain}', requirements: [
    'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
    'domain'    => '.+',
    'branch'    => '[A-Za-z0-9-]+',
])]
class MpesaFeedController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DomainHelper $domains,
        private readonly TerminalBranchAccessService $terminalBranchAccess,
        private readonly Connection $db,
        private readonly CheckPermissionService $can,
        private readonly UserActivityLogService $activityLog,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Page
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/mpesa-feed', name: 'terminal_mpesa_feed', methods: ['GET'])]
    public function index(Request $request, string $branch): Response
    {
        $ctx = $this->resolveContext($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        [$session, $branchNode, $settings, $routeParams, $canSeeFullPhone, $effectiveMaxHours, $effectiveMaxVisible] = $ctx;

        if (!(bool) $settings['show_mpesa_feed']) {
            return $this->redirectToRoute('terminal_dashboard', $routeParams);
        }

        $payments = $this->fetchPayments(
            $session->company->id,
            $branchNode->id,
            $effectiveMaxHours,
            $effectiveMaxVisible,
        );

        $this->activityLog->view(
            session:     $session,
            module:      UserActivityLogService::MODULE_PAYMENTS,
            description: sprintf('Viewed M-Pesa feed (%d payments, last %dh)', count($payments), $effectiveMaxHours),
            permission:  'VIEW_TRANSACTIONS',
            subjectType: 'mpesa_feed',
            request:     $request,
        );

        return $this->render('terminal/mpesa_feed.html.twig', [
            'payments'           => $payments,
            'refresh_seconds'    => max(5, (int) $settings['mpesa_feed_refresh_seconds']),
            'max_hours'          => $effectiveMaxHours,
            'can_see_full_phone' => $canSeeFullPhone,
            'route_params'       => $routeParams,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Poll — JSON endpoint for live auto-refresh
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/mpesa-feed/poll', name: 'terminal_mpesa_feed_poll', methods: ['GET'])]
    public function poll(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->resolveContext($request, $branch);
        if ($ctx instanceof Response) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        [$session, $branchNode, $settings, , $canSeeFullPhone, $effectiveMaxHours, $effectiveMaxVisible] = $ctx;

        if (!(bool) $settings['show_mpesa_feed']) {
            return new JsonResponse(['error' => 'disabled'], 403);
        }

        $payments = $this->fetchPayments(
            $session->company->id,
            $branchNode->id,
            $effectiveMaxHours,
            $effectiveMaxVisible,
        );

        // Format for JSON; phone display respects VIEW_FULL_CUSTOMER_PHONE permission
        $out = array_map(static function (array $p) use ($canSeeFullPhone): array {
            $msisdn = (string) ($p['msisdn'] ?? '');
            $phone  = $msisdn !== ''
                ? ($canSeeFullPhone ? $msisdn : ('···' . substr($msisdn, -3)))
                : '';
            return [
                'id'              => (int)   $p['id'],
                'transaction_id'  => (string) ($p['transaction_id'] ?? ''),
                'amount'          => (float)  $p['amount'],
                'first_name'      => (string) ($p['first_name'] ?? ''),
                'masked_phone'    => $phone,
                'created_at'      => (string) $p['created_at'],
                'is_claimed'      => (bool)   $p['is_claimed'],
                'loyalty_awarded' => (bool)   $p['loyalty_auto_awarded'],
                'loyalty_points'  => (float)  ($p['loyalty_points_awarded'] ?? 0),
                'method'          => (string) ($p['method'] ?? ''),
            ];
        }, $payments);

        return new JsonResponse([
            'payments'        => $out,
            'ts'              => time(),
            'refresh_seconds' => max(5, (int) $settings['mpesa_feed_refresh_seconds']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolves session, branch access, company feed settings, and per-user
     * permission constraints for the M-Pesa feed.
     *
     * Returns: [session, branchNode, settings, routeParams, canSeeFullPhone, effectiveMaxHours, effectiveMaxVisible]
     *
     * @return array{0:mixed,1:mixed,2:array<string,mixed>,3:array<string,string>,4:bool,5:int,6:int}|Response
     */
    private function resolveContext(Request $request, string $branch): array|Response
    {
        $token      = $request->cookies->get('angavu_pos_token') ?: null;
        $terminal   = (string) $request->cookies->get('angavu_terminal', '');
        $subdomain  = $this->domains->getSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        $routeParams = [
            'subdomain' => $subdomain ?? 'unknown',
            'domain'    => $baseDomain,
            'branch'    => $branch,
        ];

        if ($token === null || $subdomain === null) {
            return $this->redirectToRoute('terminal_login_page', $routeParams);
        }

        try {
            $session    = $this->auth->validateSession($token);
            $branchNode = $this->terminalBranchAccess->resolveBranchNode($session->company->id, $branch);

            if ($branchNode === null
                || !$this->terminalBranchAccess->terminalMatchesBranch($session->company->id, $terminal, $branchNode->id)
                || !$this->terminalBranchAccess->userAssignedToBranch($session->user->id, $branchNode->id)
            ) {
                return $this->redirectToRoute('terminal_login_page', $routeParams);
            }
        } catch (AuthException) {
            return $this->redirectToRoute('terminal_login_page', $routeParams);
        }

        // Attach branch so CheckPermissionService uses the branch-aware path
        $session->branch = $branchNode;

        // Gate: user must hold VIEW_TRANSACTIONS to access the feed
        if (!$this->can->check($session, 'VIEW_TRANSACTIONS')) {
            return $this->redirectToRoute('terminal_dashboard', $routeParams);
        }

        $settings = $this->db->fetchAssociative(
            'SELECT show_mpesa_feed,
                    mpesa_feed_refresh_seconds,
                    mpesa_feed_max_hours,
                    mpesa_feed_max_visible
               FROM pos_terminal_settings
              WHERE company_id = :company_id
                AND branch_id  = :branch_id
              LIMIT 1',
            ['company_id' => $session->company->id, 'branch_id' => $branchNode->id],
        ) ?: [
            'show_mpesa_feed'            => 0,
            'mpesa_feed_refresh_seconds' => 5,
            'mpesa_feed_max_hours'       => 24,
            'mpesa_feed_max_visible'     => 50,
        ];

        // Company settings are the absolute ceiling.
        // Permission constraints narrow that further per role.
        $companyMaxHours   = max(1, (int) $settings['mpesa_feed_max_hours']);
        $companyMaxVisible = max(1, (int) $settings['mpesa_feed_max_visible']);

        $permHours   = $this->can->constraint($session, 'VIEW_TRANSACTIONS', 'max_hours_history',        null);
        $permVisible = $this->can->constraint($session, 'VIEW_TRANSACTIONS', 'max_transactions_visible', null);

        $effectiveMaxHours   = $permHours   !== null ? min((int) $permHours,   $companyMaxHours)   : $companyMaxHours;
        $effectiveMaxVisible = $permVisible !== null ? min((int) $permVisible,  $companyMaxVisible) : $companyMaxVisible;

        // Phone visibility: only show full number when user holds VIEW_FULL_CUSTOMER_PHONE
        $canSeeFullPhone = $this->can->check($session, 'VIEW_FULL_CUSTOMER_PHONE');

        return [$session, $branchNode, $settings, $routeParams, $canSeeFullPhone, $effectiveMaxHours, $effectiveMaxVisible];
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchPayments(int $companyId, int $branchId, int $maxHours, int $maxVisible): array
    {
        // Compute cutoff in PHP to avoid INTERVAL param-binding issues
        $cutoff = (new \DateTimeImmutable())->modify("-{$maxHours} hours")->format('Y-m-d H:i:s');
        $limit  = max(1, $maxVisible);

        return $this->db->fetchAllAssociative(
            "SELECT mp.id,
                    mp.transaction_id,
                    mp.msisdn,
                    mp.amount,
                    mp.first_name,
                    mp.short_code,
                    mp.method,
                    mp.created_at,
                    mp.status_code,
                    mp.loyalty_auto_awarded,
                    mp.loyalty_points_awarded,
                    COALESCE(mp.claimed, 0) AS is_claimed
               FROM mpesa_payments mp
              WHERE mp.company_id  = :company_id
                AND mp.branch_id   = :branch_id
                AND mp.status_code = 0
                AND mp.created_at >= :cutoff
              ORDER BY mp.created_at DESC
              LIMIT {$limit}",
            [
                'company_id' => $companyId,
                'branch_id'  => $branchId,
                'cutoff'     => $cutoff,
            ],
        );
    }
}
