<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final class DomainHelper
{
    /**
     * 2026-03-19: These configured root domains are the source of truth for
     * distinguishing apex/public hosts from tenant subdomains. This avoids
     * misreading hosts like everify.co.ke as subdomain=everify, domain=co.ke.
     *
     * @param list<string> $rootDomains
     */
    public function __construct(
        #[Autowire('%env(csv:APP_ROOT_DOMAINS)%')]
        private readonly array $rootDomains = [],
    ) {}

    public function getBaseDomainFromHost(string $host): string
    {
        $host = strtolower(trim($host));

        if ($matched = $this->matchConfiguredRootDomain($host)) {
            return $matched;
        }

        $parts = explode('.', $host);

        if (count($parts) >= 3) {
            return implode('.', array_slice($parts, 1));
        }

        return $host;
    }

    public function getBaseDomain(Request $request): string
    {
        $host = strtolower($request->getHost());

        // 2026-03-19: Prefer configured root domains over route attributes, because
        // generic host placeholders can incorrectly split apex domains like everify.co.ke.
        if ($matched = $this->matchConfiguredRootDomain($host)) {
            return $matched;
        }

        $domain = $request->attributes->get('domain');
        if (is_string($domain) && $domain !== '') {
            return $domain;
        }

        return $this->getBaseDomainFromHost($host);
    }

    public function getSubdomain(Request $request): ?string
    {
        $host = strtolower($request->getHost());

        // 2026-03-19: If the current host is a configured apex domain, it has no
        // tenant subdomain even if a generic route placeholder captured one.
        if ($matched = $this->matchConfiguredRootDomain($host)) {
            if ($host === $matched) {
                return null;
            }

            $suffix = '.' . $matched;

            if (str_ends_with($host, $suffix)) {
                $left = substr($host, 0, -strlen($suffix));

                return $left !== '' ? $left : null;
            }
        }

        $subdomain = $request->attributes->get('subdomain');
        if (is_string($subdomain) && $subdomain !== '') {
            return $subdomain;
        }

        $parts = explode('.', $host);

        if (count($parts) < 3) {
            return null;
        }

        return $parts[0];
    }

    public function isAdminHost(Request $request): bool
    {
        return $request->attributes->get('subdomain') === 'admin'
            || $this->getSubdomain($request) === 'admin';
    }

    private function matchConfiguredRootDomain(string $host): ?string
    {
        $normalized = strtolower(trim($host));
        $candidates = array_values(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            $this->rootDomains,
        )));

        usort(
            $candidates,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
        );

        foreach ($candidates as $domain) {
            if ($normalized === $domain || str_ends_with($normalized, '.' . $domain)) {
                return $domain;
            }
        }

        return null;
    }
}
