<?php

declare(strict_types=1);

namespace App\Controller\Mpesa;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Customer\CustomerMetricsService;
use App\Services\Permission\CheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles customer profile loading.
 * Replaces: Legacy/mpesa/ajax/customer_profile.php
 *
 * Returns JSON with pre-rendered HTML (via Twig) exactly like the legacy did,
 * so ui.js can inject it directly into the modal — no JS rendering needed.
 *
 * Route:
 *   GET|POST /mpesa/customer/profile?msisdn=254...
 */
#[Route('/mpesa/customer', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+'])]
class CustomerController extends AbstractController
{
    public function __construct(
        private readonly AuthService             $auth,
        private readonly CheckPermissionService  $can,
        private readonly CustomerMetricsService  $metrics,
        private readonly Connection              $db,
    ) {}

    // =========================================================================
    // GET|POST /mpesa/customer/profile
    // Permission: view_customer_profile
    // =========================================================================

    #[Route('/profile', name: 'mpesa_customer_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): JsonResponse
    {
        $session = $this->requireSession($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        if (!$this->can->check($session, 'view_customer_profile')) {
            return $this->json([
                'success' => false,
                'data'    => '<p class="text-red-500">Access denied to customer profiles.</p>',
            ], 403);
        }

        // Accept msisdn from GET or POST — same as legacy
        $msisdn = trim((string) (
            $request->query->get('msisdn')
            ?? $request->request->get('msisdn', '')
        ));

        if ($msisdn === '') {
            return $this->json([
                'success' => false,
                'data'    => '<p class="text-red-500">Phone number missing.</p>',
            ], 400);
        }

        $msisdn = $this->normalizePhone($msisdn);
        if (!$msisdn) {
            return $this->json([
                'success' => false,
                'data'    => '<p class="text-red-500">Invalid phone number format.</p>',
            ], 400);
        }

        // ── Fetch customer profile ─────────────────────────────────────────────
        $customer = $this->db->fetchAssociative(
            'SELECT * FROM customer_profiles
             WHERE msisdn = :msisdn
               AND company_id = :company_id
             LIMIT 1',
            ['msisdn' => $msisdn, 'company_id' => $session->company->id],
        );

        if (!$customer) {
            return $this->json([
                'success' => false,
                'data'    => '<p class="text-red-500">Customer profile not found.</p>',
            ], 404);
        }

        // ── Mask phone if user lacks full phone permission ─────────────────────
        if (!$this->can->check($session, 'view_full_customer_phone')) {
            $customer['msisdn'] = substr($customer['msisdn'], 0, 7)
                . '***'
                . substr($customer['msisdn'], -2);
        }

        // ── Build sections using CustomerMetricsService ────────────────────────
        $sections = $this->metrics->buildSections($customer);

        // ── Render via existing Twig template (same as legacy) ─────────────────
        $html = $this->renderView('Legacy/customer_profile.twig', [
            'customer' => $customer,
            'sections' => $sections,
        ]);

        // ── Return JSON with rendered HTML — ui.js injects it directly ─────────
        return $this->json([
            'success' => true,
            'data'    => $html,
        ]);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function normalizePhone(string $phone): ?string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (strlen($phone) === 9 && str_starts_with($phone, '7')) {
            return '254' . $phone;
        }
        if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }
        if (strlen($phone) === 12 && str_starts_with($phone, '254')) {
            return $phone;
        }

        return null;
    }

    private function requireSession(Request $request): mixed
    {
        $token = $this->resolveToken($request);

        if (!$token) {
            return $this->json([
                'success' => false,
                'data'    => '<p class="text-red-500">Unauthenticated.</p>',
            ], 401);
        }

        try {
            return $this->auth->validateSession($token);
        } catch (AuthException $e) {
            return $this->json([
                'success' => false,
                'data'    => '<p class="text-red-500">' . $e->getMessage() . '</p>',
            ], $e->getHttpStatus());
        }
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
