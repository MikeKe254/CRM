<?php

declare(strict_types=1);

namespace App\Twig;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Branch\BranchHierarchyService;
use App\Services\Feature\TenantFeatureAccessService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Exposes permission checks to Twig templates.
 *
 * Usage in any template:
 *   {% if can('view_users') %}...{% endif %}
 *   {% if isSuperAdmin() %}...{% endif %}
 *
 * Reads the session from the angavu_token cookie on each request.
 * Returns false safely if the session is missing or invalid.
 */
final class PermissionExtension extends AbstractExtension implements GlobalsInterface
{
    private ?object $resolvedSession = null;
    private bool    $sessionResolved = false;

    public function __construct(
        private readonly AuthService                 $auth,
        private readonly CheckPermissionService      $can,
        private readonly PlatformCheckPermissionService $platformCan,
        private readonly RequestStack                $requestStack,
        private readonly BranchHierarchyService      $hierarchy,
        private readonly TenantFeatureAccessService  $features,
    ) {}

    public function getGlobals(): array
    {
        return [];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can', $this->checkPermission(...)),
            new TwigFunction('platformCan', $this->checkPlatformPermission(...)),
            new TwigFunction('isSuperAdmin', $this->checkSuperAdmin(...)),
            new TwigFunction('isPlatformOwner', $this->checkPlatformOwner(...)),
            new TwigFunction('feature', $this->checkFeature(...)),
        ];
    }

    public function checkPermission(string $permission): bool
    {
        $session = $this->getSession();
        if ($session === null) {
            return false;
        }

        return $this->can->check($session, $permission);
    }

    /**
     * Check if a feature is enabled for the current company.
     * Returns false if no session or the feature is not accessible.
     * Usage in templates: {% if feature('branch_create') %}
     */
    public function checkFeature(string $featureSlug): bool
    {
        $session = $this->getSession();
        if ($session === null || !isset($session->company)) {
            return false;
        }

        return $this->features->can($session->company->id, $featureSlug);
    }

    public function checkPlatformPermission(string $permission): bool
    {
        $session = $this->getSession();
        if ($session === null) {
            return false;
        }

        return $this->platformCan->check($session, $permission);
    }

    public function checkSuperAdmin(): bool
    {
        $session = $this->getSession();
        if ($session === null) {
            return false;
        }

        return $this->platformCan->isPlatformAdminSession($session);
    }

    public function checkPlatformOwner(): bool
    {
        $session = $this->getSession();
        if ($session === null) {
            return false;
        }

        return $this->platformCan->isPlatformOwner($session);
    }

    // ── Resolve once per request ──────────────────────────────────────────────

    private function getSession(): ?object
    {
        if ($this->sessionResolved) {
            return $this->resolvedSession;
        }

        $this->sessionResolved = true;

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        // Try cookie first (dashboard sessions), then Authorization header
        $token = $request->cookies->get('angavu_token');

        if (!$token) {
            $header = $request->headers->get('Authorization', '');
            if (str_starts_with($header, 'Bearer ')) {
                $token = substr($header, 7);
            }
        }

        if (!$token) {
            return null;
        }

        try {
            $this->resolvedSession = $this->auth->validateSession($token);

            // Resolve branch context from {branch} URL attribute so that can()
            // uses hierarchy-aware permissions rather than the flat user_roles table.
            $branchSlug = (string) ($request->attributes->get('branch', ''));
            if ($branchSlug !== '' && !$this->resolvedSession->user->isSuperAdmin) {
                $branch = $this->hierarchy->findBySlug(
                    $this->resolvedSession->company->id,
                    $branchSlug,
                );
                $this->resolvedSession->branch = $branch;
            }
        } catch (AuthException) {
            $this->resolvedSession = null;
        }

        return $this->resolvedSession;
    }
}
