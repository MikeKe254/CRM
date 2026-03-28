<?php

declare(strict_types=1);

namespace App\Controller\Terminal;

use App\Services\Auth\AuthService;
use App\Services\Auth\Exception\AuthException;
use App\Services\Terminal\TerminalBranchAccessService;
use App\Support\DomainHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/terminal', host: '{subdomain}.{domain}', requirements: [
    'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
    'domain'    => '.+',
    'branch'    => '[A-Za-z0-9-]+',
])]
class TransactionController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DomainHelper $domains,
        private readonly TerminalBranchAccessService $terminalBranchAccess,
        private readonly Connection $db,
    ) {}

    #[Route('/transactions', name: 'terminal_transactions', methods: ['GET'])]
    public function index(Request $request, string $branch): Response
    {
        $token      = $request->cookies->get('angavu_pos_token') ?: null;
        $terminal   = (string) $request->cookies->get('angavu_terminal', '');
        $subdomain  = $this->domains->getSubdomain($request);
        $baseDomain = $this->domains->getBaseDomain($request);

        $routeParams = [
            'subdomain' => $subdomain ?? 'unknown',
            'domain'    => $baseDomain,
            'branch'    => $branch,
        ];

        if ($token === null || $subdomain === null) {
            return $this->redirectToRoute('terminal_login_page', $routeParams);
        }

        try {
            $session    = $this->auth->validateSession($token);
            $branchNode = $this->terminalBranchAccess->resolveBranchNode($session->company->id, $branch);

            if ($branchNode === null
                || !$this->terminalBranchAccess->terminalMatchesBranch($session->company->id, $terminal, $branchNode->id)
                || !$this->terminalBranchAccess->userAssignedToBranch($session->user->id, $branchNode->id)
            ) {
                return $this->redirectToRoute('terminal_login_page', $routeParams);
            }
        } catch (AuthException) {
            return $this->redirectToRoute('terminal_login_page', $routeParams);
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT pt.*,
                    pm.name        AS payment_method_name,
                    pm.method_key,
                    a.name         AS area_name,
                    c.first_name   AS customer_first_name,
                    -- Best-effort M-Pesa receipt (stored > time-proximity fallback)
                    COALESCE(
                        NULLIF(pt.api_receipt, ''),
                        IF(pm.method_key = 'mpesa', (
                            SELECT mp.transaction_id
                              FROM mpesa_payments mp
                             WHERE mp.company_id  = pt.company_id
                               AND mp.amount      = pt.amount
                               AND mp.status_code = 0
                               AND mp.transaction_id IS NOT NULL
                               AND mp.transaction_id != ''
                               AND ABS(TIMESTAMPDIFF(SECOND, mp.created_at, pt.created_at)) <= 300
                             ORDER BY ABS(TIMESTAMPDIFF(SECOND, mp.created_at, pt.created_at)) ASC
                             LIMIT 1
                        ), NULL)
                    ) AS mpesa_code
               FROM pos_transactions pt
               JOIN payment_methods  pm ON pm.id = pt.payment_method_id
          LEFT JOIN areas             a  ON a.id  = pt.area_id
          LEFT JOIN customers         c  ON c.id  = pt.customer_id
              WHERE pt.cashier_user_id = :user_id
                AND pt.company_id      = :company_id
                AND pt.status IN ('complete', 'processing', 'cancelled')
              ORDER BY pt.created_at DESC
              LIMIT 50",
            ['user_id' => $session->user->id, 'company_id' => $session->company->id],
        );

        // Fetch split legs for all returned transactions in one query
        $txnIds = array_column($rows, 'id');
        $splitsByTxn = [];
        if (!empty($txnIds)) {
            $placeholders = implode(',', array_fill(0, count($txnIds), '?'));
            $splitRows = $this->db->fetchAllAssociative(
                "SELECT pos_transaction_id, method_key, amount, api_receipt
                   FROM pos_transaction_splits
                  WHERE pos_transaction_id IN ($placeholders)
                  ORDER BY pos_transaction_id, split_index ASC",
                $txnIds,
            );
            foreach ($splitRows as $s) {
                $splitsByTxn[(int) $s['pos_transaction_id']][] = $s;
            }
        }

        $txns = array_map(function (array $row) use ($splitsByTxn): array {
            $row['splits'] = $splitsByTxn[(int) $row['id']] ?? [];
            return $row;
        }, $rows);

        return $this->render('terminal/transactions.html.twig', [
            'transactions' => $txns,
            'user_name'    => $session->user->name,
            'route_params' => $routeParams,
        ]);
    }
}
