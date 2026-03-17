<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Auth\DTO\AuthResult;
use App\Services\Auth\Exception\AuthException;
use App\Services\Permission\CheckPermissionService;
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
 * Usage:
 *   class UserController extends AdminBaseController
 *   {
 *       public function index(Request $request): Response
 *       {
 *           $session = $this->requireAdmin($request, 'view_users');
 *           if ($session instanceof Response) return $session;
 *           ...
 *       }
 *   }
 */
abstract class AdminBaseController extends AbstractController
{
    public function __construct(
        protected readonly AuthService            $auth,
        protected readonly CheckPermissionService $can,
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

        // Check permission if required
        if ($permission !== null && !$this->can->check($session, $permission)) {
            return $this->denyAccess($request, 'You do not have permission to access this.', 403);
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

        if (!$session->user->isSuperAdmin) {
            return $this->denyAccess($request, 'Super admin access required.', 403);
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
     * Deny access.
     * Returns JSON for fetch/AJAX requests.
     * Returns redirect to login for page requests.
     */
    private function denyAccess(Request $request, string $message, int $status = 401): Response
    {
        // Detect fetch/AJAX request
        if (
            $request->isXmlHttpRequest() ||
            str_contains($request->headers->get('Accept', ''), 'application/json') ||
            $request->headers->get('Content-Type') === 'application/x-www-form-urlencoded' && $request->isMethod('POST')
        ) {
            return $this->json(['success' => false, 'message' => $message], $status);
        }

        return $this->redirectToRoute('app_login');
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
