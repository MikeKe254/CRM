<?php

declare(strict_types=1);

namespace App\Controller\Terminal;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Customer\CustomerService;
use App\Services\Feature\TenantFeatureAccessService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Terminal\TerminalBranchAccessService;
use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/terminal/loyalty', host: '{subdomain}.{domain}', requirements: [
    'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
    'domain'    => '.+',
    'branch'    => '[A-Za-z0-9-]+',
])]
class LoyaltyController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DomainHelper $domains,
        private readonly LoyaltyService $loyalty,
        private readonly TenantFeatureAccessService $features,
        private readonly CustomerService $customers,
        private readonly TerminalBranchAccessService $terminalBranchAccess,
        private readonly Connection $db,
    ) {}

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

    #[Route('/check-phone', name: 'terminal_loyalty_check_phone', methods: ['GET'])]
    public function checkPhone(Request $request, string $branch): JsonResponse
    {
        $token     = $request->cookies->get('patronr_pos_token') ?: null;
        $terminal  = (string) $request->cookies->get('patronr_terminal', '');
        $subdomain = $this->domains->getSubdomain($request);

        if ($token === null || $subdomain === null) {
            return new JsonResponse(['error' => 'unauthenticated'], 401);
        }

        try {
            $session    = $this->auth->validateSession($token);
            $branchNode = $this->terminalBranchAccess->resolveBranchNode($session->company->id, $branch);

            if ($branchNode === null
                || !$this->terminalBranchAccess->terminalMatchesBranch($session->company->id, $terminal, $branchNode->id)
                || !$this->terminalBranchAccess->userAssignedToBranch($session->user->id, $branchNode->id)
            ) {
                return new JsonResponse(['error' => 'forbidden'], 403);
            }
        } catch (AuthException) {
            return new JsonResponse(['error' => 'unauthenticated'], 401);
        }

        if (!$this->isLoyaltyEnabled($session->company->id, $branchNode->id)) {
            return new JsonResponse(['error' => 'Loyalty module not enabled.'], 403);
        }

        $phone     = trim((string) $request->query->get('phone', ''));
        $normalised = $this->customers->normalizePhone($phone);

        if ($normalised === null) {
            return new JsonResponse(['enrolled' => false, 'valid' => false]);
        }

        $companyId = $session->company->id;
        $account   = $this->loyalty->getAccount($companyId, $normalised, $branchNode->id);
        $program   = $this->loyalty->getProgram($companyId, $branchNode->id);

        if ($account === null) {
            return new JsonResponse(['enrolled' => false, 'valid' => true]);
        }

        $customer = $this->customers->findByMsisdn($companyId, $normalised);

        return new JsonResponse([
            'enrolled'       => true,
            'valid'          => true,
            'first_name'     => $customer['first_name'] ?? null,
            'points_balance' => $account->pointsBalance,
            'points_symbol'  => $program['points_symbol'] ?? 'pts',
        ]);
    }

    #[Route('/check', name: 'terminal_loyalty_check', methods: ['GET'])]
    public function check(Request $request, string $branch): Response
    {
        $token      = $request->cookies->get('patronr_pos_token') ?: null;
        $terminal   = (string) $request->cookies->get('patronr_terminal', '');
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

        if (!$this->isLoyaltyEnabled($session->company->id, $branchNode->id)) {
            return $this->redirectToRoute('terminal_dashboard', $routeParams);
        }

        $program = $this->loyalty->getProgram($session->company->id, $branchNode->id);

        return $this->render('terminal/loyalty/check.html.twig', [
            'program'      => $program,
            'route_params' => $routeParams,
        ]);
    }

    #[Route('/enroll', name: 'terminal_loyalty_enroll', methods: ['GET', 'POST'])]
    public function enroll(Request $request, string $branch): Response
    {
        $token      = $request->cookies->get('patronr_pos_token') ?: null;
        $terminal   = (string) $request->cookies->get('patronr_terminal', '');
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

        if (!$this->isLoyaltyEnabled($session->company->id, $branchNode->id)) {
            return $this->redirectToRoute('terminal_dashboard', $routeParams);
        }

        $companyId = $session->company->id;
        $program   = $this->loyalty->getProgram($companyId, $branchNode->id);

        $enrolled     = null;
        $account      = null;
        $justEnrolled = false;

        if ($request->isMethod('POST')) {
            $phone      = trim((string) $request->request->get('phone', ''));
            $normalised = $this->customers->normalizePhone($phone);

            if ($normalised !== null && $program !== null) {
                $firstName  = trim((string) $request->request->get('first_name', ''));
                $gender     = trim((string) $request->request->get('gender', ''));
                $birthMonth = $request->request->get('birth_month') !== ''
                    ? ((int) $request->request->get('birth_month') ?: null) : null;
                $birthDay   = $request->request->get('birth_day') !== ''
                    ? ((int) $request->request->get('birth_day') ?: null) : null;

                // Save details before enrollment so they're attached from the start
                if ($firstName !== '' || in_array($gender, ['male', 'female'], true) || $birthMonth !== null) {
                    $this->customers->findOrCreate(
                        companyId:  $companyId,
                        msisdn:     $normalised,
                        firstName:  $firstName !== '' ? $firstName : null,
                        gender:     in_array($gender, ['male', 'female'], true) ? $gender : null,
                        birthMonth: $birthMonth,
                        birthDay:   $birthDay,
                    );
                }

                $existing = $this->loyalty->getAccount($companyId, $normalised, $branchNode->id);
                $account  = $this->loyalty->findOrEnroll($companyId, $normalised, $firstName !== '' ? $firstName : null, $branchNode->id);
                $enrolled = $this->customers->findByMsisdn($companyId, $normalised);

                if ($existing === null) {
                    $justEnrolled = true;
                }
            }
        }

        return $this->render('terminal/loyalty/enroll.html.twig', [
            'program'       => $program,
            'enrolled'      => $enrolled,
            'account'       => $account,
            'just_enrolled' => $justEnrolled,
            'route_params'  => $routeParams,
        ]);
    }
}
