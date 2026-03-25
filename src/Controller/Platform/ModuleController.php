<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Services\Auth\AuthService;
use App\Services\Feature\PlatformFeatureService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/platform/modules', host: 'admin.{domain}', requirements: ['domain' => '.+'])]
final class ModuleController extends PlatformBaseController
{
    public function __construct(
        AuthService $auth,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection $db,
        private readonly PlatformFeatureService $platform,
    ) {
        parent::__construct($auth, $platformCan);
    }

    // =========================================================================
    // INDEX — full module → submodule → feature tree
    // =========================================================================

    #[Route('', name: 'platform_modules', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return $session;

        // Three flat queries, tree assembled in PHP — no N+1
        $modules = $this->db->fetchAllAssociative(
            'SELECT id, name, slug, icon, description, sort_order, is_active, platform_released
               FROM modules
              ORDER BY sort_order ASC, name ASC',
        );

        $submodules = $this->db->fetchAllAssociative(
            'SELECT id, module_id, name, slug, description, sort_order, is_active
               FROM module_submodules
              ORDER BY sort_order ASC, name ASC',
        );

        $features = $this->db->fetchAllAssociative(
            'SELECT id, submodule_id, name, slug, description, sort_order, is_active, platform_released
               FROM module_features
              ORDER BY sort_order ASC, name ASC',
        );

        // Index features by submodule_id
        $featuresBySubmodule = [];
        foreach ($features as $f) {
            $featuresBySubmodule[(int) $f['submodule_id']][] = $f;
        }

        // Index submodules by module_id, attach their features
        $submodulesByModule = [];
        foreach ($submodules as &$sm) {
            $sm['features'] = $featuresBySubmodule[(int) $sm['id']] ?? [];
            $submodulesByModule[(int) $sm['module_id']][] = $sm;
        }
        unset($sm);

        // Attach submodules to each module
        foreach ($modules as &$m) {
            $m['submodules'] = $submodulesByModule[(int) $m['id']] ?? [];
        }
        unset($m);

        return $this->render('platform/modules/index.html.twig', [
            'modules' => $modules,
        ]);
    }

    // =========================================================================
    // MODULES
    // =========================================================================

    #[Route('/create', name: 'platform_modules_create', methods: ['POST'])]
    public function createModule(Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name  = trim((string) $request->request->get('name', ''));
        $slug  = trim((string) $request->request->get('slug', ''));
        $icon  = trim((string) $request->request->get('icon', '')) ?: null;
        $desc  = trim((string) $request->request->get('description', '')) ?: null;
        $order = (int) $request->request->get('sort_order', 0);

        if ($name === '' || $slug === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name and slug are required.']);
        }

        try {
            $this->db->insert('modules', [
                'name'        => $name,
                'slug'        => $this->normalizeSlug($slug),
                'icon'        => $icon,
                'description' => $desc,
                'sort_order'  => $order,
                'is_active'   => 1,
            ]);

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e, 'slug')]);
        }
    }

    #[Route('/{id}/edit', name: 'platform_modules_edit', methods: ['POST'])]
    public function editModule(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name  = trim((string) $request->request->get('name', ''));
        $icon  = trim((string) $request->request->get('icon', '')) ?: null;
        $desc  = trim((string) $request->request->get('description', '')) ?: null;
        $order = (int) $request->request->get('sort_order', 0);

        if ($name === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name is required.']);
        }

        try {
            $this->db->update('modules', [
                'name'        => $name,
                'icon'        => $icon,
                'description' => $desc,
                'sort_order'  => $order,
            ], ['id' => $id]);

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    #[Route('/{id}/delete', name: 'platform_modules_delete', methods: ['POST'])]
    public function deleteModule(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        try {
            $this->db->delete('modules', ['id' => $id]);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    // =========================================================================
    // SUBMODULES
    // =========================================================================

    #[Route('/{moduleId}/submodules/create', name: 'platform_submodules_create', methods: ['POST'])]
    public function createSubmodule(int $moduleId, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name  = trim((string) $request->request->get('name', ''));
        $slug  = trim((string) $request->request->get('slug', ''));
        $desc  = trim((string) $request->request->get('description', '')) ?: null;
        $order = (int) $request->request->get('sort_order', 0);

        if ($name === '' || $slug === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name and slug are required.']);
        }

        try {
            $this->db->insert('module_submodules', [
                'module_id'   => $moduleId,
                'name'        => $name,
                'slug'        => $this->normalizeSlug($slug),
                'description' => $desc,
                'sort_order'  => $order,
                'is_active'   => 1,
            ]);

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e, 'slug')]);
        }
    }

    #[Route('/submodules/{id}/edit', name: 'platform_submodules_edit', methods: ['POST'])]
    public function editSubmodule(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name  = trim((string) $request->request->get('name', ''));
        $desc  = trim((string) $request->request->get('description', '')) ?: null;
        $order = (int) $request->request->get('sort_order', 0);

        if ($name === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name is required.']);
        }

        try {
            $this->db->update('module_submodules', [
                'name'        => $name,
                'description' => $desc,
                'sort_order'  => $order,
            ], ['id' => $id]);

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    #[Route('/submodules/{id}/delete', name: 'platform_submodules_delete', methods: ['POST'])]
    public function deleteSubmodule(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        try {
            $this->db->delete('module_submodules', ['id' => $id]);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    // =========================================================================
    // FEATURES
    // =========================================================================

    #[Route('/submodules/{submoduleId}/features/create', name: 'platform_features_create', methods: ['POST'])]
    public function createFeature(int $submoduleId, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name  = trim((string) $request->request->get('name', ''));
        $slug  = trim((string) $request->request->get('slug', ''));
        $desc  = trim((string) $request->request->get('description', '')) ?: null;
        $order = (int) $request->request->get('sort_order', 0);

        if ($name === '' || $slug === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name and slug are required.']);
        }

        try {
            $this->db->insert('module_features', [
                'submodule_id'     => $submoduleId,
                'name'             => $name,
                'slug'             => $this->normalizeSlug($slug),
                'description'      => $desc,
                'sort_order'       => $order,
                'is_active'        => 1,
                'platform_released' => 0,
            ]);

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e, 'slug')]);
        }
    }

    #[Route('/features/{id}/edit', name: 'platform_features_edit', methods: ['POST'])]
    public function editFeature(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        $name  = trim((string) $request->request->get('name', ''));
        $desc  = trim((string) $request->request->get('description', '')) ?: null;
        $order = (int) $request->request->get('sort_order', 0);

        if ($name === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name is required.']);
        }

        try {
            $this->db->update('module_features', [
                'name'        => $name,
                'description' => $desc,
                'sort_order'  => $order,
            ], ['id' => $id]);

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    #[Route('/features/{id}/delete', name: 'platform_features_delete', methods: ['POST'])]
    public function deleteFeature(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);

        try {
            $this->db->delete('module_features', ['id' => $id]);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $this->dbError($e)]);
        }
    }

    // =========================================================================
    // RELEASE GATES
    // =========================================================================

    #[Route('/{id}/release', name: 'platform_modules_toggle_release', methods: ['POST'])]
    public function toggleModuleRelease(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        $released = (bool) $request->request->get('released', 0);
        $this->platform->toggleModule($id, $released);

        return new JsonResponse(['ok' => true, 'released' => $released]);
    }

    #[Route('/features/{id}/release', name: 'platform_features_toggle_release', methods: ['POST'])]
    public function toggleRelease(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) {
            return new JsonResponse(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        $released = (bool) $request->request->get('released', 0);
        $this->platform->toggle($id, $released);

        return new JsonResponse(['ok' => true, 'released' => $released]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Lowercase, spaces/dashes → underscores, strip anything non-alphanumeric.
     */
    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower($slug);
        $slug = str_replace([' ', '-'], '_', $slug);

        return (string) preg_replace('/[^a-z0-9_]/', '', $slug);
    }

    /**
     * Turn a raw DB exception into a clean user-facing message.
     */
    private function dbError(\Throwable $e, string $uniqueColumn = ''): string
    {
        if ($uniqueColumn !== '' && str_contains($e->getMessage(), '1062')) {
            return ucfirst($uniqueColumn) . ' already exists. Choose a different one.';
        }

        return 'A database error occurred. Please try again.';
    }
}
