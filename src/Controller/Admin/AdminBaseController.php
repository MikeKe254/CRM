<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Auth\DTO\AuthResult;
use App\Services\Auth\Exception\AuthException;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base controller for all admin panel controllers.
 *
 * Handles:
 *   - Validating angavu_token cookie
 *   - Rejecting POS sessions
 *   - Checking required permissions
 *   - Providing helpers for JSON responses
 *
 * Access denial behaviour:
 *   - No token / expired session (401) → redirect to login
 *   - Valid session, missing permission (403) → render in-layout 403 page
 *   - Fetch/AJAX requests (any) → JSON error
 */
abstract class AdminBaseController extends AbstractController
{
    public function __construct(
        protected readonly AuthService            $auth,
        protected readonly CheckPermissionService $can,
        protected readonly PlatformCheckPermissionService $platformCan,
    ) {}

    // =========================================================================
    // GUARD
    // =========================================================================

    /**
     * Require a valid dashboard session with the given permission.
     * Returns AuthResult on success.
     * Returns Response on failure — caller must return it immediately.
     *
     * @param string|null $permission  Pass null to only check for valid dashboard session
     */
    protected function requireAdmin(
        Request $request,
        ?string $permission = null,
    ): AuthResult|Response {
        $token = $this->resolveToken($request);

        if (!$token) {
            return $this->denyAccess($request, 'You must be logged in.');
        }

        try {
            $session = $this->auth->validateSession($token);
        } catch (AuthException $e) {
            return $this->denyAccess($request, $e->getMessage());
        }

        // Reject POS sessions
        if ($session->deviceType === 'pos') {
            return $this->denyAccess($request, 'POS sessions cannot access the admin panel.');
        }

        // Check permission if required.
        // Platform admins (isSuperAdmin) bypass tenant permission checks entirely —
        // their access is governed by their platform-level permissions, not tenant roles.
        if ($permission !== null && !$session->user->isSuperAdmin && !$this->can->check($session, $permission)) {
            return $this->denyAccess($request, 'You do not have permission to access this page.', 403, $session);
        }

        return $session;
    }

    /**
     * Require super admin access only.
     */
    protected function requireSuperAdmin(Request $request): AuthResult|Response
    {
        $session = $this->requireAdmin($request);

        if ($session instanceof Response) {
            return $session;
        }

        if (!$this->platformCan->isPlatformAdminSession($session)) {
            return $this->denyAccess($request, 'Super admin access required.', 403, $session);
        }

        return $session;
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    /**
     * Success JSON response for fetch actions.
     */
    protected function success(string $message, array $data = []): JsonResponse
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * Error JSON response for fetch actions.
     */
    protected function error(string $message, int $status = 400): JsonResponse
    {
        return $this->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Deny access with context-aware response:
     *
     *   - Fetch/AJAX request       → JSON error (any status)
     *   - 401 Unauthenticated      → redirect to login page
     *   - 403 Forbidden            → render in-layout 403 page (sidebar stays visible)
     */
    private function denyAccess(Request $request, string $message, int $status = 401, ?object $session = null): Response
    {
        // Fetch/AJAX — always return JSON regardless of status
        if (
            $request->isXmlHttpRequest() ||
            str_contains($request->headers->get('Accept', ''), 'application/json') ||
            (
                $request->headers->get('Content-Type') === 'application/x-www-form-urlencoded'
                && $request->isMethod('POST')
            )
        ) {
            return $this->json(['success' => false, 'message' => $message], $status);
        }

        // Not logged in / session expired → send to login
        if ($status === 401) {
            return $this->redirectToRoute('app_login', [
                'subdomain' => (string) $request->attributes->get('subdomain', ''),
                'domain' => (string) $request->attributes->get('domain', ''),
            ]);
        }

        // Logged in but no permission → in-layout 403 page
        // Pass session so the template can display the logged-in user's name.
        return $this->render('errors/403.html.twig', [
            'message' => $message,
            'session' => $session,
        ], new Response('', Response::HTTP_FORBIDDEN));
    }

    private function resolveToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->cookies->get('angavu_token') ?: null;
    }
}
