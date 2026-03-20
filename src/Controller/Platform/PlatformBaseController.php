<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Services\Auth\AuthService;
use App\Services\Auth\DTO\AuthResult;
use App\Services\Auth\Exception\AuthException;
use App\Services\Permission\PlatformCheckPermissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class PlatformBaseController extends AbstractController
{
    public function __construct(
        protected readonly AuthService $auth,
        protected readonly PlatformCheckPermissionService $platformCan,
    ) {}

    protected function requirePlatform(Request $request, ?string $permission = null): AuthResult|Response
    {
        $token = $this->resolveToken($request);

        if (!$token) {
            return $this->redirectToRoute('platform_login', [
                'domain' => (string) $request->attributes->get('domain', ''),
            ]);
        }

        try {
            $session = $this->auth->validateSession($token);
        } catch (AuthException) {
            return $this->redirectToPlatformLoginClearingCookie();
        }

        if ($session->deviceType !== 'dashboard') {
            return $this->redirectToPlatformLoginClearingCookie();
        }

        if (!$this->platformCan->isPlatformAdminSession($session)) {
            return $this->redirectToPlatformLoginClearingCookie();
        }

        if ($permission !== null && !$this->platformCan->check($session, $permission)) {
            return $this->renderPlatform403(
                session: $session,
                message: 'You do not have permission to access this platform page.',
            );
        }

        return $session;
    }

    /**
     * Require the caller to be a platform owner (isPlatformOwner === true).
     * Regular platform admins — even those with broad permissions — are rejected.
     * Use this for any route that should be owner-exclusive.
     */
    protected function requirePlatformOwner(Request $request): AuthResult|Response
    {
        $session = $this->requirePlatform($request);
        if ($session instanceof Response) {
            return $session;
        }

        if (!$this->platformCan->isPlatformOwner($session)) {
            return $this->renderPlatform403(
                session: $session,
                message: 'This area is restricted to platform owners.',
            );
        }

        return $session;
    }

    private function renderPlatform403(AuthResult $session, string $message): Response
    {
        return $this->render('platform/errors/403.html.twig', [
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

    private function redirectToPlatformLoginClearingCookie(): Response
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $response = $this->redirectToRoute('platform_login', [
            'domain' => (string) $request?->attributes->get('domain', ''),
        ]);
        $response->headers->clearCookie('angavu_token', '/');

        return $response;
    }
}
