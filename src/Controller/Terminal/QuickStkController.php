<?php

declare(strict_types=1);

namespace App\Controller\Terminal;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Payment\MpesaApiService;
use App\Services\Payment\PaymentConfigService;
use App\Services\Patronr\TransactionRecordService;
use App\Services\Customer\CustomerService;
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
 * Quick STK Prompt — send an M-Pesa STK push directly from the terminal
 * without going through the full checkout flow.
 *
 * Visibility gated by pos_terminal_settings.show_quick_stk = 1.
 */
#[Route('/{branch}/terminal', host: '{subdomain}.{domain}', requirements: [
    'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
    'domain'    => '.+',
    'branch'    => '[A-Za-z0-9-]+',
])]
class QuickStkController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DomainHelper $domains,
        private readonly TerminalBranchAccessService $terminalBranchAccess,
        private readonly Connection $db,
        private readonly PaymentConfigService $paymentConfigs,
        private readonly MpesaApiService $mpesa,
        private readonly TransactionRecordService $transactions,
        private readonly CustomerService $customers,
        private readonly CheckPermissionService $can,
        private readonly UserActivityLogService $activityLog,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Page
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/quick-stk', name: 'terminal_quick_stk', methods: ['GET'])]
    public function index(Request $request, string $branch): Response
    {
        $ctx = $this->resolveContext($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        [$session, $branchNode, $settings, $routeParams] = $ctx;

        if (!(bool) $settings['show_quick_stk']) {
            return $this->redirectToRoute('terminal_dashboard', $routeParams);
        }

        $areas = $this->db->fetchAllAssociative(
            'SELECT id, name FROM areas
              WHERE company_id = :company_id
                AND branch_id  = :branch_id
                AND status     = \'active\'
                AND deleted_at IS NULL
              ORDER BY name ASC',
            ['company_id' => $session->company->id, 'branch_id' => $branchNode->id],
        );

        $mpesaConfigs = $this->paymentConfigs->getActiveConfigs($session->company->id, $branchNode->id);
        $mpesaConfigs = array_values(array_filter(
            $mpesaConfigs,
            fn($c) => $c->methodKey === 'mpesa' && in_array('stk_push', $c->integrationModes, true),
        ));

        if (empty($mpesaConfigs)) {
            // No STK-capable config — redirect back, feature shouldn't be visible
            return $this->redirectToRoute('terminal_dashboard', $routeParams);
        }

        return $this->render('terminal/quick_stk.html.twig', [
            'areas'        => $areas,
            'mpesa_configs' => array_map(fn($c) => [
                'id'        => $c->configId,
                'label'     => $c->label,
                'shortcode' => $c->cfg('type') === 'buygoods'
                    ? ($c->cfg('till_number') ?: $c->cfg('shortcode'))
                    : $c->cfg('shortcode'),
                'type'      => $c->cfg('type', 'paybill'),
            ], $mpesaConfigs),
            'route_params' => $routeParams,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Send STK push
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/quick-stk/send', name: 'terminal_quick_stk_send', methods: ['POST'])]
    public function send(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->resolveContext($request, $branch);
        if ($ctx instanceof Response) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        [$session, $branchNode, $settings, $routeParams] = $ctx;

        if (!(bool) $settings['show_quick_stk']) {
            return new JsonResponse(['success' => false, 'message' => 'Feature disabled.'], 403);
        }

        $phone    = trim((string) $request->request->get('phone', ''));
        $amount   = (float) $request->request->get('amount', 0);
        $configId = (int) $request->request->get('config_id', 0);
        $areaId   = (int) $request->request->get('area_id', 0) ?: null;

        // Validate phone
        $normalised = $this->customers->normalizePhone($phone);
        if ($normalised === null) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid phone number.'], 400);
        }

        // Validate amount
        if ($amount < 1) {
            return new JsonResponse(['success' => false, 'message' => 'Amount must be at least KES 1.'], 400);
        }

        // Load mpesa config
        $config = $this->paymentConfigs->getConfig($configId, 'mpesa');
        if ($config === null || !in_array('stk_push', $config->integrationModes, true)) {
            return new JsonResponse(['success' => false, 'message' => 'Payment configuration not found.'], 400);
        }

        // Create a pos_transaction record so patronrapis callback can complete it
        $txnId = $this->transactions->create(
            companyId:          $session->company->id,
            branchId:           $branchNode->id,
            areaId:             $areaId > 0 ? $areaId : null,
            terminalIdentifier: (string) $request->cookies->get('angavu_terminal', ''),
            cashierUserId:      $session->user->id,
            paymentMethodId:    $config->paymentMethodId,
            amount:             $amount,
            description:        'Quick STK',
            mode:               'api',
            mpesaConfigId:      $config->configId,
        );

        // Build callback URL
        $callbackUrl = $config->cfg('callback_url')
            ?: rtrim($request->getSchemeAndHttpHost(), '/') . '/webhook/mpesa/callback/' . $session->company->id;

        // Send STK push
        $result = $this->mpesa->stkPush(
            config:      $config,
            phone:       $normalised,
            amount:      $amount,
            accountRef:  'Quick Pay',
            callbackUrl: $callbackUrl,
        );

        if (!$result['success']) {
            $this->transactions->markFailed($txnId, $result['raw'] ?: null);
            return new JsonResponse([
                'success' => false,
                'message' => $result['response_description']
                    ?: ($result['raw']['errorMessage'] ?? ($result['raw']['ResultDesc'] ?? 'STK push failed.')),
                'raw'     => $result['raw'],
            ]);
        }

        // Transition transaction to processing state with checkout_request_id
        $this->transactions->markProcessing(
            transactionId:     $txnId,
            checkoutRequestId: $result['checkout_request_id'],
            merchantRequestId: $result['merchant_request_id'],
        );

        $posId = (int) ($this->db->fetchOne(
            'SELECT pos_id FROM pos_transactions WHERE id = :id AND company_id = :company_id LIMIT 1',
            ['id' => $txnId, 'company_id' => $session->company->id],
        ) ?: 0) ?: null;

        $this->activityLog->send(
            session:     $session,
            module:      UserActivityLogService::MODULE_PAYMENTS,
            description: sprintf(
                'Sent Quick STK push of KES %s to ···%s (#%s)',
                number_format($amount, 2),
                substr($normalised, -3),
                $posId ?? $txnId,
            ),
            permission:  'SEND_STK_PUSH',
            subjectType: 'pos_transaction',
            subjectId:   $txnId,
            metadata:    [
                'amount'              => $amount,
                'masked_phone'        => '···' . substr($normalised, -3),
                'checkout_request_id' => $result['checkout_request_id'],
                'pos_id'              => $posId,
                'pos_transaction_id'  => $txnId,
                'source'              => 'quick_stk',
            ],
            request:     $request,
        );

        return new JsonResponse([
            'success'             => true,
            'checkout_request_id' => $result['checkout_request_id'],
            'masked_phone'        => '···' . substr($normalised, -3),
            'amount'              => $amount,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Poll status
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/quick-stk/status', name: 'terminal_quick_stk_status', methods: ['GET'])]
    public function status(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->resolveContext($request, $branch);
        if ($ctx instanceof Response) {
            return new JsonResponse(['status' => 'error'], 401);
        }

        [$session, , , ] = $ctx;

        $checkoutId = trim((string) $request->query->get('checkout_id', ''));
        if ($checkoutId === '') {
            return new JsonResponse(['status' => 'pending']);
        }

        $row = $this->db->fetchAssociative(
            'SELECT pt.status, pt.api_receipt, pt.amount, pt.msisdn, pt.api_raw_response
               FROM pos_transactions pt
              WHERE pt.api_checkout_request_id = :checkout_id
                AND pt.company_id              = :company_id
              ORDER BY pt.id DESC
              LIMIT 1',
            ['checkout_id' => $checkoutId, 'company_id' => $session->company->id],
        );

        if (!$row) {
            return new JsonResponse(['status' => 'pending']);
        }

        $msisdn = (string) ($row['msisdn'] ?? '');

        // Extract real failure reason from Safaricom callback payload if available
        $failMessage = null;
        if ($row['status'] === 'failed') {
            $raw = $row['api_raw_response'] ? json_decode((string) $row['api_raw_response'], true) : null;
            $failMessage = ($raw['ResultDesc'] ?? null)
                ?: ($raw['Body']['stkCallback']['ResultDesc'] ?? null)
                ?: ($raw['errorMessage'] ?? null)
                ?: 'Payment was declined or not completed.';
        }

        return new JsonResponse([
            'status'       => $row['status'],
            'receipt'      => $row['api_receipt'] ?? null,
            'amount'       => (float) ($row['amount'] ?? 0),
            'masked_phone' => $msisdn !== '' ? ('···' . substr($msisdn, -3)) : '',
            'message'      => $failMessage,
            'raw'          => $row['status'] === 'failed' && $row['api_raw_response']
                ? json_decode((string) $row['api_raw_response'], true)
                : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{0:mixed,1:mixed,2:array<string,mixed>,3:array<string,string>}|Response */
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

        // Attach branch for branch-aware permission checks
        $session->branch = $branchNode;

        // Gate: user must hold SEND_STK_PUSH to use Quick STK
        if (!$this->can->check($session, 'SEND_STK_PUSH')) {
            return $this->redirectToRoute('terminal_dashboard', $routeParams);
        }

        $settings = $this->db->fetchAssociative(
            'SELECT show_quick_stk, show_mpesa_feed
               FROM pos_terminal_settings
              WHERE company_id = :company_id
                AND branch_id  = :branch_id
              LIMIT 1',
            ['company_id' => $session->company->id, 'branch_id' => $branchNode->id],
        ) ?: ['show_quick_stk' => 0, 'show_mpesa_feed' => 0];

        return [$session, $branchNode, $settings, $routeParams];
    }
}
