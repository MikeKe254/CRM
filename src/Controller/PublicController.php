<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(host: '{domain}', requirements: ['domain' => '.+'])]
class PublicController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly DomainHelper $domains,
    ) {}

    #[Route('/', name: 'home')]
    public function home(Request $request): Response
    {
        $subdomain = $this->domains->getSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        // 2026-03-19: The public home page should only render on the apex/base
        // domain. If a tenant subdomain hits "/", send existing companies to
        // their login page and unknown subdomains back to the base domain.
        if ($subdomain !== null && $subdomain !== 'admin') {
            if ($this->tenantExists($subdomain)) {
                return $this->redirectToRoute('app_login', [
                    'subdomain' => $subdomain,
                    'domain' => $baseDomain,
                ]);
            }

            return $this->redirectToRoute('home', [
                'domain' => $baseDomain,
            ]);
        }

        return $this->render('public/home.html.twig');
    }

    #[Route('/about', name: 'about')]
    public function about(): Response
    {
        return $this->render('public/about.html.twig');
    }

    #[Route('/pricing', name: 'pricing')]
    public function pricing(): Response
    {
        return $this->render('public/pricing.html.twig');
    }

    #[Route('/features', name: 'features')]
    public function features(): Response
    {
        return $this->render('public/features.html.twig');
    }

    #[Route('/contact', name: 'contact')]
    public function contact(): Response
    {
        return $this->render('public/contact.html.twig');
    }

    #[Route('/help', name: 'help')]
    public function help(): Response
    {
        return $this->render('public/help.html.twig');
    }

    #[Route('/terms', name: 'terms')]
    public function terms(): Response
    {
        return $this->render('public/terms.html.twig');
    }

    #[Route('/privacy', name: 'privacy')]
    public function privacy(): Response
    {
        return $this->render('public/privacy.html.twig');
    }

    private function tenantExists(string $subdomain): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM companies WHERE id <> 0 AND subdomain = :subdomain AND deleted_at IS NULL LIMIT 1',
            ['subdomain' => $subdomain],
        );
    }
}
