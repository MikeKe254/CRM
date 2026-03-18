<?php

declare(strict_types=1);

namespace App\Twig;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Permission\CheckPermissionService;
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
        private readonly AuthService            $auth,
        private readonly CheckPermissionService $can,
        private readonly RequestStack           $requestStack,
    ) {}

    public function getGlobals(): array
    {
        return [];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can', $this->checkPermission(...)),
            new TwigFunction('isSuperAdmin', $this->checkSuperAdmin(...)),
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

    public function checkSuperAdmin(): bool
    {
        $session = $this->getSession();
        if ($session === null) {
            return false;
        }

        return $session->user->isSuperAdmin;
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
        } catch (AuthException) {
            $this->resolvedSession = null;
        }

        return $this->resolvedSession;
    }
}
