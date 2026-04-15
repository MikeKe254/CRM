<?php

declare(strict_types=1);

namespace App\Controller\Dev;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only M-Pesa payment simulator.
 *
 * Access:
 * - /dev/mpesa-sim            legacy fake-row generator
 * - /dev/mpesa-webhook-sim    webhook-style JSON dispatcher
 */
#[Route('/dev')]
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

    private const SAMPLE_PHONES = [
        '254796763792',
        '254700123456',
        '254711222333',
        '254722444555',
        '254733666777',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/mpesa-sim', name: 'dev_mpesa_sim', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($this->getParameter('kernel.environment') === 'prod') {
            throw $this->createNotFoundException();
        }

        $generated = [];
        $error = null;

        if ($request->isMethod('POST')) {
            try {
                $generated = $this->generate(
                    amount: (float) $request->request->get('amount', 100),
                    count: (int) $request->request->get('count', 1),
                    companyId: (int) $request->request->get('company_id', 1),
                    branchId: (int) $request->request->get('branch_id', 30),
                    shortcode: (string) $request->request->get('shortcode', '5548218'),
                );
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->renderSimulator($request, $generated, null, $error, false);
    }

    #[Route('/mpesa-webhook-sim', name: 'dev_mpesa_webhook_sim', methods: ['GET', 'POST'])]
    public function webhookSimulator(Request $request): Response
    {
        if ($this->getParameter('kernel.environment') === 'prod') {
            throw $this->createNotFoundException();
        }

        $generated = [];
        $error = null;
        $dispatchResult = null;

        if ($request->isMethod('POST')) {
            try {
                $action = (string) $request->request->get('action', 'dispatch');

                if ($action === 'generate') {
                    $generated = $this->generate(
                        amount: (float) $request->request->get('amount', 100),
                        count: (int) $request->request->get('count', 1),
                        companyId: (int) $request->request->get('company_id', 1),
                        branchId: (int) $request->request->get('branch_id', 30),
                        shortcode: (string) $request->request->get('shortcode', '5548218'),
                    );
                } else {
                    $dispatchResult = $this->dispatchSimulation($request);
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->renderSimulator($request, $generated, $dispatchResult, $error, true);
    }

    private function renderSimulator(Request $request, array $generated, ?array $dispatchResult, ?string $error, bool $showDispatchTools): Response
    {
        $companyId = max(1, (int) ($request->request->get('company_id', 1) ?: 1));

        $recent = $this->db->fetchAllAssociative(
            "SELECT id, first_name, last_name, msisdn, amount, transaction_id,
                    created_at, claimed, claimed_by_user_id
               FROM mpesa_payments
              WHERE company_id = :cid
                AND deleted_at IS NULL
              ORDER BY id DESC
              LIMIT 30",
            ['cid' => $companyId],
        );

        $configs = $this->loadMpesaConfigs();

        return $this->render('dev/mpesa_simulator/index.html.twig', [
            'generated' => $generated,
            'recent' => $recent,
            'error' => $error,
            'dispatch_result' => $dispatchResult,
            'show_dispatch_tools' => $showDispatchTools,
            'configs' => $configs,
            'sample_phones' => self::SAMPLE_PHONES,
            'form' => [
                'amount' => (string) ($request->request->get('amount', '') ?: ''),
                'count' => (string) ($request->request->get('count', 1) ?: 1),
                'company_id' => (string) ($request->request->get('company_id', 1) ?: 1),
                'branch_id' => (string) ($request->request->get('branch_id', 30) ?: 30),
                'shortcode' => (string) ($request->request->get('shortcode', '5548218') ?: '5548218'),
                'config_id' => (int) ($request->request->get('config_id', 0) ?: 0),
                'payload_type' => (string) ($request->request->get('payload_type', 'c2b') ?: 'c2b'),
                'msisdn' => (string) ($request->request->get('msisdn', self::SAMPLE_PHONES[0]) ?: self::SAMPLE_PHONES[0]),
                'dispatch_amount' => (string) ($request->request->get('dispatch_amount', 100) ?: 100),
                'bill_ref' => (string) ($request->request->get('bill_ref', 'SIM-ORDER-001') ?: 'SIM-ORDER-001'),
                'receipt' => (string) ($request->request->get('receipt', '') ?: ''),
                'checkout_request_id' => (string) ($request->request->get('checkout_request_id', '') ?: ''),
            ],
        ]);
    }

    private function loadMpesaConfigs(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id,
                    company_id,
                    branch_id,
                    shortcode,
                    till_number,
                    integration_mode,
                    callback_url,
                    confirmation_url,
                    forward_urls,
                    is_active,
                    integration_enabled
               FROM mpesa_configs
              WHERE deleted_at IS NULL
              ORDER BY company_id ASC, branch_id ASC, shortcode ASC, id ASC'
        );

        return array_map(function (array $row): array {
            $forwardUrls = json_decode((string) ($row['forward_urls'] ?? '[]'), true);
            if (!is_array($forwardUrls)) {
                $forwardUrls = [];
            }

            $targets = array_values(array_filter(array_unique(array_map(
                static fn ($url) => is_string($url) ? trim($url) : '',
                $forwardUrls
            ))));

            return [
                'id' => (int) $row['id'],
                'company_id' => (int) $row['company_id'],
                'branch_id' => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
                'shortcode' => (string) ($row['shortcode'] ?? ''),
                'till_number' => (string) ($row['till_number'] ?? ''),
                'integration_mode' => (string) ($row['integration_mode'] ?? 'manual'),
                'callback_url' => trim((string) ($row['callback_url'] ?? '')),
                'confirmation_url' => trim((string) ($row['confirmation_url'] ?? '')),
                'forward_urls' => $targets,
                'is_active' => (int) ($row['is_active'] ?? 0) === 1,
                'integration_enabled' => (int) ($row['integration_enabled'] ?? 0) === 1,
            ];
        }, $rows);
    }

    private function dispatchSimulation(Request $request): array
    {
        $configId = (int) $request->request->get('config_id', 0);
        if ($configId <= 0) {
            throw new \RuntimeException('Select an M-Pesa config first.');
        }

        $config = $this->db->fetchAssociative(
            'SELECT id,
                    company_id,
                    branch_id,
                    shortcode,
                    till_number,
                    integration_mode,
                    callback_url,
                    confirmation_url,
                    forward_urls
               FROM mpesa_configs
              WHERE id = :id
                AND deleted_at IS NULL
              LIMIT 1',
            ['id' => $configId],
        );

        if ($config === false) {
            throw new \RuntimeException('Selected M-Pesa config was not found.');
        }

        $payloadType = strtolower(trim((string) $request->request->get('payload_type', 'c2b')));
        if (!in_array($payloadType, ['c2b', 'stk'], true)) {
            throw new \RuntimeException('Unsupported payload type.');
        }

        $msisdn = $this->normalizePhone((string) $request->request->get('msisdn', self::SAMPLE_PHONES[0]));
        if ($msisdn === null) {
            throw new \RuntimeException('Enter a valid Kenyan phone in 2547XXXXXXXX format.');
        }

        $amount = (float) $request->request->get('dispatch_amount', 100);
        if ($amount <= 0) {
            throw new \RuntimeException('Dispatch amount must be greater than zero.');
        }

        $billRef = trim((string) $request->request->get('bill_ref', 'SIM-ORDER-001'));
        $receipt = trim((string) $request->request->get('receipt', ''));
        if ($receipt === '') {
            $receipt = $this->randomMpesaRef();
        }

        $firstName = self::KENYAN_FIRST_NAMES[array_rand(self::KENYAN_FIRST_NAMES)];
        $lastName = self::KENYAN_LAST_NAMES[array_rand(self::KENYAN_LAST_NAMES)];
        $transTime = (new DateTimeImmutable())->format('YmdHis');
        $hashedMsisdn = hash('sha256', $msisdn);

        $payload = $payloadType === 'stk'
            ? $this->buildStkPayload(
                checkoutRequestId: trim((string) $request->request->get('checkout_request_id', '')) ?: 'ws_CO_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8),
                amount: $amount,
                receipt: $receipt,
                msisdn: $msisdn,
                transTime: $transTime,
            )
            : $this->buildC2bPayload(
                shortcode: (string) $config['shortcode'],
                amount: $amount,
                receipt: $receipt,
                billRef: $billRef !== '' ? $billRef : 'SIM-ORDER-001',
                hashedMsisdn: $hashedMsisdn,
                firstName: $firstName,
                lastName: $lastName,
                transTime: $transTime,
            );

        $targets = $this->resolveTargets($config, $payloadType);
        if ($targets === []) {
            throw new \RuntimeException('The selected shortcode has no target URLs for this payload type.');
        }

        return [
            'config' => [
                'id' => (int) $config['id'],
                'company_id' => (int) $config['company_id'],
                'branch_id' => $config['branch_id'] !== null ? (int) $config['branch_id'] : null,
                'shortcode' => (string) $config['shortcode'],
                'integration_mode' => (string) ($config['integration_mode'] ?? 'manual'),
            ],
            'payload_type' => $payloadType,
            'msisdn' => $msisdn,
            'hashed_msisdn' => $hashedMsisdn,
            'receipt' => $receipt,
            'bill_ref' => $billRef,
            'targets' => $targets,
            'payload_json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'results' => $this->dispatchJson($payload, $targets),
        ];
    }

    private function resolveTargets(array $config, string $payloadType): array
    {
        $targets = [];

        if ($payloadType === 'stk') {
            $callbackUrl = trim((string) ($config['callback_url'] ?? ''));
            if ($callbackUrl !== '') {
                $targets[] = $callbackUrl;
            }
        } else {
            $confirmationUrl = trim((string) ($config['confirmation_url'] ?? ''));
            if ($confirmationUrl !== '') {
                $targets[] = $confirmationUrl;
            }

            $forwardUrls = json_decode((string) ($config['forward_urls'] ?? '[]'), true);
            if (is_array($forwardUrls)) {
                foreach ($forwardUrls as $url) {
                    if (is_string($url) && trim($url) !== '') {
                        $targets[] = trim($url);
                    }
                }
            }
        }

        return array_values(array_unique($targets));
    }

    private function buildC2bPayload(
        string $shortcode,
        float $amount,
        string $receipt,
        string $billRef,
        string $hashedMsisdn,
        string $firstName,
        string $lastName,
        string $transTime,
    ): array {
        return [
            'TransactionType' => 'Pay Bill',
            'TransID' => $receipt,
            'TransTime' => $transTime,
            'TransAmount' => number_format($amount, 2, '.', ''),
            'BusinessShortCode' => $shortcode,
            'BillRefNumber' => $billRef,
            'InvoiceNumber' => '',
            'OrgAccountBalance' => '',
            'ThirdPartyTransID' => '',
            'MSISDN' => $hashedMsisdn,
            'FirstName' => $firstName,
            'MiddleName' => '',
            'LastName' => $lastName,
        ];
    }

    private function buildStkPayload(
        string $checkoutRequestId,
        float $amount,
        string $receipt,
        string $msisdn,
        string $transTime,
    ): array {
        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'SIM-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10)),
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => (float) number_format($amount, 2, '.', '')],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                            ['Name' => 'Balance'],
                            ['Name' => 'TransactionDate', 'Value' => $transTime],
                            ['Name' => 'PhoneNumber', 'Value' => $msisdn],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function dispatchJson(array $payload, array $targets): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode payload to JSON.');
        }

        $results = [];
        foreach ($targets as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $results[] = [
                'url' => $url,
                'ok' => $errno === 0 && $status >= 200 && $status < 300,
                'status' => $status,
                'error' => $errno !== 0 ? $error : null,
                'body' => is_string($body) ? $body : null,
            ];
        }

        return $results;
    }

    private function generate(float $amount, int $count, int $companyId, int $branchId, string $shortcode): array
    {
        $count = max(1, min(10, $count));
        $created = [];

        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $offsets[] = random_int(60, 480);
        }
        sort($offsets);

        foreach ($offsets as $secAgo) {
            $firstName = self::KENYAN_FIRST_NAMES[array_rand(self::KENYAN_FIRST_NAMES)];
            $lastName = self::KENYAN_LAST_NAMES[array_rand(self::KENYAN_LAST_NAMES)];
            $msisdn = '2547' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $txnId = $this->randomMpesaRef();

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
                    'bestguess' => 'SIM',
                    'short_code' => $shortcode,
                    'client_id' => 0,
                    'msisdn' => $msisdn,
                    'amount' => $amount,
                    'reference' => 'SIM-' . strtoupper(substr(uniqid('', true), -6)),
                    'invoice_number' => null,
                    'method' => 'SIM',
                    'reference_id' => 0,
                    'transaction_id' => $txnId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'middle_name' => null,
                    'account' => null,
                    'status_code' => 0,
                    'retries' => 0,
                    'status_description' => 'The service request is processed successfully.',
                    'result_description' => 'The service request is processed successfully.',
                    'payment_id' => 0,
                    'secs_ago' => $secAgo,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                ],
            );

            $createdAt = $this->db->fetchOne('SELECT NOW() - INTERVAL :s SECOND', ['s' => $secAgo]);

            $created[] = [
                'name' => "$firstName $lastName",
                'msisdn' => $msisdn,
                'amount' => $amount,
                'txn_id' => $txnId,
                'created_at' => $createdAt,
            ];
        }

        return $created;
    }

    private function randomMpesaRef(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
        $ref = '';
        for ($i = 0; $i < 10; $i++) {
            $ref .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $ref;
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '254' . substr($digits, 1);
        }
        if (strlen($digits) === 9 && ($digits[0] === '7' || $digits[0] === '1')) {
            $digits = '254' . $digits;
        }
        if (strlen($digits) !== 12 || !str_starts_with($digits, '254')) {
            return null;
        }

        return $digits;
    }
}
