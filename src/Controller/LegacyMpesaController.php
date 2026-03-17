<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the POS dashboard.
 * Access is gated by the angavu_terminal cookie — set during POS terminal authorization.
 * No user session required to VIEW the dashboard — only to unlock it via PIN.
 */
#[Route('/legacy/mpesa')]
class LegacyMpesaController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/dashboard', name: 'mpesa_dashboard')]
    public function dashboard(Request $request): Response
    {
        $terminal  = $request->cookies->get('angavu_terminal', '');
        $subdomain = $this->resolveSubdomain($request);

        // No terminal cookie → send to POS authorization page
        if ($terminal === '') {
            return $this->redirectToRoute('mpesa_login_page');
        }

        // Resolve company
        $company = $this->db->fetchAssociative(
            'SELECT id FROM companies WHERE subdomain = :subdomain LIMIT 1',
            ['subdomain' => $subdomain],
        );

        if (!$company) {
            return $this->redirectToRoute('mpesa_login_page');
        }

        // Check terminal is valid + not expired + not revoked
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
            // Terminal expired or revoked → re-authorization required
            return $this->redirectToRoute('mpesa_login_page');
        }

        // Terminal valid — serve dashboard always locked
        // PIN unlock handled by SessionController
        return $this->render('mpesa/dashboard.html.twig', [
            'is_locked' => true,
        ]);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function resolveSubdomain(Request $request): string
    {
        $host  = $request->getHost();
        $parts = explode('.', $host);

        if (count($parts) >= 3) {
            return $parts[0];
        }

        return $_ENV['DEFAULT_SUBDOMAIN'] ?? 'koma';
    }
}