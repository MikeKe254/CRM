<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Services\Auth\AuthService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/platform/owner', host: 'admin.{domain}', requirements: ['domain' => '.+'])]
final class OwnerConfigController extends PlatformBaseController
{
    public function __construct(
        AuthService $auth,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $platformCan);
    }

    // ── Permissions (company-level) ──────────────────────────────────────

    #[Route('/permissions', name: 'platform_owner_permissions', methods: ['GET'])]
    public function permissions(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, category, description, action_key, created_at
             FROM permissions
             ORDER BY category ASC, name ASC'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['category']][] = $row;
        }

        return $this->render('platform/owner/permissions/index.html.twig', [
            'session' => $session,
            'grouped' => $grouped,
            'total'   => count($rows),
        ]);
    }

    #[Route('/permissions', name: 'platform_owner_permissions_create', methods: ['POST'])]
    public function createPermission(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        $name        = trim((string) $request->request->get('name', ''));
        $category    = trim((string) $request->request->get('category', ''));
        $actionKey   = strtoupper(trim((string) $request->request->get('action_key', '')));
        $description = trim((string) $request->request->get('description', ''));

        if ($name === '' || $category === '' || $actionKey === '') {
            $this->addFlash('error', 'Name, category, and action key are required.');

            return $this->redirectToRoute('platform_owner_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $exists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM permissions WHERE name = :name OR action_key = :action_key LIMIT 1',
            ['name' => $name, 'action_key' => $actionKey],
        );

        if ($exists) {
            $this->addFlash('error', 'A permission with that name or action key already exists.');

            return $this->redirectToRoute('platform_owner_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $this->db->insert('permissions', [
            'name'        => $name,
            'category'    => $category,
            'description' => $description !== '' ? $description : null,
            'action_key'  => $actionKey,
        ]);

        $this->addFlash('success', 'Permission added.');

        return $this->redirectToRoute('platform_owner_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
    }

    #[Route('/permissions/{id}/edit', name: 'platform_owner_permissions_edit', methods: ['POST'])]
    public function editPermission(int $id, Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        $name        = trim((string) $request->request->get('name', ''));
        $category    = trim((string) $request->request->get('category', ''));
        $actionKey   = strtoupper(trim((string) $request->request->get('action_key', '')));
        $description = trim((string) $request->request->get('description', ''));

        if ($name === '' || $category === '' || $actionKey === '') {
            $this->addFlash('error', 'Name, category, and action key are required.');

            return $this->redirectToRoute('platform_owner_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $exists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM permissions WHERE (name = :name OR action_key = :action_key) AND id != :id LIMIT 1',
            ['name' => $name, 'action_key' => $actionKey, 'id' => $id],
        );

        if ($exists) {
            $this->addFlash('error', 'Another permission with that name or action key already exists.');

            return $this->redirectToRoute('platform_owner_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $this->db->update('permissions', [
            'name'        => $name,
            'category'    => $category,
            'description' => $description !== '' ? $description : null,
            'action_key'  => $actionKey,
        ], ['id' => $id]);

        $this->addFlash('success', 'Permission updated.');

        return $this->redirectToRoute('platform_owner_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
    }

    #[Route('/permissions/{id}/delete', name: 'platform_owner_permissions_delete', methods: ['POST'])]
    public function deletePermission(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return new JsonResponse(['ok' => false, 'error' => 'Unauthorized.'], 403);
        }

        try {
            $this->db->delete('permissions', ['id' => $id]);
        } catch (\Exception) {
            return new JsonResponse(['ok' => false, 'error' => 'Cannot delete — this permission is assigned to one or more roles.'], 422);
        }

        return new JsonResponse(['ok' => true]);
    }

    // ── Payment Methods ──────────────────────────────────────────────────

    #[Route('/payment-methods', name: 'platform_owner_payment_methods', methods: ['GET'])]
    public function paymentMethods(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        return $this->render('platform/owner/payment-methods/index.html.twig', [
            'session'         => $session,
            'payment_methods' => $this->db->fetchAllAssociative(
                'SELECT id, name, method_key, description, logo_url, is_active, sort_order, created_at
                 FROM payment_methods
                 ORDER BY sort_order ASC, name ASC'
            ),
        ]);
    }

    #[Route('/payment-methods', name: 'platform_owner_payment_methods_create', methods: ['POST'])]
    public function createPaymentMethod(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        $name        = trim((string) $request->request->get('name', ''));
        $methodKey   = strtolower(trim((string) $request->request->get('method_key', '')));
        $description = trim((string) $request->request->get('description', ''));
        $logoUrl     = trim((string) $request->request->get('logo_url', ''));
        $sortOrder   = max(0, (int) $request->request->get('sort_order', 0));
        $isActive    = $request->request->getBoolean('is_active', true);

        if ($name === '' || $methodKey === '') {
            $this->addFlash('error', 'Name and method key are required.');

            return $this->redirectToRoute('platform_owner_payment_methods', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $exists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM payment_methods WHERE name = :name OR method_key = :method_key LIMIT 1',
            ['name' => $name, 'method_key' => $methodKey],
        );

        if ($exists) {
            $this->addFlash('error', 'A payment method with that name or key already exists.');

            return $this->redirectToRoute('platform_owner_payment_methods', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $this->db->insert('payment_methods', [
            'name'        => $name,
            'method_key'  => $methodKey,
            'description' => $description !== '' ? $description : null,
            'logo_url'    => $logoUrl !== '' ? $logoUrl : null,
            'is_active'   => $isActive ? 1 : 0,
            'sort_order'  => $sortOrder,
        ]);

        $this->addFlash('success', 'Payment method added.');

        return $this->redirectToRoute('platform_owner_payment_methods', ['domain' => (string) $request->attributes->get('domain', '')]);
    }

    #[Route('/payment-methods/{id}/edit', name: 'platform_owner_payment_methods_edit', methods: ['POST'])]
    public function editPaymentMethod(int $id, Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        $name        = trim((string) $request->request->get('name', ''));
        $methodKey   = strtolower(trim((string) $request->request->get('method_key', '')));
        $description = trim((string) $request->request->get('description', ''));
        $logoUrl     = trim((string) $request->request->get('logo_url', ''));
        $sortOrder   = max(0, (int) $request->request->get('sort_order', 0));
        $isActive    = $request->request->getBoolean('is_active', false);

        if ($name === '' || $methodKey === '') {
            $this->addFlash('error', 'Name and method key are required.');

            return $this->redirectToRoute('platform_owner_payment_methods', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $exists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM payment_methods WHERE (name = :name OR method_key = :method_key) AND id != :id LIMIT 1',
            ['name' => $name, 'method_key' => $methodKey, 'id' => $id],
        );

        if ($exists) {
            $this->addFlash('error', 'Another payment method with that name or key already exists.');

            return $this->redirectToRoute('platform_owner_payment_methods', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $this->db->update('payment_methods', [
            'name'        => $name,
            'method_key'  => $methodKey,
            'description' => $description !== '' ? $description : null,
            'logo_url'    => $logoUrl !== '' ? $logoUrl : null,
            'is_active'   => $isActive ? 1 : 0,
            'sort_order'  => $sortOrder,
        ], ['id' => $id]);

        $this->addFlash('success', 'Payment method updated.');

        return $this->redirectToRoute('platform_owner_payment_methods', ['domain' => (string) $request->attributes->get('domain', '')]);
    }

    #[Route('/payment-methods/{id}/delete', name: 'platform_owner_payment_methods_delete', methods: ['POST'])]
    public function deletePaymentMethod(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return new JsonResponse(['ok' => false, 'error' => 'Unauthorized.'], 403);
        }

        try {
            $this->db->delete('payment_methods', ['id' => $id]);
        } catch (\Exception) {
            return new JsonResponse(['ok' => false, 'error' => 'Cannot delete — this payment method is referenced by tenant configs.'], 422);
        }

        return new JsonResponse(['ok' => true]);
    }

    // ── Platform Permissions ─────────────────────────────────────────────

    #[Route('/platform-permissions', name: 'platform_owner_platform_permissions', methods: ['GET'])]
    public function platformPermissions(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, category, description, action_key, created_at
             FROM platform_permissions
             ORDER BY category ASC, name ASC'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['category']][] = $row;
        }

        return $this->render('platform/owner/platform-permissions/index.html.twig', [
            'session' => $session,
            'grouped' => $grouped,
            'total'   => count($rows),
        ]);
    }

    #[Route('/platform-permissions', name: 'platform_owner_platform_permissions_create', methods: ['POST'])]
    public function createPlatformPermission(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        $name        = trim((string) $request->request->get('name', ''));
        $category    = trim((string) $request->request->get('category', ''));
        $actionKey   = strtoupper(trim((string) $request->request->get('action_key', '')));
        $description = trim((string) $request->request->get('description', ''));

        if ($name === '' || $category === '' || $actionKey === '') {
            $this->addFlash('error', 'Name, category, and action key are required.');

            return $this->redirectToRoute('platform_owner_platform_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $exists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM platform_permissions WHERE name = :name OR action_key = :action_key LIMIT 1',
            ['name' => $name, 'action_key' => $actionKey],
        );

        if ($exists) {
            $this->addFlash('error', 'A platform permission with that name or action key already exists.');

            return $this->redirectToRoute('platform_owner_platform_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $this->db->insert('platform_permissions', [
            'name'        => $name,
            'category'    => $category,
            'description' => $description !== '' ? $description : null,
            'action_key'  => $actionKey,
        ]);

        $this->addFlash('success', 'Platform permission added.');

        return $this->redirectToRoute('platform_owner_platform_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
    }

    #[Route('/platform-permissions/{id}/edit', name: 'platform_owner_platform_permissions_edit', methods: ['POST'])]
    public function editPlatformPermission(int $id, Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        $name        = trim((string) $request->request->get('name', ''));
        $category    = trim((string) $request->request->get('category', ''));
        $actionKey   = strtoupper(trim((string) $request->request->get('action_key', '')));
        $description = trim((string) $request->request->get('description', ''));

        if ($name === '' || $category === '' || $actionKey === '') {
            $this->addFlash('error', 'Name, category, and action key are required.');

            return $this->redirectToRoute('platform_owner_platform_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $exists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM platform_permissions WHERE (name = :name OR action_key = :action_key) AND id != :id LIMIT 1',
            ['name' => $name, 'action_key' => $actionKey, 'id' => $id],
        );

        if ($exists) {
            $this->addFlash('error', 'Another platform permission with that name or action key already exists.');

            return $this->redirectToRoute('platform_owner_platform_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
        }

        $this->db->update('platform_permissions', [
            'name'        => $name,
            'category'    => $category,
            'description' => $description !== '' ? $description : null,
            'action_key'  => $actionKey,
        ], ['id' => $id]);

        $this->addFlash('success', 'Platform permission updated.');

        return $this->redirectToRoute('platform_owner_platform_permissions', ['domain' => (string) $request->attributes->get('domain', '')]);
    }

    #[Route('/platform-permissions/{id}/delete', name: 'platform_owner_platform_permissions_delete', methods: ['POST'])]
    public function deletePlatformPermission(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return new JsonResponse(['ok' => false, 'error' => 'Unauthorized.'], 403);
        }

        try {
            $this->db->delete('platform_permissions', ['id' => $id]);
        } catch (\Exception) {
            return new JsonResponse(['ok' => false, 'error' => 'Cannot delete — this permission is assigned to one or more platform roles.'], 422);
        }

        return new JsonResponse(['ok' => true]);
    }

    // ── Features ─────────────────────────────────────────────────────────

    #[Route('/features', name: 'platform_owner_features', methods: ['GET'])]
    public function features(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return $session;
        }

        return $this->render('platform/owner/features/index.html.twig', [
            'session' => $session,
        ]);
    }
}
