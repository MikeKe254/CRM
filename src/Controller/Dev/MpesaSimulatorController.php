<?php

declare(strict_types=1);

namespace App\Controller\Dev;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only M-Pesa payment simulator.
 * Inserts fake rows into mpesa_payments to test the callback claim flow.
 *
 * Access: /dev/mpesa-sim  (disabled in prod via env check)
 */
#[Route('/dev/mpesa-sim', name: 'dev_mpesa_sim')]
class MpesaSimulatorController extends AbstractController
{
    private const KENYAN_FIRST_NAMES = [
        'Amina','Brian','Cynthia','David','Esther','Faith','George','Hannah',
        'Isaac','Janet','Kevin','Lydia','Michael','Nancy','Oliver','Patricia',
        'Quentin','Rachel','Samuel','Tabitha','Usman','Violet','Wanjiku',
        'Xavier','Yvonne','Zawadi','Aisha','Benedict','Caroline','Dennis',
        'Eunice','Francis','Grace','Harold','Irene','James','Kathleen','Leonard',
        'Margaret','Nicholas','Olivia','Peter','Queeneth','Robert','Stella',
        'Timothy','Ursula','Victor','Wendy','Zipporah',
    ];

    private const KENYAN_LAST_NAMES = [
        'Kamau','Otieno','Njoroge','Waweru','Mwangi','Ochieng','Kariuki',
        'Mutuku','Ndegwa','Odhiambo','Kiprotich','Korir','Chebet','Rono',
        'Langat','Mutai','Abuya','Onyango','Omondi','Adhiambo','Akinyi',
        'Were','Ogola','Simiyu','Wekesa','Masinde','Barasa','Makokha',
        'Mukhwana','Nangila','Hassan','Omar','Abdi','Ali','Salim',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($this->getParameter('kernel.environment') === 'prod') {
            throw $this->createNotFoundException();
        }

        $generated = [];
        $error     = null;

        if ($request->isMethod('POST')) {
            try {
                $generated = $this->generate(
                    amount:    (float)  $request->request->get('amount', 100),
                    count:     (int)    $request->request->get('count', 1),
                    companyId: (int)    $request->request->get('company_id', 1),
                    branchId:  (int)    $request->request->get('branch_id', 30),
                    shortcode: (string) $request->request->get('shortcode', '5548218'),
                );
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        // Load recent simulated payments (last 30)
        $recent = $this->db->fetchAllAssociative(
            "SELECT id, first_name, last_name, msisdn, amount, transaction_id,
                    created_at, claimed, claimed_by_user_id
               FROM mpesa_payments
              WHERE company_id = :cid
                AND deleted_at IS NULL
              ORDER BY id DESC
              LIMIT 30",
            ['cid' => $request->request->get('company_id', 1) ?: 1],
        );

        $html = $this->renderPage($request, $recent, $generated, $error);

        return new Response($html);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function generate(float $amount, int $count, int $companyId, int $branchId, string $shortcode): array
    {
        $count   = max(1, min(10, $count));
        $created = [];

        // Spread created_at across random offsets: 1–8 minutes ago each
        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $offsets[] = random_int(60, 480); // 1–8 min ago in seconds
        }
        sort($offsets); // oldest first

        foreach ($offsets as $secAgo) {
            $firstName = self::KENYAN_FIRST_NAMES[array_rand(self::KENYAN_FIRST_NAMES)];
            $lastName  = self::KENYAN_LAST_NAMES[array_rand(self::KENYAN_LAST_NAMES)];
            $msisdn    = '2547' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $txnId     = $this->randomMpesaRef();

            // Use MySQL NOW() - INTERVAL to avoid PHP/MySQL timezone drift
            $this->db->executeStatement(
                "INSERT INTO mpesa_payments
                    (bestguess, short_code, client_id, msisdn, amount, reference,
                     invoice_number, method, reference_id, transaction_id,
                     first_name, last_name, middle_name, account, status_code,
                     retries, status_description, result_description, payment_id,
                     created_at, company_id, branch_id, claimed)
                 VALUES
                    (:bestguess, :short_code, :client_id, :msisdn, :amount, :reference,
                     :invoice_number, :method, :reference_id, :transaction_id,
                     :first_name, :last_name, :middle_name, :account, :status_code,
                     :retries, :status_description, :result_description, :payment_id,
                     NOW() - INTERVAL :secs_ago SECOND, :company_id, :branch_id, 0)",
                [
                    'bestguess'          => 'SIM',
                    'short_code'         => $shortcode,
                    'client_id'          => 0,
                    'msisdn'             => $msisdn,
                    'amount'             => $amount,
                    'reference'          => 'SIM-' . strtoupper(substr(uniqid(), -6)),
                    'invoice_number'     => null,
                    'method'             => 'SIM',
                    'reference_id'       => 0,
                    'transaction_id'     => $txnId,
                    'first_name'         => $firstName,
                    'last_name'          => $lastName,
                    'middle_name'        => null,
                    'account'            => null,
                    'status_code'        => 0,
                    'retries'            => 0,
                    'status_description' => 'The service request is processed successfully.',
                    'result_description' => 'The service request is processed successfully.',
                    'payment_id'         => 0,
                    'secs_ago'           => $secAgo,
                    'company_id'         => $companyId,
                    'branch_id'          => $branchId,
                ],
            );

            // Read back the actual created_at MySQL used
            $createdAt = $this->db->fetchOne('SELECT NOW() - INTERVAL :s SECOND', ['s' => $secAgo]);

            $created[] = [
                'name'       => "$firstName $lastName",
                'msisdn'     => $msisdn,
                'amount'     => $amount,
                'txn_id'     => $txnId,
                'created_at' => $createdAt,
            ];
        }

        return $created;
    }

    private function randomMpesaRef(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
        $ref   = '';
        for ($i = 0; $i < 10; $i++) {
            $ref .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $ref;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function renderPage(Request $req, array $recent, array $generated, ?string $error): string
    {
        $amt       = htmlspecialchars((string) ($req->request->get('amount', '') ?: ''));
        $count     = htmlspecialchars((string) ($req->request->get('count', 1)));
        $company   = htmlspecialchars((string) ($req->request->get('company_id', 1)));
        $branch    = htmlspecialchars((string) ($req->request->get('branch_id', 30)));
        $shortcode = htmlspecialchars((string) ($req->request->get('shortcode', '5548218')));

        $generatedHtml = '';
        foreach ($generated as $g) {
            $generatedHtml .= sprintf(
                '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#052e16;border:1px solid #166534;border-radius:10px;">
                    <div>
                        <div style="color:#4ade80;font-weight:700;font-size:14px;">%s</div>
                        <div style="color:#6b7280;font-size:12px;font-family:monospace;">%s · %s</div>
                    </div>
                    <div style="text-align:right;">
                        <div style="color:#fff;font-weight:700;">KES %s</div>
                        <div style="color:#374151;font-size:11px;font-family:monospace;">%s</div>
                    </div>
                </div>',
                htmlspecialchars($g['name']),
                htmlspecialchars($g['msisdn']),
                htmlspecialchars($g['created_at']),
                number_format($g['amount']),
                htmlspecialchars($g['txn_id']),
            );
        }

        $recentHtml = '';
        foreach ($recent as $r) {
            $claimedBadge = $r['claimed']
                ? '<span style="background:#1e3a5f;color:#60a5fa;font-size:10px;padding:2px 7px;border-radius:99px;font-weight:600;">CLAIMED</span>'
                : '<span style="background:#1a2e1a;color:#4ade80;font-size:10px;padding:2px 7px;border-radius:99px;font-weight:600;">UNCLAIMED</span>';

            $recentHtml .= sprintf(
                '<tr style="border-bottom:1px solid #1f2937;">
                    <td style="padding:9px 10px;color:#9ca3af;font-size:12px;font-family:monospace;">%s</td>
                    <td style="padding:9px 10px;color:#fff;font-weight:600;font-size:13px;">%s %s</td>
                    <td style="padding:9px 10px;color:#9ca3af;font-size:12px;font-family:monospace;">···%s</td>
                    <td style="padding:9px 10px;color:#4ade80;font-weight:700;font-size:13px;">%s</td>
                    <td style="padding:9px 10px;color:#6b7280;font-size:11px;font-family:monospace;">%s</td>
                    <td style="padding:9px 10px;">%s</td>
                </tr>',
                htmlspecialchars((string) $r['id']),
                htmlspecialchars((string) ($r['first_name'] ?? '')),
                htmlspecialchars((string) ($r['last_name'] ?? '')),
                htmlspecialchars(substr((string) ($r['msisdn'] ?? ''), -3)),
                'KES ' . number_format((float) $r['amount']),
                htmlspecialchars((string) ($r['created_at'] ?? '')),
                $claimedBadge,
            );
        }

        $errorHtml = $error
            ? '<div style="background:#450a0a;border:1px solid #7f1d1d;border-radius:10px;padding:12px 16px;color:#f87171;font-size:13px;margin-bottom:12px;">' . htmlspecialchars($error) . '</div>'
            : '';

        $successHtml = $generated
            ? '<div style="margin-bottom:12px;display:flex;flex-direction:column;gap:6px;">' . $generatedHtml . '</div>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>M-Pesa Simulator — Dev</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #030712; color: #e5e7eb; font-family: -apple-system, system-ui, sans-serif; min-height: 100vh; padding: 24px 16px; }
  input, select { background: #111827; border: 1px solid #374151; border-radius: 10px; color: #fff; padding: 10px 14px; font-size: 15px; width: 100%; outline: none; }
  input:focus, select:focus { border-color: #10b981; }
  label { display: block; color: #9ca3af; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 5px; }
  .btn { width: 100%; padding: 14px; border: none; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; }
  .btn-green { background: #16a34a; color: #fff; }
  .btn-green:hover { background: #15803d; }
  table { width: 100%; border-collapse: collapse; }
  th { color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; text-align: left; padding: 8px 10px; border-bottom: 1px solid #1f2937; }
</style>
</head>
<body>
<div style="max-width:680px;margin:0 auto;">

  <div style="margin-bottom:24px;">
    <div style="color:#10b981;font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px;">DEV TOOL</div>
    <h1 style="color:#fff;font-size:24px;font-weight:800;">M-Pesa Payment Simulator</h1>
    <p style="color:#6b7280;font-size:13px;margin-top:4px;">Inserts fake mpesa_payments rows to test the callback claim flow.</p>
  </div>

  {$errorHtml}
  {$successHtml}

  <form method="post" style="background:#0f172a;border:1px solid #1f2937;border-radius:16px;padding:20px;margin-bottom:24px;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
      <div>
        <label>Amount (KES)</label>
        <input type="number" name="amount" value="{$amt}" placeholder="e.g. 700" step="1" min="1" required>
      </div>
      <div>
        <label>Number of transactions</label>
        <input type="number" name="count" value="{$count}" min="1" max="10">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
      <div>
        <label>Company ID</label>
        <input type="number" name="company_id" value="{$company}" min="1">
      </div>
      <div>
        <label>Branch ID</label>
        <input type="number" name="branch_id" value="{$branch}" min="1">
      </div>
      <div>
        <label>Shortcode</label>
        <input type="text" name="shortcode" value="{$shortcode}">
      </div>
    </div>
    <button type="submit" class="btn btn-green">⚡ Generate Payments</button>
  </form>

  <div style="background:#0f172a;border:1px solid #1f2937;border-radius:16px;overflow:hidden;">
    <div style="padding:14px 16px;border-bottom:1px solid #1f2937;display:flex;align-items:center;justify-content:space-between;">
      <div style="color:#fff;font-weight:700;font-size:14px;">Recent Payments</div>
      <div style="color:#6b7280;font-size:12px;">Last 30 rows</div>
    </div>
    <div style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Name</th><th>Phone</th><th>Amount</th><th>Created</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          {$recentHtml}
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>
HTML;
    }
}
