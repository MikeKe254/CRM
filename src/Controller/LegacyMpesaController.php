<?php

declare(strict_types=1);

namespace App\Controller;

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
#[Route('/legacy/mpesa', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin$)[A-Za-z0-9-]+', 'domain' => '.+'])]
class LegacyMpesaController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly DomainHelper $domains,
    ) {}

    #[Route('/dashboard', name: 'mpesa_dashboard')]
    public function dashboard(Request $request, string $domain): Response
    {
        $terminal = $request->cookies->get('angavu_terminal', '');
        $subdomain = $this->resolveSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        if ($subdomain === null) {
            return $this->redirectToRoute('home', ['domain' => $baseDomain]);
        }

        if ($terminal === '') {
            return $this->redirectToRoute('mpesa_login_page', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
        }

        $company = $this->db->fetchAssociative(
            'SELECT id FROM companies WHERE id <> 0 AND subdomain = :subdomain LIMIT 1',
            ['subdomain' => $subdomain],
        );

        if (!$company) {
            return $this->redirectToRoute('mpesa_login_page', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
        }

        $valid = $this->db->fetchOne(
            'SELECT id FROM pos_terminals
             WHERE  company_id          = :company_id
               AND  terminal_identifier = :identifier
               AND  revoked_at IS NULL
               AND  (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1',
            ['company_id' => $company['id'], 'identifier' => $terminal],
        );

        if (!$valid) {
            return $this->redirectToRoute('mpesa_login_page', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
        }

        return $this->render('mpesa/dashboard.html.twig', [
            'is_locked' => true,
        ]);
    }

    private function resolveSubdomain(Request $request): ?string
    {
        // 2026-03-19: Resolve through DomainHelper to avoid treating apex domains
        // like everify.co.ke as tenant subdomains in POS flows.
        return $this->domains->getSubdomain($request);
    }
}
