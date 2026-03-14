<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{

    #[Route('', name: 'admin_dashboard')]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    #[Route('/users', name: 'admin_users')]
    public function users(): Response
    {
        return $this->render('admin/users.html.twig');
    }

    #[Route('/roles', name: 'admin_roles')]
    public function roles(): Response
    {
        return $this->render('admin/roles.html.twig');
    }

    #[Route('/permissions', name: 'admin_permissions')]
    public function permissions(): Response
    {
        return $this->render('admin/permissions.html.twig');
    }

    #[Route('/settings', name: 'admin_settings')]
    public function settings(): Response
    {
        return $this->render('admin/settings.html.twig');
    }

    #[Route('/audit-logs', name: 'admin_audit_logs')]
    public function auditLogs(): Response
    {
        return $this->render('admin/audit_logs.html.twig');
    }

}