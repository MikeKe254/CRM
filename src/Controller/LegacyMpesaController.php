<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Terminal\TerminalBranchAccessService;
use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the POS dashboard.
 * Access is gated by the angavu_terminal cookie.
 */
#[Route('/{branch}/terminal', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'])]
class LegacyMpesaController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly DomainHelper $domains,
        private readonly TerminalBranchAccessService $terminalBranchAccess,
        private readonly AuthService $auth,
    ) {}

    #[Route('/dashboard', name: 'terminal_dashboard', priority: 1)]
    public function dashboard(Request $request, string $domain, string $branch): Response
    {
        $terminal = $request->cookies->get('angavu_terminal', '');
        $subdomain = $this->resolveSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        if ($subdomain === null) {
            return $this->redirectToRoute('home', ['domain' => $baseDomain]);
        }

        if ($terminal === '') {
            return $this->redirectToRoute('terminal_login_page', ['subdomain' => $subdomain, 'domain' => $baseDomain, 'branch' => $branch]);
        }

        $company = $this->db->fetchAssociative(
            'SELECT id FROM companies WHERE id <> 0 AND subdomain = :subdomain AND deleted_at IS NULL LIMIT 1',
            ['subdomain' => $subdomain],
        );

        if (!$company) {
            return $this->redirectToRoute('terminal_login_page', ['subdomain' => $subdomain, 'domain' => $baseDomain, 'branch' => $branch]);
        }

        $branchNode = $this->terminalBranchAccess->resolveBranchNode((int) $company['id'], $branch);
        if ($branchNode === null) {
            return $this->redirectToRoute('terminal_login_page', ['subdomain' => $subdomain, 'domain' => $baseDomain, 'branch' => $branch]);
        }

        $valid = $this->db->fetchOne(
            'SELECT id FROM pos_terminals
             WHERE  company_id          = :company_id
               AND  branch_id           = :branch_id
               AND  terminal_identifier = :identifier
               AND  revoked_at IS NULL
               AND  (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1',
            ['company_id' => $company['id'], 'branch_id' => $branchNode->id, 'identifier' => $terminal],
        );

        if (!$valid) {
            return $this->redirectToRoute('terminal_login_page', ['subdomain' => $subdomain, 'domain' => $baseDomain, 'branch' => $branch]);
        }

        $token    = $request->cookies->get('angavu_pos_token') ?: null;
        $isLocked = true;
        $userName = null;

        if ($token !== null) {
            try {
                $session  = $this->auth->validateSession($token);
                $isLocked = false;
                $userName = $session->user->name ?? null;
            } catch (AuthException) {
                $isLocked = true;
            }
        }

        return $this->render('terminal/dashboard.html.twig', [
            'is_locked' => $isLocked,
            'user_name' => $userName,
        ]);
    }

    private function resolveSubdomain(Request $request): ?string
    {
        // 2026-03-19: Resolve through DomainHelper to avoid treating apex domains
        // like everify.co.ke as tenant subdomains in POS flows.
        return $this->domains->getSubdomain($request);
    }
}
