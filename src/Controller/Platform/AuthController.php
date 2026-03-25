<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(host: 'admin.{domain}', requirements: ['domain' => '.+'])]
final class AuthController extends AbstractController
{
    public function __construct(private readonly AuthService $auth) {}

    #[Route('/', name: 'platform_home', methods: ['GET'])]
    public function home(Request $request, string $domain): Response
    {
        $token = $this->resolveToken($request);

        if ($token) {
            try {
                $session = $this->auth->validateSession($token);

                if ($session->deviceType === 'dashboard' && $session->user->isSuperAdmin) {
                    return $this->redirect('/platform/dashboard');
                }
            } catch (AuthException) {
                return $this->redirectToPlatformLoginClearingCookie();
            }
        }

        return $this->redirect('/platform/login');
    }

    #[Route('/platform/login', name: 'platform_login', methods: ['GET'])]
    public function loginPage(Request $request, string $domain): Response
    {
        $token = $this->resolveToken($request);

        if ($token) {
            try {
                $session = $this->auth->validateSession($token);

                if ($session->deviceType === 'dashboard' && $session->user->isSuperAdmin) {
                    return $this->redirect('/platform/dashboard');
                }
            } catch (AuthException) {
                return $this->redirectToPlatformLoginClearingCookie();
            }
        }

        return $this->render('platform/auth/login.html.twig');
    }

    #[Route('/platform/login/auth', name: 'platform_login_auth', methods: ['POST'])]
    public function authenticate(Request $request, string $domain): JsonResponse
    {
        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');
        $remember = (bool) $request->request->get('remember', false);

        if ($email === '' || $password === '') {
            return $this->json(['success' => false, 'message' => 'Email and password are required.'], 400);
        }

        try {
            $result = $this->auth->loginSuperAdmin(
                email: $email,
                password: $password,
                ipAddress: $request->getClientIp() ?? '',
                userAgent: $request->headers->get('User-Agent') ?? '',
                deviceName: 'Platform Dashboard',
            );

            $response = $this->json([
                'success' => true,
                'redirect' => '/platform/dashboard',
                'data' => $result->toArray(),
            ]);

            $cookieTtl = $remember ? 60 * 60 * 24 * 30 : 0;
            $response->headers->setCookie(
                Cookie::create('angavu_token')
                    ->withValue($result->token)
                    ->withExpires($cookieTtl > 0 ? time() + $cookieTtl : 0)
                    ->withPath('/')
                    ->withHttpOnly(true)
                    ->withSameSite('lax'),
            );

            return $response;
        } catch (AuthException) {
            return $this->json([
                'success' => false,
                'message' => 'Wrong login details. Please confirm your platform admin credentials and try again.',
            ], 401);
        }
    }

    #[Route('/platform/logout', name: 'platform_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if ($token) {
            $this->auth->logout($token);
        }

        $response = $this->json(['success' => true, 'message' => 'Logged out.']);
        $response->headers->clearCookie('angavu_token', '/');

        return $response;
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
        $response = $this->redirectToRoute('platform_login', [
            'domain' => (string) $this->container->get('request_stack')->getCurrentRequest()?->attributes->get('domain', ''),
        ]);
        $response->headers->clearCookie('angavu_token', '/');

        return $response;
    }
}
