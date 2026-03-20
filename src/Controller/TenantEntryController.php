<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TenantEntryController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    #[Route('/', name: 'tenant_entry', host: '{subdomain}.{domain}', requirements: ['subdomain' => '(?!admin$)[A-Za-z0-9-]+', 'domain' => '.+'], methods: ['GET'])]
    public function index(string $subdomain, string $domain): Response
    {
        $exists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM companies WHERE id <> 0 AND subdomain = :subdomain LIMIT 1',
            ['subdomain' => $subdomain],
        );

        if ($exists) {
            return $this->redirectToRoute('app_dashboard', [
                'subdomain' => $subdomain,
                'domain' => $domain,
            ]);
        }

        return $this->redirectToRoute('home', [
            'domain' => $domain,
        ]);
    }
}
