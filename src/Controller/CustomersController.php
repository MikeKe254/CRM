<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Admin\AdminBaseController;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/{branch}/dashboard/customers',
    host: '{subdomain}.{domain}',
    requirements: ['subdomain' => '(?!admin\.)[A-Za-z0-9-]+', 'domain' => '.+', 'branch' => '[A-Za-z0-9-]+'],
)]
final class CustomersController extends AdminBaseController
{
    public function __construct(
        AuthService                    $auth,
        CheckPermissionService         $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService          $branchResolver,
        Connection                     $db,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    #[Route('', name: 'app_customers', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request);
        if ($session instanceof Response) return $session;

        return $this->render('customers/index.html.twig', [
            'session' => $session,
        ]);
    }

    #[Route('/{id}', name: 'app_customer_profile', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function profile(Request $request, int $id): Response
    {
        $session = $this->requireAdmin($request);
        if ($session instanceof Response) return $session;

        return $this->render('customers/profile.html.twig', [
            'session' => $session,
        ]);
    }
}
