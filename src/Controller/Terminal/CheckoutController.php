<?php

declare(strict_types=1);

namespace App\Controller\Terminal;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Auth\DTO\AuthResult;
use App\Services\Auth\Exception\AuthException;
use App\Services\Customer\CustomerService;
use App\Services\Feature\TenantFeatureAccessService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Patronr\CheckoutService;
use App\Services\Patronr\DTO\CheckoutDraft;
use App\Services\Patronr\TransactionRecordService;
use App\Services\Payment\MpesaApiService;
use App\Services\Payment\PaymentConfigService;
use App\Services\Revenue\CatalogService;
use App\Services\Revenue\EventService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Terminal\TerminalBranchAccessService;
use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Multi-step POS checkout flow.
 *
 * Step 1 — Area + Amount + Description
 * Step 2 — Select Payment Method
 * Step 3 — Process Payment (cash / STK push / manual)
 * Step 4 — Loyalty Capture (phone + points award)
 * Step 5 — Success screen
 */
#[Route('/{branch}/terminal/checkout', host: '{subdomain}.{domain}', requirements: [
    'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
    'domain'    => '.+',
    'branch'    => '[A-Za-z0-9-]+',
])]
class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DomainHelper $domains,
        private readonly CheckoutService $checkout,
        private readonly TransactionRecordService $transactions,
        private readonly PaymentConfigService $paymentConfigs,
        private readonly LoyaltyService $loyalty,
        private readonly TenantFeatureAccessService $features,
        private readonly CustomerService $customers,
        private readonly MpesaApiService $mpesa,
        private readonly Connection $db,
        private readonly TerminalBranchAccessService $terminalBranchAccess,
        private readonly UserActivityLogService $activityLog,
        private readonly CheckPermissionService $can,
        private readonly CatalogService $catalog,
        private readonly EventService $eventService,
    ) {}

    // =========================================================================
    // START — discard existing draft, create fresh, go to step 1
    // =========================================================================

    #[Route('/start', name: 'terminal_checkout_start', methods: ['GET', 'POST'])]
    public function start(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $this->checkout->startDraft(
            terminalIdentifier: $ctx['terminal'],
            companyId:          $ctx['session']->company->id,
            branchId:           $ctx['session']->branch->id,
            cashierUserId:      $ctx['session']->user->id,
        );

        return $this->redirectToRoute('terminal_checkout_step1', $ctx['route_params']);
    }

    // =========================================================================
    // STEP 1 — Area + Amount + Description
    // =========================================================================

    #[Route('/1', name: 'terminal_checkout_step1', methods: ['GET'])]
    public function step1(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $draft = $this->getOrStartDraft($ctx);

        $areas = $this->db->fetchAllAssociative(
            'SELECT id, name FROM areas
              WHERE branch_id = :branch_id AND is_transactional = 1
                AND status = \'active\' AND deleted_at IS NULL
              ORDER BY name ASC',
            ['branch_id' => $ctx['session']->branch->id],
        );

        // Load terminal settings for this branch
        $terminalSettings = $this->db->fetchAssociative(
            'SELECT enable_pos_pricing, show_events_at_terminal
               FROM pos_terminal_settings
              WHERE company_id = :company_id AND branch_id = :branch_id
              LIMIT 1',
            ['company_id' => $ctx['session']->company->id, 'branch_id' => $ctx['session']->branch->id],
        ) ?: ['enable_pos_pricing' => 0, 'show_events_at_terminal' => 1];

        $catalogItems = $this->catalog->listActiveForTerminal($ctx['session']->branch->id);
        $activeEvents = $terminalSettings['show_events_at_terminal']
            ? $this->eventService->findAllActive($ctx['session']->branch->id)
            : [];

        return $this->render('terminal/checkout/step1_info.html.twig', [
            'draft'             => $draft,
            'areas'             => $areas,
            'catalog_items'     => $catalogItems,
            'active_events'     => $activeEvents,
            'terminal_settings' => $terminalSettings,
            'can_discount'      => $this->can->check($ctx['session'], 'apply_discount'),
            'route_params'      => $ctx['route_params'],
        ]);
    }

    #[Route('/1', name: 'terminal_checkout_save1', methods: ['POST'])]
    public function saveStep1(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $amount       = (int) $request->request->get('amount', 0);
        $areaId       = $request->request->get('area_id') ? (int) $request->request->get('area_id') : null;
        $description  = trim((string) $request->request->get('description', ''));
        $eventId      = $request->request->get('event_id') ? (int) $request->request->get('event_id') : null;

        // Multi-select catalog items — submitted as catalog_item_ids (comma-separated string)
        $catalogItemIdsRaw = trim((string) $request->request->get('catalog_item_ids', ''));
        $catalogItemIds    = $catalogItemIdsRaw !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $catalogItemIdsRaw)), fn($id) => $id > 0))
            : [];
        $catalogItemId     = !empty($catalogItemIds) ? $catalogItemIds[0] : null;
        $coversRaw      = $request->request->get('covers', '');
        $covers         = ($coversRaw !== '' && ctype_digit((string) $coversRaw)) ? (int) $coversRaw : null;

        // Discount (permission-gated; validated server-side regardless of what the UI sends)
        $grossAmountRaw = (int) $request->request->get('gross_amount', 0);
        $grossAmount    = $grossAmountRaw > 0 ? $grossAmountRaw : $amount;
        $discountAmount = 0;
        $discountReason = null;
        $discountRaw    = (int) $request->request->get('discount_amount', 0);
        if ($discountRaw > 0 && $discountRaw < $grossAmount && $this->can->check($ctx['session'], 'apply_discount')) {
            $reasonRaw = trim((string) $request->request->get('discount_reason', ''));
            if ($reasonRaw !== '') {
                $discountAmount = $discountRaw;
                $discountReason = $reasonRaw;
            }
        }

        if ($amount <= 0) {
            return $this->redirectToRoute('terminal_checkout_step1', $ctx['route_params']);
        }

        $this->checkout->advanceDraft(
            terminalIdentifier: $ctx['terminal'],
            companyId:          $ctx['session']->company->id,
            nextStep:           2,
            newPayload:         [
                'amount'           => $amount,
                'gross_amount'     => $grossAmount,
                'discount_amount'  => $discountAmount,
                'discount_reason'  => $discountReason,
                'area_id'          => $areaId,
                'description'      => $description !== '' ? $description : null,
                'catalog_item_id'  => $catalogItemId,
                'catalog_item_ids' => $catalogItemIds,
                'event_id'         => $eventId,
                'covers'           => $covers,
            ],
        );

        return $this->redirectToRoute('terminal_checkout_step2', $ctx['route_params']);
    }

    // =========================================================================
    // STEP 2 — Payment Method
    // =========================================================================

    #[Route('/2', name: 'terminal_checkout_step2', methods: ['GET'])]
    public function step2(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $draft = $this->requireDraft($ctx);
        if ($draft instanceof Response) {
            return $draft;
        }

        $methods = $this->paymentConfigs->getActiveConfigs(
            $ctx['session']->company->id,
            $ctx['session']->branch->id,
        );

        if (!$ctx['loyalty_enabled']) {
            $methods = array_values(array_filter($methods, fn($m) => $m->methodKey !== 'loyalty'));
        }

        return $this->render('terminal/checkout/step2_payment.html.twig', [
            'draft'        => $draft,
            'methods'      => $methods,
            'route_params' => $ctx['route_params'],
        ]);
    }

    #[Route('/2', name: 'terminal_checkout_save2', methods: ['POST'])]
    public function saveStep2(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $draft = $this->requireDraft($ctx);
        if ($draft instanceof Response) {
            return $draft;
        }

        $splitsJson = trim((string) $request->request->get('splits_json', ''));
        $splits     = $splitsJson !== '' ? json_decode($splitsJson, true) : null;

        // Legacy fallback: single method POST (config_id + method_key)
        if (!is_array($splits) || count($splits) === 0) {
            $configId  = (int) $request->request->get('config_id', 0);
            $methodKey = trim((string) $request->request->get('method_key', ''));
            if ($configId <= 0 || $methodKey === '') {
                return $this->redirectToRoute('terminal_checkout_step2', $ctx['route_params']);
            }
            $splits = [[
                'config_id'  => $configId,
                'method_key' => $methodKey,
                'label'      => $methodKey,
                'amount'     => (float) $draft->get('amount', 0),
            ]];
        }

        // Validate splits total matches draft amount (within 1 KES rounding)
        $draftAmount   = (float) $draft->get('amount', 0);
        $splitsTotal   = array_sum(array_column($splits, 'amount'));
        if (abs($splitsTotal - $draftAmount) > 1.0) {
            return $this->redirectToRoute('terminal_checkout_step2', $ctx['route_params']);
        }

        // Sanitise split data
        $cleanSplits = [];
        foreach ($splits as $s) {
            $entry = [
                'config_id'  => (int) ($s['config_id'] ?? 0),
                'method_key' => (string) ($s['method_key'] ?? ''),
                'label'      => (string) ($s['label'] ?? ''),
                'amount'     => (float) ($s['amount'] ?? 0),
            ];
            // Preserve loy_phone for loyalty splits (used in step 4 pre-fill)
            if (!empty($s['loy_phone'])) {
                $entry['loy_phone'] = (string) $s['loy_phone'];
            }
            $cleanSplits[] = $entry;
        }

        // Loyalty redemption always processes last — other payments must succeed first
        usort($cleanSplits, fn($a, $b) => ($a['method_key'] === 'loyalty' ? 1 : 0) - ($b['method_key'] === 'loyalty' ? 1 : 0));

        $this->checkout->advanceDraft(
            terminalIdentifier: $ctx['terminal'],
            companyId:          $ctx['session']->company->id,
            nextStep:           3,
            newPayload:         [
                'splits'      => $cleanSplits,
                'split_index' => 0,
            ],
        );

        return $this->redirectToRoute('terminal_checkout_step3', $ctx['route_params']);
    }

    // =========================================================================
    // STEP 3 — Process Payment
    // =========================================================================

    #[Route('/3', name: 'terminal_checkout_step3', methods: ['GET'])]
    public function step3(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $draft = $this->requireDraft($ctx);
        if ($draft instanceof Response) {
            return $draft;
        }

        // If draft has advanced past step 3 (e.g. after last STK push), forward correctly
        if ($draft->step > 3) {
            $forwardRoute = match ($draft->step) {
                4 => 'terminal_checkout_step4',
                5 => 'terminal_checkout_step5',
                default => 'terminal_checkout_step4',
            };
            return $this->redirectToRoute($forwardRoute, $ctx['route_params']);
        }

        $splits     = $draft->get('splits', []);
        $splitIndex = (int) $draft->get('split_index', 0);

        // Legacy support: drafts created before split system
        if (empty($splits)) {
            $methodKey = (string) $draft->get('payment_method_key', '');
            $configId  = (int)    $draft->get('config_id', 0);
            if ($methodKey !== '' && $configId > 0) {
                $splits = [[
                    'config_id'  => $configId,
                    'method_key' => $methodKey,
                    'label'      => $methodKey,
                    'amount'     => (float) $draft->get('amount', 0),
                ]];
                $splitIndex = 0;
            }
        }

        $currentSplit = $splits[$splitIndex] ?? null;
        if ($currentSplit === null) {
            return $this->redirectToRoute('terminal_checkout_step2', $ctx['route_params']);
        }

        // Create pos_transaction on first visit to step 3 (before any payment)
        if ($draft->posTransactionId === null) {
            // Primary method = first non-loyalty split
            $primarySplit = $currentSplit;
            foreach ($splits as $s) {
                if ($s['method_key'] !== 'loyalty') {
                    $primarySplit = $s;
                    break;
                }
            }

            // Resolve revenue_source_type from draft catalog_item_id (primary / first)
            $draftCatalogId       = $draft->get('catalog_item_id') ? (int) $draft->get('catalog_item_id') : null;
            $draftCatalogIds      = is_array($draft->get('catalog_item_ids')) ? $draft->get('catalog_item_ids') : [];
            $revenueSourceType    = $draftCatalogId ? 'catalog_item' : null;

            // Discount fields from draft
            $draftDiscountAmount  = (int) ($draft->get('discount_amount', 0) ?? 0);
            $draftGrossAmount     = (float) ($draft->get('gross_amount') ?: $draft->get('amount', 0));

            $txnId = $this->transactions->create(
                companyId:           $ctx['session']->company->id,
                branchId:            $ctx['session']->branch->id,
                areaId:              $draft->get('area_id'),
                terminalIdentifier:  $ctx['terminal'],
                cashierUserId:       $ctx['session']->user->id,
                paymentMethodId:     $this->resolvePaymentMethodId($primarySplit['method_key']),
                amount:              (float) $draft->get('amount', 0),
                description:         $draft->get('description'),
                mode:                'manual',
                mpesaConfigId:       $primarySplit['method_key'] === 'mpesa'   ? (int) $primarySplit['config_id'] : null,
                pesapalConfigId:     $primarySplit['method_key'] === 'pesapal' ? (int) $primarySplit['config_id'] : null,
                revenueSourceType:   $revenueSourceType,
                revenueSourceId:     $draftCatalogId,
                eventId:             $draft->get('event_id') ? (int) $draft->get('event_id') : null,
                covers:              $draft->get('covers') !== null ? (int) $draft->get('covers') : null,
                grossAmount:         $draftGrossAmount,
                discountAmount:      (float) $draftDiscountAmount,
                discountReason:      $draft->get('discount_reason'),
                discountByUserId:    $draftDiscountAmount > 0 ? $ctx['session']->user->id : null,
            );

            // Write multi-item junction rows if the table exists (segment 40)
            if (!empty($draftCatalogIds) && count($draftCatalogIds) > 1) {
                try {
                    foreach ($draftCatalogIds as $ciId) {
                        $this->db->executeStatement(
                            'INSERT IGNORE INTO pos_transaction_catalog_items (transaction_id, catalog_item_id) VALUES (?, ?)',
                            [$txnId, $ciId],
                        );
                    }
                } catch (\Throwable) {
                    // Junction table not yet applied — silently skip until segment 40 runs
                }
            }

            $this->checkout->advanceDraft(
                terminalIdentifier: $ctx['terminal'],
                companyId:          $ctx['session']->company->id,
                nextStep:           3,
                newPayload:         [],
                posTransactionId:   $txnId,
            );

            // Reload with txnId set
            $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);
        }

        $paymentConfig = $currentSplit['method_key'] === 'loyalty'
            ? $this->paymentConfigs->getConfig((int) $currentSplit['config_id'], 'loyalty')
            : $this->paymentConfigs->getConfig((int) $currentSplit['config_id'], $currentSplit['method_key']);

        return $this->render('terminal/checkout/step3_process.html.twig', [
            'draft'          => $draft,
            'payment'        => $paymentConfig,
            'splits'         => $splits,
            'split_index'    => $splitIndex,
            'total_splits'   => count($splits),
            'split_amount'   => (float) $currentSplit['amount'],
            'route_params'   => $ctx['route_params'],
            'csrf_token'     => $this->generateToken('checkout_stk'),
        ]);
    }

    /** Cash / bank / pesapal manual confirm — also handles split advancement */
    #[Route('/3/confirm', name: 'terminal_checkout_confirm', methods: ['POST'])]
    public function confirm(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $draft = $this->requireDraft($ctx);
        if ($draft instanceof Response) {
            return $draft;
        }

        $splits     = $draft->get('splits', []);
        $splitIndex = (int) $draft->get('split_index', 0);
        $nextIndex  = $splitIndex + 1;
        $txnId      = $draft->posTransactionId;

        $mpesaCode        = trim((string) $request->request->get('mpesa_code',        '')) ?: null;
        $cardCode         = trim((string) $request->request->get('card_code',         '')) ?: null;
        $otherDescription = trim((string) $request->request->get('other_description', '')) ?: null;
        $otherCode        = trim((string) $request->request->get('other_code',        '')) ?: null;

        // Record this split leg
        if ($txnId && isset($splits[$splitIndex])) {
            $leg = $splits[$splitIndex];

            $apiReceipt = match ($leg['method_key']) {
                'mpesa' => $mpesaCode,
                'card'  => $cardCode,
                'other' => $otherCode,
                default => null,
            };

            $this->transactions->recordSplitLeg(
                posTransactionId: $txnId,
                splitIndex:       $splitIndex,
                paymentMethodId:  $this->resolvePaymentMethodId($leg['method_key']),
                methodKey:        $leg['method_key'],
                amount:           (float) $leg['amount'],
                mpesaConfigId:    isset($leg['config_id']) && $leg['method_key'] === 'mpesa' ? (int) $leg['config_id'] : null,
                apiReceipt:       $apiReceipt,
                paymentNotes:     $leg['method_key'] === 'other' ? $otherDescription : null,
            );

            // Propagate receipt code to the parent transaction row for receipts/reports
            if ($apiReceipt !== null && $txnId) {
                $this->db->executeStatement(
                    'UPDATE pos_transactions SET api_receipt = :code, updated_at = NOW() WHERE id = :id',
                    ['code' => $apiReceipt, 'id' => $txnId],
                );
            }
        }

        if ($nextIndex < count($splits)) {
            // More splits to process — advance to next split
            $this->checkout->advanceDraft(
                terminalIdentifier: $ctx['terminal'],
                companyId:          $ctx['session']->company->id,
                nextStep:           3,
                newPayload:         ['split_index' => $nextIndex],
            );
            return $this->redirectToRoute('terminal_checkout_step3', $ctx['route_params']);
        }

        // All splits done — mark transaction complete and advance to step 4
        if ($txnId) {
            $this->transactions->markComplete($txnId);
        }

        $this->checkout->advanceDraft(
            terminalIdentifier: $ctx['terminal'],
            companyId:          $ctx['session']->company->id,
            nextStep:           4,
            newPayload:         [],
        );

        return $this->redirectToRoute('terminal_checkout_step4', $ctx['route_params']);
    }

    /** JSON — trigger STK push */
    #[Route('/3/stk-push', name: 'terminal_checkout_stk_push', methods: ['POST'])]
    public function stkPush(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->requirePosJson($request, $branch);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }

        // Gate: must hold SEND_STK_PUSH
        if (!$this->can->check($ctx['session'], 'SEND_STK_PUSH')) {
            return $this->json(['success' => false, 'message' => 'You do not have permission to send STK pushes.'], 403);
        }

        $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);
        if ($draft === null) {
            return $this->json(['success' => false, 'message' => 'No active checkout.'], 400);
        }

        $txnId      = $draft->posTransactionId;
        $splits     = $draft->get('splits', []);
        $splitIndex = (int) $draft->get('split_index', 0);
        $amount     = isset($splits[$splitIndex]) ? (float) $splits[$splitIndex]['amount'] : (float) $draft->get('amount', 0);
        // Read config_id from current split (preferred) or legacy draft field
        $configId   = isset($splits[$splitIndex]) ? (int) $splits[$splitIndex]['config_id'] : (int) $draft->get('config_id', 0);
        $phone      = trim((string) $request->request->get('phone', ''));

        $normalised = $this->customers->normalizePhone($phone);
        if ($normalised === null) {
            return $this->json(['success' => false, 'message' => 'Invalid phone number.'], 400);
        }

        $config = $this->paymentConfigs->getConfig($configId, 'mpesa');
        if ($config === null) {
            return $this->json(['success' => false, 'message' => 'Payment configuration not found.'], 400);
        }

        // Build callback URL — uses the company_id embedded path
        $callbackUrl = $config->cfg('callback_url')
            ?: rtrim($request->getSchemeAndHttpHost(), '/') . '/webhook/mpesa/callback/' . $ctx['session']->company->id;

        $result = $this->mpesa->stkPush(
            config:       $config,
            phone:        $normalised,
            amount:       $amount,
            accountRef:   (string) ($draft->get('description') ?: 'Payment'),
            callbackUrl:  $callbackUrl,
        );

        if ($result['success'] && $txnId) {
            $this->transactions->markProcessing(
                transactionId:   $txnId,
                checkoutRequestId: $result['checkout_request_id'],
                merchantRequestId: $result['merchant_request_id'],
            );

            // Store phone in draft for loyalty step
            $this->checkout->advanceDraft(
                terminalIdentifier: $ctx['terminal'],
                companyId:          $ctx['session']->company->id,
                nextStep:           3, // stay on step 3 — JS polls
                newPayload:         ['stk_phone' => $normalised],
            );

            $posId = $this->posId($txnId, $ctx['session']->company->id);
            $this->activityLog->send(
                session:     $ctx['session'],
                module:      UserActivityLogService::MODULE_PAYMENTS,
                description: sprintf(
                    'Sent STK push of KES %s to ···%s (checkout #%s)',
                    number_format($amount, 2),
                    substr($normalised, -3),
                    $posId ?? $txnId,
                ),
                permission:  'SEND_STK_PUSH',
                subjectType: 'pos_transaction',
                subjectId:   $txnId,
                metadata:    [
                    'amount'               => $amount,
                    'masked_phone'         => '···' . substr($normalised, -3),
                    'checkout_request_id'  => $result['checkout_request_id'],
                    'pos_id'               => $posId,
                    'pos_transaction_id'   => $txnId,
                ],
                request:     $request,
            );
        }

        return $this->json($result);
    }

    /** JSON — poll STK push status via pos_transactions */
    #[Route('/3/stk-status', name: 'terminal_checkout_stk_status', methods: ['GET'])]
    public function stkStatus(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->requirePosJson($request, $branch);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }

        $checkoutId = trim((string) $request->query->get('checkout_id', ''));
        if ($checkoutId === '') {
            return $this->json(['status' => 'pending']);
        }

        $row = $this->db->fetchAssociative(
            'SELECT status,
                    api_receipt,
                    loyalty_auto_awarded,
                    loyalty_points_awarded
               FROM pos_transactions
              WHERE api_checkout_request_id = :id
                AND company_id = :company_id
              ORDER BY id DESC LIMIT 1',
            ['id' => $checkoutId, 'company_id' => $ctx['session']->company->id],
        );

        if (!$row) {
            return $this->json(['status' => 'pending']);
        }

        // If complete → record split leg + advance to next split or step 4
        if ($row['status'] === 'complete') {
            $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);
            if ($draft && $draft->step <= 3) {
                $splits     = $draft->get('splits', []);
                $splitIndex = (int) $draft->get('split_index', 0);
                $nextIndex  = $splitIndex + 1;
                $txnId      = $draft->posTransactionId;

                // Record this STK split leg
                if ($txnId && isset($splits[$splitIndex])) {
                    $leg = $splits[$splitIndex];
                    $this->transactions->recordSplitLeg(
                        posTransactionId: $txnId,
                        splitIndex:       $splitIndex,
                        paymentMethodId:  $this->resolvePaymentMethodId($leg['method_key']),
                        methodKey:        $leg['method_key'],
                        amount:           (float) $leg['amount'],
                        mpesaConfigId:    isset($leg['config_id']) ? (int) $leg['config_id'] : null,
                        apiReceipt:       $row['api_receipt'] ?: null,
                    );
                }

                if ($nextIndex < count($splits)) {
                    $this->checkout->advanceDraft(
                        terminalIdentifier: $ctx['terminal'],
                        companyId:          $ctx['session']->company->id,
                        nextStep:           3,
                        newPayload:         ['split_index' => $nextIndex],
                    );
                } else {
                    $this->checkout->advanceDraft(
                        terminalIdentifier: $ctx['terminal'],
                        companyId:          $ctx['session']->company->id,
                        nextStep:           4,
                        newPayload:         [],
                    );
                }
            }
        }

        return $this->json([
            'status'         => $row['status'],
            'receipt'        => $row['api_receipt'],
            'auto_awarded'   => (bool) ($row['loyalty_auto_awarded'] ?? false),
            'points_awarded' => (int) ($row['loyalty_points_awarded'] ?? 0),
            'message'        => $row['status'] === 'failed' ? 'Payment was declined or not completed.' : null,
        ]);
    }

    /** JSON — poll mpesa_payments for unclaimed payments matching the expected amount (callback mode) */
    #[Route('/3/callback-status', name: 'terminal_checkout_callback_status', methods: ['GET'])]
    public function callbackStatus(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->requirePosJson($request, $branch);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }

        $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);
        if ($draft === null) {
            return $this->json(['status' => 'no_draft']);
        }

        $splits     = $draft->get('splits', []);
        $splitIndex = (int) $draft->get('split_index', 0);
        $amount     = isset($splits[$splitIndex]) ? (float) $splits[$splitIndex]['amount'] : (float) $draft->get('amount', 0);
        $configId   = isset($splits[$splitIndex]) ? (int) $splits[$splitIndex]['config_id'] : 0;

        // Resolve shortcode + branch for tighter matching
        $shortcode = null;
        if ($configId > 0) {
            $shortcode = $this->db->fetchOne(
                'SELECT shortcode FROM mpesa_configs WHERE id = :id AND company_id = :cid LIMIT 1',
                ['id' => $configId, 'cid' => $ctx['session']->company->id],
            ) ?: null;
        }
        $branchId = $ctx['session']->branch->id ?? null;

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, msisdn, first_name, amount, transaction_id
               FROM mpesa_payments
              WHERE company_id = :cid
                AND amount = :amount
                AND status_code = 0
                AND (claimed = 0 OR claimed IS NULL)
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                AND deleted_at IS NULL
                AND (:shortcode IS NULL OR short_code = :shortcode)
                AND (:branch_id IS NULL OR branch_id = :branch_id)
              ORDER BY id DESC',
            [
                'cid'       => $ctx['session']->company->id,
                'amount'    => $amount,
                'shortcode' => $shortcode,
                'branch_id' => $branchId,
            ],
        );

        if (empty($rows)) {
            return $this->json(['status' => 'pending']);
        }

        // Return all matching payments — cashier must explicitly claim one
        $payments = array_map(fn($r) => [
            'id'         => $r['id'],
            'name'       => $r['first_name'] ?: 'Customer',
            'phone_tail' => substr((string) $r['msisdn'], -3),
            'receipt'    => $r['transaction_id'],
            'amount'     => (float) $r['amount'],
        ], $rows);

        return $this->json([
            'status'   => 'matches',
            'payments' => $payments,
        ]);
    }

    /** POST — cashier claims a specific mpesa_payment row and advances the draft */
    #[Route('/3/claim-payment', name: 'terminal_checkout_claim_payment', methods: ['POST'])]
    public function claimPayment(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->requirePosJson($request, $branch);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }

        $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);
        if ($draft === null) {
            return $this->json(['ok' => false, 'error' => 'no_draft']);
        }

        $mpesaPaymentId = (int) $request->request->get('mpesa_payment_id', 0);
        if ($mpesaPaymentId <= 0) {
            return $this->json(['ok' => false, 'error' => 'missing_id']);
        }

        // Fetch the row — verify it belongs to this company, is unclaimed, and matches amount + shortcode + branch
        $splits     = $draft->get('splits', []);
        $splitIndex = (int) $draft->get('split_index', 0);
        $amount     = isset($splits[$splitIndex]) ? (float) $splits[$splitIndex]['amount'] : (float) $draft->get('amount', 0);
        $configId   = isset($splits[$splitIndex]) ? (int) $splits[$splitIndex]['config_id'] : 0;

        $shortcode = null;
        if ($configId > 0) {
            $shortcode = $this->db->fetchOne(
                'SELECT shortcode FROM mpesa_configs WHERE id = :id AND company_id = :cid LIMIT 1',
                ['id' => $configId, 'cid' => $ctx['session']->company->id],
            ) ?: null;
        }
        $branchId = $ctx['session']->branch->id ?? null;

        $row = $this->db->fetchAssociative(
            'SELECT id, msisdn, transaction_id, amount, first_name,
                    loyalty_auto_awarded, loyalty_points_awarded
               FROM mpesa_payments
              WHERE id = :id
                AND company_id = :cid
                AND amount = :amount
                AND status_code = 0
                AND (claimed = 0 OR claimed IS NULL)
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                AND deleted_at IS NULL
                AND (:shortcode IS NULL OR short_code = :shortcode)
                AND (:branch_id IS NULL OR branch_id = :branch_id)',
            [
                'id'        => $mpesaPaymentId,
                'cid'       => $ctx['session']->company->id,
                'amount'    => $amount,
                'shortcode' => $shortcode,
                'branch_id' => $branchId,
            ],
        );

        if (!$row) {
            return $this->json(['ok' => false, 'error' => 'not_found']);
        }

        // Mark as claimed
        $this->db->executeStatement(
            'UPDATE mpesa_payments SET claimed = 1, claimed_by_user_id = :uid WHERE id = :id',
            ['uid' => $ctx['session']->user->id, 'id' => $row['id']],
        );

        // Mark pos_transaction complete and advance draft
        $txnId = $draft->posTransactionId;

        if ($txnId) {
            $this->transactions->syncAutoAwardedMpesaPaymentToTransaction(
                $txnId,
                (int) $row['id'],
                isset($splits[$splitIndex]) ? (float) $splits[$splitIndex]['amount'] : null,
            );

            if ((bool) ($row['loyalty_auto_awarded'] ?? false)) {
                $this->activityLog->log(
                    session:     $ctx['session'],
                    module:      UserActivityLogService::MODULE_LOYALTY,
                    action:      UserActivityLogService::ACTION_UPDATE,
                    description: sprintf(
                        'Synced callback-side loyalty award from M-Pesa payment #%d to transaction #%d (no re-award)',
                        (int) $row['id'],
                        $txnId,
                    ),
                    subjectType: 'pos_transaction',
                    subjectId:   $txnId,
                    metadata:    [
                        'mpesa_payment_id'   => (int) $row['id'],
                        'pos_transaction_id' => $txnId,
                        'award_source'       => 'mpesa_claim_sync',
                        'points'             => (int) ($row['loyalty_points_awarded'] ?? 0),
                    ],
                );
            }
        }

        // Record this callback split leg
        if ($txnId && isset($splits[$splitIndex])) {
            $leg = $splits[$splitIndex];
            $this->transactions->recordSplitLeg(
                posTransactionId: $txnId,
                splitIndex:       $splitIndex,
                paymentMethodId:  $this->resolvePaymentMethodId($leg['method_key']),
                methodKey:        $leg['method_key'],
                amount:           (float) $leg['amount'],
                mpesaConfigId:    isset($leg['config_id']) ? (int) $leg['config_id'] : null,
                apiReceipt:       $row['transaction_id'] ?: null,
                mpesaPaymentId:   $row['id'],
            );
        }

        if ($txnId && $draft->step <= 3) {
            $nextIndex = $splitIndex + 1;

            $this->transactions->markComplete(
                transactionId: $txnId,
                cashierUserId: $ctx['session']->user->id,
            );

            if ($nextIndex < count($splits)) {
                $this->checkout->advanceDraft(
                    terminalIdentifier: $ctx['terminal'],
                    companyId:          $ctx['session']->company->id,
                    nextStep:           3,
                    newPayload:         ['split_index' => $nextIndex, 'stk_phone' => $row['msisdn']],
                );
            } else {
                $this->checkout->advanceDraft(
                    terminalIdentifier: $ctx['terminal'],
                    companyId:          $ctx['session']->company->id,
                    nextStep:           4,
                    newPayload:         ['stk_phone' => $row['msisdn']],
                );
            }
        }

        return $this->json([
            'ok'      => true,
            'phone'   => $row['msisdn'],
            'receipt' => $row['transaction_id'],
        ]);
    }

    /** JSON — check loyalty enrollment status for a phone number */
    #[Route('/loyalty-check', name: 'terminal_checkout_loyalty_check', methods: ['GET'])]
    public function loyaltyCheck(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->requirePosJson($request, $branch);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }

        if (!$ctx['loyalty_enabled']) {
            return $this->json(['success' => false, 'message' => 'Loyalty module not enabled.'], 400);
        }

        $phone = trim((string) $request->query->get('phone', ''));
        $normalised = $this->customers->normalizePhone($phone);

        if ($normalised === null) {
            return $this->json(['enrolled' => false, 'phone' => null]);
        }

        $program = $this->loyalty->getProgram($ctx['session']->company->id, $ctx['session']->branch->id);
        $account = $this->loyalty->getAccount($ctx['session']->company->id, $normalised, $ctx['session']->branch->id);

        if ($account === null) {
            return $this->json([
                'enrolled'      => false,
                'phone'         => $normalised,
                'points_symbol' => $program ? ($program['points_symbol'] ?? $program['points_name'] ?? 'pts') : 'pts',
            ]);
        }

        return $this->json([
            'enrolled'      => true,
            'phone'         => $normalised,
            'balance'       => $account->pointsBalance,
            'tier'          => $account->tierName,
            'tier_color'    => $account->tierColor ?? '#fff',
            'points_symbol' => $account->pointsSymbol ?? $account->pointsName,
        ]);
    }

    /** JSON — redeem loyalty points as a payment split */
    #[Route('/3/loyalty-redeem', name: 'terminal_checkout_loyalty_redeem', methods: ['POST'])]
    public function loyaltyRedeem(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->requirePosJson($request, $branch);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }

        if (!$ctx['loyalty_enabled']) {
            return $this->json(['success' => false, 'message' => 'Loyalty module not enabled.'], 400);
        }

        $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);
        if ($draft === null) {
            return $this->json(['success' => false, 'message' => 'No active checkout.'], 400);
        }

        $phone  = trim((string) $request->request->get('phone', ''));
        $normalised = $this->customers->normalizePhone($phone);
        if ($normalised === null) {
            return $this->json(['success' => false, 'message' => 'Invalid phone number.'], 400);
        }

        $splits     = $draft->get('splits', []);
        $splitIndex = (int) $draft->get('split_index', 0);
        $splitAmount = isset($splits[$splitIndex]) ? (float) $splits[$splitIndex]['amount'] : 0.0;

        $redemptionConfig = $this->loyalty->getRedemptionConfig($ctx['session']->company->id, $ctx['session']->branch->id);
        if ($redemptionConfig === null) {
            return $this->json(['success' => false, 'message' => 'Loyalty redemption not enabled.'], 400);
        }

        $account = $this->loyalty->getAccount($ctx['session']->company->id, $normalised, $ctx['session']->branch->id);
        if ($account === null) {
            return $this->json(['success' => false, 'message' => 'Customer not enrolled in loyalty.'], 400);
        }

        $kesPerPoint = (float) ($redemptionConfig['kes_per_point'] ?? 1.0);
        $pointsNeeded = $kesPerPoint > 0 ? (int) ceil($splitAmount / $kesPerPoint) : 0;

        if ($pointsNeeded <= 0) {
            return $this->json(['success' => false, 'message' => 'Invalid redemption amount.'], 400);
        }

        if ($account->pointsBalance < $pointsNeeded) {
            return $this->json([
                'success' => false,
                'message' => "Insufficient points. Has {$account->pointsBalance}, needs {$pointsNeeded}.",
            ], 400);
        }

        $redeemed = $this->loyalty->redeemPoints(
            companyId:        $ctx['session']->company->id,
            loyaltyAccountId: $account->id,
            points:           $pointsNeeded,
            posTransactionId: $draft->posTransactionId,
            cashierUserId:    $ctx['session']->user->id,
        );

        if (!$redeemed) {
            return $this->json(['success' => false, 'message' => 'Redemption failed. Please retry.'], 500);
        }

        // Store redeemed phone so loyalty step 4 knows customer
        $this->checkout->advanceDraft(
            terminalIdentifier: $ctx['terminal'],
            companyId:          $ctx['session']->company->id,
            nextStep:           3,
            newPayload:         ['stk_phone' => $normalised, 'loyalty_redeemed_pts' => $pointsNeeded],
        );

        return $this->json([
            'success'         => true,
            'points_redeemed' => $pointsNeeded,
            'balance_after'   => $account->pointsBalance - $pointsNeeded,
        ]);
    }

    /** JSON — look up loyalty balance for a phone (for redemption step) */
    #[Route('/3/redemption-balance', name: 'terminal_checkout_redemption_balance', methods: ['GET'])]
    public function redemptionBalance(Request $request, string $branch): JsonResponse
    {
        $ctx = $this->requirePosJson($request, $branch);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }

        if (!$ctx['loyalty_enabled']) {
            return $this->json(['success' => false, 'message' => 'Loyalty module not enabled.'], 400);
        }

        $phone = trim((string) $request->query->get('phone', ''));
        $normalised = $this->customers->normalizePhone($phone);
        if ($normalised === null) {
            return $this->json(['enrolled' => false]);
        }

        $account = $this->loyalty->getAccount($ctx['session']->company->id, $normalised, $ctx['session']->branch->id);
        if ($account === null) {
            return $this->json(['enrolled' => false, 'phone' => $normalised]);
        }

        $redemptionCfg = $this->loyalty->getRedemptionConfig($ctx['session']->company->id, $ctx['session']->branch->id);
        $kesPerPoint   = (float) ($redemptionCfg['kes_per_point'] ?? 1.0);
        $maxPct        = (int)   ($redemptionCfg['max_redemption_pct'] ?? 100);

        // Amount can come from query param (step 2 lookup) or from active draft split (step 3)
        $requestedAmount = (float) $request->query->get('amount', 0);
        if ($requestedAmount <= 0) {
            $draft      = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);
            $splits     = $draft?->get('splits', []) ?? [];
            $splitIndex = (int) ($draft?->get('split_index', 0) ?? 0);
            $requestedAmount = isset($splits[$splitIndex]) ? (float) $splits[$splitIndex]['amount'] : 0.0;
        }

        $availableKes  = $kesPerPoint > 0 ? floor($account->pointsBalance * $kesPerPoint) : 0;
        $maxByPct      = $requestedAmount > 0 ? floor($requestedAmount * $maxPct / 100) : $availableKes;
        $redeemableKes = min($availableKes, $maxByPct, $requestedAmount > 0 ? $requestedAmount : PHP_INT_MAX);
        $pointsNeeded  = $kesPerPoint > 0 && $redeemableKes > 0 ? (int) ceil($redeemableKes / $kesPerPoint) : 0;
        $canCoverFull  = $redeemableKes >= $requestedAmount && $requestedAmount > 0;
        $canRedeem     = $redeemableKes > 0;

        // Explain why redemption is blocked when it is
        $denyReason = null;
        if (!$canRedeem) {
            if ($availableKes <= 0) {
                $denyReason = 'no_points'; // customer has zero redeemable points
            } elseif ($maxByPct <= 0) {
                $denyReason = 'amount_too_low'; // bill too small for the % cap (e.g. KES 1 at 50% = KES 0)
            } else {
                $denyReason = 'insufficient_points'; // has some points but not enough
            }
        }

        return $this->json([
            'enrolled'        => true,
            'phone'           => $normalised,
            'balance'         => $account->pointsBalance,
            'points_symbol'   => $account->pointsSymbol ?? $account->pointsName,
            'points_needed'   => $pointsNeeded,
            'kes_per_point'   => $kesPerPoint,
            'max_pct'         => $maxPct,
            'available_kes'   => $availableKes,
            'redeemable_kes'  => $redeemableKes,
            'can_cover_full'  => $canCoverFull,
            'can_redeem'      => $canRedeem,
            'deny_reason'     => $denyReason,
        ]);
    }

    // =========================================================================
    // STEP 4 — Loyalty
    // =========================================================================

    #[Route('/4', name: 'terminal_checkout_step4', methods: ['GET'])]
    public function step4(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $draft = $this->requireDraft($ctx);
        if ($draft instanceof Response) {
            return $draft;
        }

        $remainingEarnableAmount = $this->remainingEarnableAmount($draft, $ctx['session']->company->id);

        if ($draft->posTransactionId
            && $this->isTransactionAutoAwarded($draft->posTransactionId, $ctx['session']->company->id)
            && $remainingEarnableAmount <= 0.0
        ) {
            $this->activityLog->log(
                session:     $ctx['session'],
                module:      UserActivityLogService::MODULE_LOYALTY,
                action:      UserActivityLogService::ACTION_UPDATE,
                description: sprintf(
                    'Step 4 skipped — loyalty points already auto-awarded for transaction #%d',
                    $draft->posTransactionId,
                ),
                subjectType: 'pos_transaction',
                subjectId:   $draft->posTransactionId,
                metadata:    ['reason' => 'auto_award_complete', 'transaction_id' => $draft->posTransactionId],
            );
            $this->advanceToSuccessFromTransaction($ctx, $draft, $draft->posTransactionId);
            return $this->redirectToRoute('terminal_checkout_step5', $ctx['route_params']);
        }

        // Skip loyalty step entirely if module is disabled for this company
        if (!$ctx['loyalty_enabled']) {
            if ($draft->posTransactionId) {
                $this->transactions->markComplete($draft->posTransactionId);
            }
            $this->checkout->advanceDraft(
                terminalIdentifier: $ctx['terminal'],
                companyId:          $ctx['session']->company->id,
                nextStep:           5,
                newPayload:         [],
            );
            return $this->redirectToRoute('terminal_checkout_step5', $ctx['route_params']);
        }

        $program = $this->loyalty->getProgram($ctx['session']->company->id, $ctx['session']->branch->id);

        // Points are earned only on the non-loyalty portion of the bill
        $loyaltyRedeemedKes = 0.0;
        foreach (($draft->get('splits', []) ?: []) as $s) {
            if (($s['method_key'] ?? '') === 'loyalty') {
                $loyaltyRedeemedKes += (float) ($s['amount'] ?? 0);
            }
        }
        $earnableAmount = max(0.0, $this->remainingEarnableAmount($draft, $ctx['session']->company->id));

        $pointsPreview = $program
            ? $this->loyalty->calculatePoints($ctx['session']->company->id, $earnableAmount, $ctx['session']->branch->id)
            : 0;

        // Pre-fill phone from STK push step or loyalty split
        $stkPhone = (string) $draft->get('stk_phone', '');
        if ($stkPhone === '') {
            foreach (($draft->get('splits', []) ?: []) as $s) {
                if (!empty($s['loy_phone'])) { $stkPhone = $s['loy_phone']; break; }
            }
        }

        return $this->render('terminal/checkout/step4_loyalty.html.twig', [
            'draft'         => $draft,
            'program'       => $program,
            'points_preview'=> $pointsPreview,
            'stk_phone'     => $stkPhone,
            'route_params'  => $ctx['route_params'],
        ]);
    }

    #[Route('/4', name: 'terminal_checkout_save4', methods: ['POST'])]
    public function saveStep4(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $draft = $this->requireDraft($ctx);
        if ($draft instanceof Response) {
            return $draft;
        }

        $phone      = trim((string) $request->request->get('phone', ''));
        $skip       = $request->request->get('skip') === '1' || !$ctx['loyalty_enabled'];
        $txnId      = $draft->posTransactionId;
        $firstName  = trim((string) $request->request->get('first_name', ''));
        $gender     = trim((string) $request->request->get('gender', ''));
        $birthMonth = $request->request->get('birth_month') !== '' && $request->request->get('birth_month') !== null
            ? (int) $request->request->get('birth_month') : null;
        $birthDay   = $request->request->get('birth_day') !== '' && $request->request->get('birth_day') !== null
            ? (int) $request->request->get('birth_day') : null;

        if ($txnId
            && $this->isTransactionAutoAwarded($txnId, $ctx['session']->company->id)
            && $this->remainingEarnableAmount($draft, $ctx['session']->company->id) <= 0.0
        ) {
            $this->advanceToSuccessFromTransaction($ctx, $draft, $txnId, $phone);
            return $this->redirectToRoute('terminal_checkout_step5', $ctx['route_params']);
        }

        // Points awarded only on the amount NOT paid by loyalty redemption
        $loyaltyRedeemedKes = 0.0;
        foreach (($draft->get('splits', []) ?: []) as $s) {
            if (($s['method_key'] ?? '') === 'loyalty') {
                $loyaltyRedeemedKes += (float) ($s['amount'] ?? 0);
            }
        }
        $earnableAmount = max(0.0, $this->remainingEarnableAmount($draft, $ctx['session']->company->id));

        $pointsAwarded  = 0;
        $loyaltyAccount = null;

        if (!$skip && $phone !== '' && $txnId) {
            // Pre-save customer details so they're set before markComplete calls findOrCreate again
            $normalizedForSave = $this->customers->normalizePhone($phone);
            if ($normalizedForSave !== null && ($firstName !== '' || in_array($gender, ['male', 'female'], true) || $birthMonth !== null)) {
                $this->customers->findOrCreate(
                    companyId:  $ctx['session']->company->id,
                    msisdn:     $normalizedForSave,
                    firstName:  $firstName !== '' ? $firstName : null,
                    gender:     in_array($gender, ['male', 'female'], true) ? $gender : null,
                    birthMonth: $birthMonth,
                    birthDay:   $birthDay,
                );
            }

            $pointsAwarded = $this->transactions->markComplete(
                transactionId: $txnId,
                msisdn:        $phone,
                cashierUserId: $ctx['session']->user->id,
                earnableAmount: $earnableAmount > 0 ? $earnableAmount : null,
            );

            $normalised = $this->customers->normalizePhone($phone);
            if ($normalised) {
                $loyaltyAccount = $this->loyalty->getAccount($ctx['session']->company->id, $normalised, $ctx['session']->branch->id);
            }

            $posId = $this->posId($txnId, $ctx['session']->company->id);
            $this->activityLog->create(
                session:     $ctx['session'],
                module:      UserActivityLogService::MODULE_TRANSACTIONS,
                description: sprintf(
                    'Checkout completed — KES %s, #%s%s',
                    number_format((float) $draft->get('amount', 0), 2),
                    $posId ?? $txnId,
                    $pointsAwarded > 0 ? sprintf(', +%d pts awarded', $pointsAwarded) : '',
                ),
                subjectType: 'pos_transaction',
                subjectId:   $txnId,
                metadata:    [
                    'amount'         => (float) $draft->get('amount', 0),
                    'points_awarded' => $pointsAwarded,
                    'loyalty_phone'  => '···' . substr((string) $phone, -3),
                    'pos_id'         => $posId,
                ],
                request:     $request,
            );
        } elseif ($skip && $txnId) {
            // Mark complete without loyalty
            $this->transactions->markComplete($txnId);

            $posId = $this->posId($txnId, $ctx['session']->company->id);
            $this->activityLog->create(
                session:     $ctx['session'],
                module:      UserActivityLogService::MODULE_TRANSACTIONS,
                description: sprintf(
                    'Checkout completed — KES %s, #%s (no loyalty)',
                    number_format((float) $draft->get('amount', 0), 2),
                    $posId ?? $txnId,
                ),
                subjectType: 'pos_transaction',
                subjectId:   $txnId,
                metadata:    [
                    'amount' => (float) $draft->get('amount', 0),
                    'pos_id' => $posId,
                ],
                request:     $request,
            );
        }

        $this->checkout->advanceDraft(
            terminalIdentifier: $ctx['terminal'],
            companyId:          $ctx['session']->company->id,
            nextStep:           5,
            newPayload:         [
                'loyalty_phone'   => $phone,
                'points_awarded'  => $pointsAwarded,
                'loyalty_balance' => $loyaltyAccount?->pointsBalance,
                'loyalty_tier'    => $loyaltyAccount?->tierName,
                'loyalty_name'    => $loyaltyAccount?->pointsName,
            ],
        );

        return $this->redirectToRoute('terminal_checkout_step5', $ctx['route_params']);
    }

    // =========================================================================
    // STEP 5 — Success
    // =========================================================================

    #[Route('/5', name: 'terminal_checkout_step5', methods: ['GET'])]
    public function step5(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);
        if ($draft === null) {
            // Draft already completed or expired — show dashboard
            return $this->redirectToRoute('terminal_dashboard', $ctx['route_params']);
        }

        $program = $this->loyalty->getProgram($ctx['session']->company->id, $ctx['session']->branch->id);

        return $this->render('terminal/checkout/step5_success.html.twig', [
            'draft'        => $draft,
            'program'      => $program,
            'route_params' => $ctx['route_params'],
        ]);
    }

    // =========================================================================
    // CANCEL
    // =========================================================================

    #[Route('/cancel', name: 'terminal_checkout_cancel', methods: ['POST'])]
    public function cancel(Request $request, string $branch): Response
    {
        $ctx = $this->requirePos($request, $branch);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);

        // Mark the transaction cancelled if one was created
        if ($draft?->posTransactionId) {
            $this->transactions->markCancelled($draft->posTransactionId);

            $posId = $this->posId($draft->posTransactionId, $ctx['session']->company->id);
            $this->activityLog->update(
                session:     $ctx['session'],
                module:      UserActivityLogService::MODULE_TRANSACTIONS,
                description: sprintf(
                    'Checkout cancelled — #%s voided',
                    $posId ?? $draft->posTransactionId,
                ),
                subjectType: 'pos_transaction',
                subjectId:   $draft->posTransactionId,
                metadata:    [
                    'pos_id'             => $posId,
                    'pos_transaction_id' => $draft->posTransactionId,
                    'amount'             => (float) ($draft->get('amount', 0)),
                    'status'             => 'cancelled',
                ],
                request:     $request,
            );
        }

        $this->checkout->cancelDraft($ctx['terminal'], $ctx['session']->company->id);

        return $this->redirectToRoute('terminal_dashboard', $ctx['route_params']);
    }

    // =========================================================================
    // PRIVATE — Auth helpers
    // =========================================================================

    /**
     * Validate POS session for page controllers.
     * Returns context array on success, RedirectResponse on failure.
     */
    private function requirePos(Request $request, string $branch): array|Response
    {
        $token     = $request->cookies->get('angavu_pos_token') ?: null;
        $terminal  = (string) $request->cookies->get('angavu_terminal', '');
        $subdomain = $this->domains->getSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        if ($token === null || $subdomain === null) {
            return $this->redirectToRoute('terminal_login_page', [
                'subdomain' => $subdomain ?? 'unknown',
                'domain'    => $baseDomain,
                'branch'    => $branch,
            ]);
        }

        try {
            $session    = $this->auth->validateSession($token);
            $branchNode = $this->terminalBranchAccess->resolveBranchNode($session->company->id, $branch);

            if ($branchNode === null
                || !$this->terminalBranchAccess->terminalMatchesBranch($session->company->id, $terminal, $branchNode->id)
                || !$this->terminalBranchAccess->userAssignedToBranch($session->user->id, $branchNode->id)
            ) {
                return $this->redirectToRoute('terminal_login_page', [
                    'subdomain' => $subdomain,
                    'domain'    => $baseDomain,
                    'branch'    => $branch,
                ]);
            }

            $session->branch = $branchNode;

            $loyaltyEnabled = $this->isLoyaltyEnabled($session->company->id, $branchNode->id);

            return [
                'session'          => $session,
                'terminal'         => $terminal,
                'subdomain'        => $subdomain,
                'route_params'     => ['subdomain' => $subdomain, 'domain' => $baseDomain, 'branch' => $branch],
                'loyalty_enabled'  => $loyaltyEnabled,
            ];

        } catch (AuthException) {
            return $this->redirectToRoute('terminal_login_page', [
                'subdomain' => $subdomain,
                'domain'    => $baseDomain,
                'branch'    => $branch,
            ]);
        }
    }

    /**
     * Validate POS session for JSON controllers.
     * Returns context array on success, JsonResponse on failure.
     */
    private function requirePosJson(Request $request, string $branch): array|JsonResponse
    {
        $token    = $request->cookies->get('angavu_pos_token') ?: null;
        $terminal = (string) $request->cookies->get('angavu_terminal', '');
        $subdomain = $this->domains->getSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        if ($token === null || $subdomain === null) {
            return $this->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        try {
            $session    = $this->auth->validateSession($token);
            $branchNode = $this->terminalBranchAccess->resolveBranchNode($session->company->id, $branch);

            if ($branchNode === null) {
                return $this->json(['success' => false, 'message' => 'Invalid branch.'], 404);
            }

            $session->branch = $branchNode;

            $loyaltyEnabled = $this->isLoyaltyEnabled($session->company->id, $branchNode->id);

            return [
                'session'         => $session,
                'terminal'        => $terminal,
                'route_params'    => ['subdomain' => $subdomain, 'domain' => $baseDomain, 'branch' => $branch],
                'loyalty_enabled' => $loyaltyEnabled,
            ];
        } catch (AuthException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }
    }

    private function getOrStartDraft(array $ctx): CheckoutDraft
    {
        $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);

        if ($draft === null) {
            $draft = $this->checkout->startDraft(
                terminalIdentifier: $ctx['terminal'],
                companyId:          $ctx['session']->company->id,
                branchId:           $ctx['session']->branch->id,
                cashierUserId:      $ctx['session']->user->id,
            );
        }

        return $draft;
    }

    private function requireDraft(array $ctx): CheckoutDraft|Response
    {
        $draft = $this->checkout->getActiveDraft($ctx['terminal'], $ctx['session']->company->id);

        if ($draft === null) {
            return $this->redirectToRoute('terminal_checkout_step1', $ctx['route_params']);
        }

        return $draft;
    }

    private function resolvePaymentMethodId(string $methodKey): int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM payment_methods WHERE method_key = :key LIMIT 1',
            ['key' => $methodKey],
        );

        return $row ? (int) $row : 1; // fallback to id=1
    }

    private function generateToken(string $id): string
    {
        return $this->container->get('security.csrf.token_manager')?->getToken($id)->getValue() ?? '';
    }

    private function isLoyaltyEnabled(int $companyId, int $branchId): bool
    {
        return $this->features->canAny(
            $companyId,
            TenantFeatureAccessService::FEATURE_EARN_POINTS,
            TenantFeatureAccessService::FEATURE_REDEEM_POINTS,
            TenantFeatureAccessService::FEATURE_REWARD_SETUP,
            TenantFeatureAccessService::FEATURE_LOYALTY_BALANCE,
        ) && $this->loyalty->getProgram($companyId, $branchId) !== null;
    }

    private function isTransactionAutoAwarded(int $transactionId, int $companyId): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT loyalty_auto_awarded
               FROM pos_transactions
              WHERE id = :id
                AND company_id = :company_id
              LIMIT 1',
            [
                'id' => $transactionId,
                'company_id' => $companyId,
            ],
        );
    }

    private function advanceToSuccessFromTransaction(array $ctx, CheckoutDraft $draft, int $transactionId, string $phoneOverride = ''): void
    {
        $snapshot = $this->db->fetchAssociative(
            'SELECT pt.msisdn,
                    pt.loyalty_points_awarded,
                    la.points_balance,
                    lt.name AS tier_name,
                    lp.points_name
               FROM pos_transactions pt
          LEFT JOIN loyalty_accounts la ON la.id = pt.loyalty_account_id
          LEFT JOIN loyalty_tiers lt ON lt.id = la.loyalty_tier_id
          LEFT JOIN loyalty_programs lp ON lp.id = la.loyalty_program_id
              WHERE pt.id = :id
                AND pt.company_id = :company_id
              LIMIT 1',
            [
                'id' => $transactionId,
                'company_id' => $ctx['session']->company->id,
            ],
        ) ?: [];

        $this->checkout->advanceDraft(
            terminalIdentifier: $ctx['terminal'],
            companyId:          $ctx['session']->company->id,
            nextStep:           5,
            newPayload:         [
                'loyalty_phone'   => $phoneOverride !== '' ? $phoneOverride : (string) ($snapshot['msisdn'] ?? ''),
                'points_awarded'  => (float) ($snapshot['loyalty_points_awarded'] ?? 0),
                'loyalty_balance' => $snapshot['points_balance'] !== null ? (float) $snapshot['points_balance'] : null,
                'loyalty_tier'    => $snapshot['tier_name'] ?? null,
                'loyalty_name'    => $snapshot['points_name'] ?? null,
            ],
        );
    }

    /** Fetch the human-readable pos_id for a transaction, returns null if not found. */
    private function posId(int $transactionId, int $companyId): ?int
    {
        $val = $this->db->fetchOne(
            'SELECT pos_id FROM pos_transactions WHERE id = :id AND company_id = :company_id LIMIT 1',
            ['id' => $transactionId, 'company_id' => $companyId],
        );

        return $val ? (int) $val : null;
    }

    private function remainingEarnableAmount(CheckoutDraft $draft, int $companyId): float
    {
        $loyaltyRedeemedKes = 0.0;
        foreach (($draft->get('splits', []) ?: []) as $s) {
            if (($s['method_key'] ?? '') === 'loyalty') {
                $loyaltyRedeemedKes += (float) ($s['amount'] ?? 0);
            }
        }

        $baseEarnable = max(0.0, (float) $draft->get('amount', 0) - $loyaltyRedeemedKes);
        if (!$draft->posTransactionId) {
            return $baseEarnable;
        }

        $autoAwardedAmount = (float) ($this->db->fetchOne(
            'SELECT loyalty_auto_awarded_amount
               FROM pos_transactions
              WHERE id = :id
                AND company_id = :company_id
              LIMIT 1',
            [
                'id' => $draft->posTransactionId,
                'company_id' => $companyId,
            ],
        ) ?? 0);

        return max(0.0, $baseEarnable - $autoAwardedAmount);
    }
}
