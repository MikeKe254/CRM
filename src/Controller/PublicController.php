<?php
declare(strict_types=1);

namespace App\Controller;

use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(host: "{domain}", requirements: ["domain" => ".+"])]
class PublicController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly DomainHelper $domains,
    ) {}

    #[Route("/", name: "home")]
    public function home(Request $request): Response
    {
        if ($redirect = $this->guardPublicPage($request)) {
            return $redirect;
        }

        return $this->render("public/home.html.twig");
    }

    #[Route("/about", name: "about")]
    public function about(Request $request): Response
    {
        if ($redirect = $this->guardPublicPage($request)) {
            return $redirect;
        }

        return $this->render("public/about.html.twig");
    }

    #[Route("/pricing", name: "pricing")]
    public function pricing(Request $request): Response
    {
        if ($redirect = $this->guardPublicPage($request)) {
            return $redirect;
        }

        $plans = $this->db->fetchAllAssociative(
            <<<SQL
            SELECT
                p.id,
                p.name,
                p.slug,
                p.description,
                p.monthly_price,
                p.annual_price,
                p.currency,
                p.trial_days,
                p.is_public,
                p.sort_order
            FROM plans p
            WHERE p.is_public = 1
              AND p.is_active = 1
            ORDER BY p.sort_order ASC, p.id ASC
            SQL
        );

        $featureRows = $this->db->fetchAllAssociative(
            <<<SQL
            SELECT
                pf.plan_id,
                m.name  AS module_name,
                m.slug  AS module_slug,
                ms.name AS submodule_name,
                ms.slug AS submodule_slug,
                COUNT(*) AS feature_count
            FROM plan_features pf
            JOIN plans p ON p.id = pf.plan_id
            JOIN module_features mf ON mf.id = pf.feature_id
            JOIN module_submodules ms ON ms.id = mf.submodule_id
            JOIN modules m ON m.id = ms.module_id
            WHERE p.is_public = 1
              AND p.is_active = 1
            GROUP BY pf.plan_id, m.id, m.name, m.slug, ms.id, ms.name, ms.slug
            ORDER BY p.sort_order ASC, m.sort_order ASC, ms.sort_order ASC, ms.name ASC
            SQL
        );

        $limitRows = $this->db->fetchAllAssociative(
            <<<SQL
            SELECT
                pl.plan_id,
                pl.limit_key,
                pl.limit_value
            FROM plan_limits pl
            JOIN plans p ON p.id = pl.plan_id
            WHERE p.is_public = 1
              AND p.is_active = 1
            ORDER BY p.sort_order ASC, pl.id ASC
            SQL
        );

        $comparisonSections = $this->buildFeatureComparison($plans, $featureRows);
        $limitComparison = $this->buildLimitComparison($plans, $limitRows);

        foreach ($plans as &$plan) {
            $plan["highlights"] = $this->buildPlanHighlights((int) $plan["id"], $comparisonSections);
            $plan["featured"] = $plan["slug"] === "growth";
            $plan["limit_summary"] = [
                "users" => $limitComparison["by_plan"][(int) $plan["id"]]["max_users"] ?? null,
                "branches" => $limitComparison["by_plan"][(int) $plan["id"]]["max_branches"] ?? null,
                "sms" => $limitComparison["by_plan"][(int) $plan["id"]]["sms_per_month"] ?? null,
            ];
        }
        unset($plan);

        return $this->render("public/pricing.html.twig", [
            "plans" => $plans,
            "comparison_sections" => $comparisonSections,
            "limit_rows" => $limitComparison["rows"],
        ]);
    }

    #[Route("/features", name: "features")]
    public function features(Request $request): Response
    {
        if ($redirect = $this->guardPublicPage($request)) {
            return $redirect;
        }

        return $this->render("public/features.html.twig");
    }

    #[Route("/contact", name: "contact")]
    public function contact(Request $request): Response
    {
        if ($redirect = $this->guardPublicPage($request)) {
            return $redirect;
        }

        return $this->render("public/contact.html.twig");
    }

    #[Route("/help", name: "help")]
    public function help(Request $request): Response
    {
        if ($redirect = $this->guardPublicPage($request)) {
            return $redirect;
        }

        return $this->render("public/help.html.twig");
    }

    #[Route("/terms", name: "terms")]
    public function terms(Request $request): Response
    {
        if ($redirect = $this->guardPublicPage($request)) {
            return $redirect;
        }

        return $this->render("public/terms.html.twig");
    }

    #[Route("/privacy", name: "privacy")]
    public function privacy(Request $request): Response
    {
        if ($redirect = $this->guardPublicPage($request)) {
            return $redirect;
        }

        return $this->render("public/privacy.html.twig");
    }

    private function guardPublicPage(Request $request): ?Response
    {
        $subdomain = $this->domains->getSubdomain($request);
        if ($subdomain === null || $subdomain === "admin") {
            return null;
        }

        $baseDomain = $this->domains->getBaseDomain($request);

        if ($this->tenantExists($subdomain)) {
            return $this->redirectToRoute("app_login", [
                "subdomain" => $subdomain,
                "domain" => $baseDomain,
            ]);
        }

        $route = (string) $request->attributes->get("_route", "home");
        $params = $request->attributes->get("_route_params", []);
        if (!is_array($params)) {
            $params = [];
        }

        unset($params["subdomain"]);
        $params["domain"] = $baseDomain;

        return $this->redirectToRoute($route, $params);
    }

    private function tenantExists(string $subdomain): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1 FROM companies WHERE id <> 0 AND subdomain = :subdomain AND deleted_at IS NULL LIMIT 1",
            ["subdomain" => $subdomain],
        );
    }

    private function buildFeatureComparison(array $plans, array $featureRows): array
    {
        $sections = [];

        foreach ($featureRows as $row) {
            $moduleKey = (string) $row["module_slug"];
            $submoduleKey = (string) $row["submodule_slug"];
            $planId = (int) $row["plan_id"];

            if (!isset($sections[$moduleKey])) {
                $sections[$moduleKey] = [
                    "title" => (string) $row["module_name"],
                    "rows" => [],
                ];
            }

            if (!isset($sections[$moduleKey]["rows"][$submoduleKey])) {
                $sections[$moduleKey]["rows"][$submoduleKey] = [
                    "label" => $this->formatSubmoduleLabel((string) $row["submodule_name"]),
                    "plans" => [],
                ];
            }

            $sections[$moduleKey]["rows"][$submoduleKey]["plans"][$planId] = true;
        }

        foreach ($sections as &$section) {
            $section["rows"] = array_values($section["rows"]);

            foreach ($section["rows"] as &$row) {
                foreach ($plans as $plan) {
                    $planId = (int) $plan["id"];
                    $row["plans"][$planId] = $row["plans"][$planId] ?? false;
                }
            }
            unset($row);
        }
        unset($section);

        return array_values($sections);
    }

    private function buildLimitComparison(array $plans, array $limitRows): array
    {
        $labels = [
            "max_users" => "Users",
            "max_branches" => "Branches",
            "max_products" => "Catalog items",
            "sms_per_month" => "SMS per month",
            "api_calls_per_month" => "API calls / month",
            "data_retention_days" => "Data retention",
        ];

        $byPlan = [];
        foreach ($limitRows as $row) {
            $byPlan[(int) $row["plan_id"]][(string) $row["limit_key"]] = (int) $row["limit_value"];
        }

        $rows = [];
        foreach ($labels as $key => $label) {
            $values = [];
            foreach ($plans as $plan) {
                $planId = (int) $plan["id"];
                $values[$planId] = $this->formatLimitValue($key, $byPlan[$planId][$key] ?? null);
            }

            $rows[] = [
                "label" => $label,
                "values" => $values,
            ];
        }

        return [
            "rows" => $rows,
            "by_plan" => $byPlan,
        ];
    }

    private function buildPlanHighlights(int $planId, array $sections): array
    {
        $highlights = [];

        foreach ($sections as $section) {
            foreach ($section["rows"] as $row) {
                if (($row["plans"][$planId] ?? false) === true) {
                    $highlights[] = $row["label"];
                }
            }
        }

        return array_slice($highlights, 0, 6);
    }

    private function formatSubmoduleLabel(string $label): string
    {
        return match (strtolower(trim($label))) {
            "profiles" => "Customer profiles",
            "activity" => "Customer activity",
            "segmentation" => "Customer segmentation",
            "records" => "Transaction records",
            "orders" => "Orders",
            "processing" => "Payment processing",
            "refunds" => "Refunds",
            "users" => "Team access",
            "permissions" => "Permissions",
            "branches" => "Multi-branch operations",
            "revenue" => "Revenue analytics",
            "products" => "Products",
            "categories" => "Categories",
            "availability" => "Availability control",
            "campaigns" => "Campaigns",
            "promotions" => "Promotions",
            "points" => "Points and rewards",
            "notifications" => "Notifications",
            "automation" => "Automation",
            "stock" => "Stock tracking",
            "alerts" => "Inventory alerts",
            "api" => "API access",
            "webhooks" => "Webhooks",
            default => $label,
        };
    }

    private function formatLimitValue(string $key, ?int $value): string
    {
        if ($value === null) {
            return "—";
        }

        if ($value === -1) {
            return "Unlimited";
        }

        return match ($key) {
            "data_retention_days" => $value . " days",
            default => number_format($value),
        };
    }
}
