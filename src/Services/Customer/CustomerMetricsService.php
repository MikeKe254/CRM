<?php

declare(strict_types=1);

namespace App\Services\Customer;

use Doctrine\DBAL\Connection;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║               Angavu Customer Metrics Service                   ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Loads metric definitions from the customer_metric_definitions  ║
 * ║  table and builds structured sections from a raw customer row.  ║
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
    /** @var array<string, array<string, array{label: string, definition: string}>>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly Connection $db,
    ) {}

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

        foreach ($this->load() as $sectionName => $metrics) {
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

        foreach ($this->load() as $metrics) {
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
        return array_keys($this->load());
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Lazy-load metric definitions from the DB (once per request).
     *
     * @return array<string, array<string, array{label: string, definition: string}>>
     */
    private function load(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT section_name, metric_key, label, definition
             FROM   customer_metric_definitions
             WHERE  is_active = 1
             ORDER BY section_sort ASC, metric_sort ASC',
        );

        $this->definitions = [];

        foreach ($rows as $row) {
            $section = $row['section_name'];

            if (!isset($this->definitions[$section])) {
                $this->definitions[$section] = [];
            }

            $this->definitions[$section][$row['metric_key']] = [
                'label'      => $row['label'],
                'definition' => $row['definition'],
            ];
        }

        return $this->definitions;
    }
}
