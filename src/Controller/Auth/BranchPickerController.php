<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Branch\BranchResolverService;
use App\Services\Branch\Exception\NoBranchAssignmentException;
use App\Services\Permission\PlatformCheckPermissionService;
use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shown after login when a user has access to multiple branches.
 * Lets them pick which branch to enter.
 *
 * Route: GET /branch-picker
 */
#[Route('', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+'])]
final class BranchPickerController extends AbstractController
{
    public function __construct(
        private readonly AuthService                    $auth,
        private readonly BranchResolverService          $branchResolver,
        private readonly DomainHelper                   $domains,
        private readonly Connection                     $db,
        private readonly PlatformCheckPermissionService $platformCan,
    ) {}

    #[Route('/branch-picker', name: 'app_branch_picker', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $subdomain  = $this->resolveSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        if ($subdomain === null || !$this->tenantExists($subdomain)) {
            return $this->redirectToRoute('home', ['domain' => $baseDomain]);
        }

        $token = $this->resolveToken($request);

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

        if (!$this->isMultiBranchPlatformReleased()) {
            return $this->redirectToRoute('app_dashboard', [
                'subdomain' => $subdomain,
                'domain'    => $baseDomain,
                'branch'    => BranchResolverService::HEAD_OFFICE_SLUG,
            ]);
        }

        // Platform admins have no user_node_roles entries — the branch picker is not
        // for them. If they hold ACCESS_COMPANY_CONTEXT, send them to /overall/.
        // Otherwise show the no-branch page (they should not be here at all).
        if ($session->user->isSuperAdmin) {
            if ($this->platformCan->check($session, 'access_company_context')) {
                return $this->redirectToRoute('app_dashboard', [
                    'subdomain' => $subdomain,
                    'domain'    => $baseDomain,
                    'branch'    => BranchResolverService::OVERALL_SLUG,
                ]);
            }
            return $this->render('auth/no-branch.html.twig', ['session' => $session]);
        }

        $branches = $this->branchResolver->getBranchesForPicker($session->user->id, $session->company->id);

        if (empty($branches)) {
            return $this->render('auth/no-branch.html.twig', [
                'session' => $session,
            ]);
        }

        // If exactly one branch, skip the picker and redirect directly
        if (count($branches) === 1) {
            return $this->redirectToRoute('app_dashboard', [
                'subdomain' => $subdomain,
                'domain'    => $baseDomain,
                'branch'    => $branches[0]->slug,
            ]);
        }

        return $this->render('auth/branch-picker.html.twig', [
            'session'    => $session,
            'branches'   => $branches,
            'subdomain'  => $subdomain,
            'baseDomain' => $baseDomain,
        ]);
    }

    private function resolveToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->cookies->get('patronr_token') ?: null;
    }

    private function resolveSubdomain(Request $request): ?string
    {
        $subdomain = $this->domains->getSubdomain($request);

        return $subdomain === 'admin' ? null : $subdomain;
    }

    private function tenantExists(string $subdomain): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM companies WHERE id <> 0 AND subdomain = :subdomain AND deleted_at IS NULL LIMIT 1',
            ['subdomain' => $subdomain],
        );
    }

    private function isMultiBranchPlatformReleased(): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT platform_released FROM modules WHERE slug = :slug LIMIT 1',
            ['slug' => 'multi_branch'],
        );
    }
}
