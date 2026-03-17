<?php

declare(strict_types=1);

namespace App\Services\Customer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║               Angavu Customer Metrics Service                   ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Loads metric definitions from src/Config/customer_metrics.php  ║
 * ║  and builds structured sections from a raw customer DB row.     ║
 * ║                                                                  ║
 * ║  The output is identical to what the legacy customer_profile.php ║
 * ║  produced — so the existing Twig template works unchanged.       ║
 * ║                                                                  ║
 * ║  Inject into any controller:                                     ║
 * ║    public function __construct(                                  ║
 * ║        private readonly CustomerMetricsService $metrics          ║
 * ║    ) {}                                                          ║
 * ║                                                                  ║
 * ║  Usage:                                                          ║
 * ║    $sections = $this->metrics->buildSections($customerRow);      ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
final class CustomerMetricsService
{
    /** @var array<string, array<string, array{label: string, definition: string}>> */
    private array $definitions;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        $this->definitions = require $this->projectDir . '/src/Config/customer_metrics.php';
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Build sections from a raw customer_profiles DB row.
     * Output matches exactly what the legacy customer_profile.php produced,
     * so templates/Legacy/customer_profile.twig works without any changes.
     *
     * @param  array $customer  Raw row from customer_profiles table
     * @return array            Array of sections, each with title + fields
     */
    public function buildSections(array $customer): array
    {
        $sections = [];

        foreach ($this->definitions as $sectionName => $metrics) {
            $fields = [];

            foreach ($metrics as $key => $meta) {
                $fields[] = [
                    'key'        => $key,
                    'label'      => $meta['label'],
                    'value'      => $customer[$key] ?? '',
                    'definition' => $meta['definition'],
                ];
            }

            $sections[] = [
                'title'  => $sectionName,
                'fields' => $fields,
            ];
        }

        return $sections;
    }

    /**
     * Get a flat key => ['label', 'definition'] map.
     * Useful for looking up a single metric definition by key.
     */
    public function getDefinitions(): array
    {
        $flat = [];

        foreach ($this->definitions as $metrics) {
            foreach ($metrics as $key => $meta) {
                $flat[$key] = $meta;
            }
        }

        return $flat;
    }

    /**
     * Get just the section names.
     */
    public function getSectionNames(): array
    {
        return array_keys($this->definitions);
    }
}
