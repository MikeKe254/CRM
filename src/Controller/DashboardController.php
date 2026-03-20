<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\ActivityLog\UserActivityLogService;
use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Main application dashboard.
 * Requires a valid angavu_token session cookie.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Connection $db,
        private readonly DomainHelper $domains,
        private readonly UserActivityLogService $activityLog,
    ) {}

    #[Route('/dashboard', name: 'app_dashboard', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin$)[A-Za-z0-9-]+', 'domain' => '.+'])]
    public function index(Request $request, string $domain): Response
    {
        $token = $this->resolveToken($request);
        $subdomain = $this->resolveSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        // 2026-03-19: Use the resolved base domain for fallback redirects so
        // apex hosts like everify.co.ke do not collapse into co.ke.
        if ($subdomain === null || !$this->tenantExists($subdomain)) {
            return $this->redirectToRoute('home', ['domain' => $baseDomain]);
        }

        if (!$token) {
            return $this->redirectToRoute('app_login', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
        }

        try {
            $session = $this->auth->validateSession($token);
        } catch (AuthException) {
            return $this->redirectToRoute('app_login', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
        }

        if ($session->deviceType === 'pos') {
            return $this->redirectToRoute('app_login', ['subdomain' => $subdomain, 'domain' => $baseDomain]);
        }

        if (
            $session->user->isSuperAdmin
            && (
                str_starts_with($request->getHost(), 'admin.')
                || $session->company->subdomain === '__platform__'
            )
        ) {
            return $this->redirectToRoute('platform_dashboard', ['domain' => $baseDomain]);
        }

        $this->activityLog->record($session, 'dashboard.access', request: $request);

        return $this->render('dashboard/index.html.twig', [
            'session' => $session,
        ]);
    }

    private function resolveToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->cookies->get('angavu_token') ?: null;
    }

    private function resolveSubdomain(Request $request): ?string
    {
        // 2026-03-19: Use DomainHelper so apex domains never masquerade as tenant hosts.
        return $this->domains->getSubdomain($request);
    }

    private function tenantExists(string $subdomain): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM companies WHERE id <> 0 AND subdomain = :subdomain LIMIT 1',
            ['subdomain' => $subdomain],
        );
    }
}
