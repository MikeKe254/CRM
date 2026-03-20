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

#[Route('/platform/plans', host: 'admin.{domain}', requirements: ['domain' => '.+'])]
final class PlanController extends PlatformBaseController
{
    public function __construct(
        AuthService $auth,
        PlatformCheckPermissionService $platformCan,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $platformCan);
    }

    // =========================================================================
    // PLANS — LIST
    // =========================================================================

    #[Route('', name: 'platform_plans', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return $session;

        $plans = $this->db->fetchAllAssociative(
            'SELECT p.*,
                    COUNT(DISTINCT pf.feature_id) AS feature_count,
                    COUNT(DISTINCT pl.id)          AS limit_count
             FROM plans p
             LEFT JOIN plan_features pf ON pf.plan_id = p.id
             LEFT JOIN plan_limits   pl ON pl.plan_id = p.id
             GROUP BY p.id
             ORDER BY p.sort_order ASC, p.id ASC',
        );

        return $this->render('platform/plans/index.html.twig', [
            'session' => $session,
            'plans'   => $plans,
            'canEdit' => $this->platformCan->check($session, 'manage_plans'),
        ]);
    }

    // =========================================================================
    // PLANS — CREATE
    // =========================================================================

    #[Route('/create', name: 'platform_plans_create', methods: ['POST'])]
    public function createPlan(Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return $session;

        $name         = trim($request->request->get('name', ''));
        $slug         = trim($request->request->get('slug', ''));
        $description  = trim($request->request->get('description', ''));
        $monthlyPrice = (float) $request->request->get('monthly_price', 0);
        $annualPrice  = (float) $request->request->get('annual_price', 0);
        $trialDays    = (int)   $request->request->get('trial_days', 0);
        $isPublic     = $request->request->getBoolean('is_public', true);
        $sortOrder    = (int)   $request->request->get('sort_order', 0);

        if (!$name || !$slug) {
            $this->addFlash('error', 'Name and slug are required.');
            return $this->redirectToRoute('platform_plans', ['domain' => $request->attributes->get('domain')]);
        }

        $slug = strtolower(preg_replace('/[^a-z0-9_-]/', '', $slug));

        $exists = $this->db->fetchOne('SELECT id FROM plans WHERE slug = :slug', ['slug' => $slug]);
        if ($exists) {
            $this->addFlash('error', "A plan with slug '{$slug}' already exists.");
            return $this->redirectToRoute('platform_plans', ['domain' => $request->attributes->get('domain')]);
        }

        $this->db->insert('plans', [
            'name'          => $name,
            'slug'          => $slug,
            'description'   => $description ?: null,
            'monthly_price' => $monthlyPrice,
            'annual_price'  => $annualPrice,
            'trial_days'    => $trialDays,
            'is_public'     => $isPublic ? 1 : 0,
            'sort_order'    => $sortOrder,
        ]);

        $this->addFlash('success', "Plan '{$name}' created.");
        return $this->redirectToRoute('platform_plans', ['domain' => $request->attributes->get('domain')]);
    }

    // =========================================================================
    // PLANS — EDIT
    // =========================================================================

    #[Route('/{id}/edit', name: 'platform_plans_edit', methods: ['POST'])]
    public function editPlan(int $id, Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return $session;

        $plan = $this->db->fetchAssociative('SELECT * FROM plans WHERE id = :id', ['id' => $id]);
        if (!$plan) {
            $this->addFlash('error', 'Plan not found.');
            return $this->redirectToRoute('platform_plans', ['domain' => $request->attributes->get('domain')]);
        }

        $name         = trim($request->request->get('name', ''));
        $description  = trim($request->request->get('description', ''));
        $monthlyPrice = (float) $request->request->get('monthly_price', 0);
        $annualPrice  = (float) $request->request->get('annual_price', 0);
        $trialDays    = (int)   $request->request->get('trial_days', 0);
        $isPublic     = $request->request->getBoolean('is_public', true);
        $isActive     = $request->request->getBoolean('is_active', true);
        $sortOrder    = (int)   $request->request->get('sort_order', 0);

        if (!$name) {
            $this->addFlash('error', 'Name is required.');
            return $this->redirectToRoute('platform_plans', ['domain' => $request->attributes->get('domain')]);
        }

        $this->db->update('plans', [
            'name'          => $name,
            'description'   => $description ?: null,
            'monthly_price' => $monthlyPrice,
            'annual_price'  => $annualPrice,
            'trial_days'    => $trialDays,
            'is_public'     => $isPublic ? 1 : 0,
            'is_active'     => $isActive ? 1 : 0,
            'sort_order'    => $sortOrder,
        ], ['id' => $id]);

        $this->addFlash('success', "Plan '{$name}' updated.");
        return $this->redirectToRoute('platform_plans', ['domain' => $request->attributes->get('domain')]);
    }

    // =========================================================================
    // PLANS — DELETE
    // =========================================================================

    #[Route('/{id}/delete', name: 'platform_plans_delete', methods: ['POST'])]
    public function deletePlan(int $id, Request $request): JsonResponse
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return new JsonResponse(['ok' => false, 'error' => 'Unauthorized.'], 403);

        $plan = $this->db->fetchAssociative('SELECT name FROM plans WHERE id = :id', ['id' => $id]);
        if (!$plan) return new JsonResponse(['ok' => false, 'error' => 'Plan not found.'], 404);

        try {
            $this->db->delete('plans', ['id' => $id]);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'Cannot delete — plan may be assigned to companies.'], 422);
        }
    }

    // =========================================================================
    // PLAN FEATURES — MATRIX
    // =========================================================================

    #[Route('/{id}/features', name: 'platform_plan_features', methods: ['GET'])]
    public function planFeatures(int $id, Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return $session;

        $plan = $this->db->fetchAssociative('SELECT * FROM plans WHERE id = :id', ['id' => $id]);
        if (!$plan) {
            $this->addFlash('error', 'Plan not found.');
            return $this->redirectToRoute('platform_plans', ['domain' => $request->attributes->get('domain')]);
        }

        $enabledIds = $this->db->fetchFirstColumn(
            'SELECT feature_id FROM plan_features WHERE plan_id = :id',
            ['id' => $id],
        );
        $enabledSet = array_flip(array_map('intval', $enabledIds));

        $modules = $this->db->fetchAllAssociative(
            'SELECT * FROM modules ORDER BY sort_order ASC, name ASC',
        );

        foreach ($modules as &$module) {
            $submodules = $this->db->fetchAllAssociative(
                'SELECT * FROM module_submodules WHERE module_id = :id ORDER BY sort_order ASC, name ASC',
                ['id' => $module['id']],
            );

            $moduleEnabledCount = 0;
            $moduleTotalCount   = 0;

            foreach ($submodules as &$submodule) {
                $features = $this->db->fetchAllAssociative(
                    'SELECT * FROM module_features WHERE submodule_id = :id ORDER BY sort_order ASC, name ASC',
                    ['id' => $submodule['id']],
                );

                foreach ($features as &$feature) {
                    $feature['is_enabled'] = isset($enabledSet[(int) $feature['id']]);
                    if ($feature['is_enabled']) $moduleEnabledCount++;
                    $moduleTotalCount++;
                }
                unset($feature);

                $submodule['features']      = $features;
                $submodule['enabled_count'] = count(array_filter($features, fn($f) => $f['is_enabled']));
                $submodule['total_count']   = count($features);
            }
            unset($submodule);

            $module['submodules']    = $submodules;
            $module['enabled_count'] = $moduleEnabledCount;
            $module['total_count']   = $moduleTotalCount;
        }
        unset($module);

        $limitRows = $this->db->fetchAllAssociative(
            'SELECT * FROM plan_limits WHERE plan_id = :id ORDER BY limit_key ASC',
            ['id' => $id],
        );
        $limits = [];
        foreach ($limitRows as $l) {
            $limits[$l['limit_key']] = $l['limit_value'];
        }

        $allFeatureCount = (int) $this->db->fetchOne('SELECT COUNT(*) FROM module_features');

        return $this->render('platform/plans/features.html.twig', [
            'session'        => $session,
            'plan'           => $plan,
            'modules'        => $modules,
            'limits'         => $limits,
            'enabled_count'  => count($enabledIds),
            'total_features' => $allFeatureCount,
        ]);
    }

    // =========================================================================
    // PLAN FEATURES — SAVE
    // =========================================================================

    #[Route('/{id}/features/save', name: 'platform_plan_features_save', methods: ['POST'])]
    public function savePlanFeatures(int $id, Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return $session;

        $plan = $this->db->fetchAssociative('SELECT * FROM plans WHERE id = :id', ['id' => $id]);
        if (!$plan) {
            $this->addFlash('error', 'Plan not found.');
            return $this->redirectToRoute('platform_plans', ['domain' => $request->attributes->get('domain')]);
        }

        $featureIds = array_map('intval', $request->request->all('features') ?? []);
        $featureIds = array_values(array_unique(array_filter($featureIds, fn($v) => $v > 0)));

        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('DELETE FROM plan_features WHERE plan_id = :id', ['id' => $id]);
            foreach ($featureIds as $featureId) {
                $this->db->insert('plan_features', ['plan_id' => $id, 'feature_id' => $featureId]);
            }
            $this->db->commit();
            $this->addFlash('success', count($featureIds) . ' features saved for ' . $plan['name'] . '.');
        } catch (\Throwable) {
            $this->db->rollBack();
            $this->addFlash('error', 'Failed to save features. Please try again.');
        }

        return $this->redirectToRoute('platform_plan_features', [
            'id'     => $id,
            'domain' => $request->attributes->get('domain'),
        ]);
    }

    // =========================================================================
    // PLAN LIMITS — SAVE
    // =========================================================================

    #[Route('/{id}/limits/save', name: 'platform_plan_limits_save', methods: ['POST'])]
    public function savePlanLimits(int $id, Request $request): Response
    {
        $session = $this->requirePlatformOwner($request);
        if ($session instanceof Response) return $session;

        $plan = $this->db->fetchAssociative('SELECT * FROM plans WHERE id = :id', ['id' => $id]);
        if (!$plan) {
            $this->addFlash('error', 'Plan not found.');
            return $this->redirectToRoute('platform_plans', ['domain' => $request->attributes->get('domain')]);
        }

        foreach ($request->request->all('limits') ?? [] as $key => $value) {
            $key      = preg_replace('/[^a-z0-9_]/', '', $key);
            $value    = (int) $value;
            $existing = $this->db->fetchOne(
                'SELECT id FROM plan_limits WHERE plan_id = :plan_id AND limit_key = :key',
                ['plan_id' => $id, 'key' => $key],
            );
            if ($existing) {
                $this->db->update('plan_limits', ['limit_value' => $value], ['id' => $existing]);
            } else {
                $this->db->insert('plan_limits', ['plan_id' => $id, 'limit_key' => $key, 'limit_value' => $value]);
            }
        }

        $this->addFlash('success', 'Limits updated for ' . $plan['name'] . '.');
        return $this->redirectToRoute('platform_plan_features', [
            'id'     => $id,
            'domain' => $request->attributes->get('domain'),
        ]);
    }
}
