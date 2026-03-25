<?php

declare(strict_types=1);

namespace App\Services\Feature;

use Doctrine\DBAL\Connection;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║              Angavu Platform Feature Release Service             ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Answers: "Has the platform owner released feature X globally?"  ║
 * ║                                                                  ║
 * ║  This is the OUTERMOST gate — it sits above plan checks,         ║
 * ║  above tenant overrides, above everything.                       ║
 * ║                                                                  ║
 * ║  Resolution: BOTH the parent module AND the feature itself       ║
 * ║  must have platform_released = 1 for access to be granted.       ║
 * ║  If the module is unreleased, every feature under it is blocked. ║
 * ║                                                                  ║
 * ║  TenantFeatureAccessService consults this as step 0.             ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
final class PlatformFeatureService
{
    /** Per-request cache keyed by feature slug */
    private array $cache = [];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Returns true only if BOTH the parent module AND the feature are released.
     * Features that don't exist in module_features are treated as unreleased.
     */
    public function isReleased(string $featureSlug): bool
    {
        if (array_key_exists($featureSlug, $this->cache)) {
            return $this->cache[$featureSlug];
        }

        // Both module and feature must be platform_released = 1
        $released = $this->db->fetchOne(
            'SELECT mf.platform_released & m.platform_released
               FROM module_features mf
               JOIN module_submodules ms ON ms.id = mf.submodule_id
               JOIN modules m            ON m.id  = ms.module_id
              WHERE mf.slug    = :slug
                AND mf.is_active = 1
                AND m.is_active  = 1
              LIMIT 1',
            ['slug' => $featureSlug],
        );

        // Unknown or inactive feature → unreleased
        if ($released === false || $released === null) {
            return $this->cache[$featureSlug] = false;
        }

        return $this->cache[$featureSlug] = (bool) $released;
    }

    /**
     * Toggle a single feature's release status.
     * Only call from a platform owner–gated controller action.
     */
    public function toggle(int $featureId, bool $released): void
    {
        $this->db->update(
            'module_features',
            ['platform_released' => (int) $released],
            ['id' => $featureId],
        );

        $this->cache = [];
    }

    /**
     * Toggle an entire module's release status.
     * Cascades to ALL features under that module.
     */
    public function toggleModule(int $moduleId, bool $released): void
    {
        $this->db->update(
            'modules',
            ['platform_released' => (int) $released],
            ['id' => $moduleId],
        );

        // Cascade to all features under this module
        $this->db->executeStatement(
            'UPDATE module_features mf
               JOIN module_submodules ms ON ms.id = mf.submodule_id
                SET mf.platform_released = :released
              WHERE ms.module_id = :module_id',
            ['released' => (int) $released, 'module_id' => $moduleId],
        );

        $this->cache = [];
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
