<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Permission\CheckPermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/admin/terminals')]
class TerminalController extends AdminBaseController
{
    public function __construct(
        AuthService            $auth,
        CheckPermissionService $can,
        private readonly Connection $db,
    ) {
        parent::__construct($auth, $can);
    }

    // =========================================================================
    // GET /dashboard/admin/terminals — List (Twig)
    // =========================================================================

    #[Route('', name: 'admin_terminals', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $this->requireAdmin($request, 'authorize_pos_terminal');
        if ($session instanceof Response) return $session;

        $terminals = $this->db->fetchAllAssociative(
            'SELECT pt.id, pt.terminal_identifier, pt.device_name,
                    pt.ip_address, pt.authorized_at, pt.expires_at, pt.revoked_at,
                    u.name AS authorized_by
             FROM   pos_terminals pt
             JOIN   users u ON u.id = pt.authorized_by_user_id
             WHERE  pt.company_id = :company_id
             ORDER  BY pt.authorized_at DESC',
            ['company_id' => $session->company->id],
        );

        return $this->render('admin/terminals/index.html.twig', [
            'session'   => $session,
            'terminals' => $terminals,
        ]);
    }

    // =========================================================================
    // POST /dashboard/admin/terminals/{id}/revoke — Revoke terminal (fetch)
    // =========================================================================

    #[Route('/{id}/revoke', name: 'admin_terminals_revoke', methods: ['POST'])]
    public function revoke(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'authorize_pos_terminal');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $terminal = $this->db->fetchAssociative(
            'SELECT * FROM pos_terminals WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$terminal) return $this->error('Terminal not found.', 404);
        if ($terminal['revoked_at'] !== null) return $this->error('Terminal is already revoked.');

        $this->db->executeStatement(
            'UPDATE pos_terminals SET revoked_at = NOW() WHERE id = :id',
            ['id' => $id],
        );

        return $this->success("Terminal \"{$terminal['device_name']}\" has been revoked.");
    }

    // =========================================================================
    // POST /dashboard/admin/terminals/{id}/reactivate — Reactivate terminal (fetch)
    // =========================================================================

    #[Route('/{id}/reactivate', name: 'admin_terminals_reactivate', methods: ['POST'])]
    public function reactivate(int $id, Request $request): JsonResponse
    {
        $session = $this->requireAdmin($request, 'authorize_pos_terminal');
        if ($session instanceof Response) return $this->error('Unauthorized.', 403);

        $terminal = $this->db->fetchAssociative(
            'SELECT * FROM pos_terminals WHERE id = :id AND company_id = :company_id',
            ['id' => $id, 'company_id' => $session->company->id],
        );

        if (!$terminal) return $this->error('Terminal not found.', 404);

        $expiresAt = (new \DateTimeImmutable())->modify('+30 days')->format('Y-m-d H:i:s');

        $this->db->executeStatement(
            'UPDATE pos_terminals
             SET revoked_at  = NULL,
                 expires_at  = :expires_at,
                 authorized_by_user_id = :user_id,
                 authorized_at = NOW()
             WHERE id = :id',
            [
                'expires_at' => $expiresAt,
                'user_id'    => $session->user->id,
                'id'         => $id,
            ],
        );

        return $this->success("Terminal \"{$terminal['device_name']}\" has been reactivated for 30 days.");
    }
}
