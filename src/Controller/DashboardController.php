<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Main application dashboard.
 * Requires a valid angavu_token session cookie.
 * No terminal cookie needed — this is the management dashboard, not POS.
 */
final class DashboardController extends AbstractController
{
    public function __construct(private readonly AuthService $auth) {}

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(Request $request): Response
    {
        $token = $this->resolveToken($request);

        if (!$token) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $session = $this->auth->validateSession($token);
        } catch (AuthException) {
            return $this->redirectToRoute('app_login');
        }

        // POS sessions are not allowed here — dashboard only
        if ($session->deviceType === 'pos') {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('dashboard/index.html.twig', [
            'session' => $session,
        ]);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function resolveToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->cookies->get('angavu_token') ?: null;
    }
}