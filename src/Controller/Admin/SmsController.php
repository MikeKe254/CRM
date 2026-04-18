<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Services\Auth\AuthService;
use App\Services\Branch\BranchResolverService;
use App\Services\Encryption\CredentialEncryptionService;
use App\Services\Permission\CheckPermissionService;
use App\Services\Permission\PlatformCheckPermissionService;
use App\Services\Sms\SmsProviderRegistry;
use App\Services\Sms\SmsService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{branch}/dashboard/admin/settings/sms', host: '{subdomain}.{domain}', requirements: [
    'subdomain' => '(?!admin\.)[A-Za-z0-9-]+',
    'domain'    => '.+',
    'branch'    => '[A-Za-z0-9-]+',
])]
final class SmsController extends AdminBaseController
{
    public function __construct(
        AuthService $auth,
        CheckPermissionService $can,
        PlatformCheckPermissionService $platformCan,
        BranchResolverService $branchResolver,
        Connection $db,
        private readonly SmsProviderRegistry $registry,
        private readonly CredentialEncryptionService $encryption,
        private readonly SmsService $smsService,
    ) {
        parent::__construct($auth, $can, $platformCan, $branchResolver, $db);
    }

    // =========================================================================
    // SETUP — list configs + sender IDs
    // =========================================================================

    #[Route('', name: 'admin_settings_sms', methods: ['GET'])]
    public function setup(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request);
        if ($session instanceof Response) return $session;

        $configs = $this->loadConfigs($session->company->id);

        // Attach sender IDs to each config
        foreach ($configs as &$cfg) {
            $cfg['sender_ids'] = $this->db->fetchAllAssociative(
                'SELECT * FROM sms_sender_ids
                  WHERE sms_config_id = :id AND is_active = 1
                  ORDER BY type ASC, is_default DESC, id ASC',
                ['id' => (int) $cfg['id']],
            );
        }
        unset($cfg);

        // Build provider definitions for the dynamic add/edit form
        $providerDefs = [];
        foreach ($this->registry->all() as $key => $adapter) {
            $def = $adapter->describeConfiguration();
            $providerDefs[$key] = [
                'key'          => $def->providerKey,
                'name'         => $def->displayName,
                'fields'       => array_map(fn($f) => [
                    'key'         => $f->key,
                    'label'       => $f->label,
                    'type'        => $f->type,
                    'required'    => $f->required,
                    'placeholder' => $f->placeholder,
                    'helpText'    => $f->helpText,
                ], $def->credentialFields),
                'systemOwned'  => $def->systemOwnedSenderIds,
                'strictSender' => $def->strictSenderIdEnforcement,
                'notes'        => $def->notes,
            ];
        }

        // Only show active providers in the picker
        $availableProviders = $this->db->fetchAllAssociative(
            'SELECT provider_key, display_name FROM sms_providers WHERE is_active = 1 ORDER BY sort_order ASC',
        );

        return $this->render('admin/settings/sms/setup.html.twig', [
            'session'            => $session,
            'configs'            => $configs,
            'provider_defs'      => $providerDefs,
            'available_providers' => $availableProviders,
        ]);
    }

    #[Route('/test-send', name: 'admin_settings_sms_test_send', methods: ['POST'])]
    public function testSend(Request $request): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $this->error('Unauthorised.', $session->getStatusCode());

        $recipient = trim((string) $request->request->get('recipient', ''));
        $message   = trim((string) $request->request->get('message', ''));
        $type      = strtolower(trim((string) $request->request->get('type', 'transactional')));
        $configId  = $request->request->get('config_id');

        if ($recipient === '') {
            return $this->error('Recipient is required.');
        }

        if ($message === '') {
            return $this->error('Message is required.');
        }

        if (!in_array($type, ['transactional', 'promotional'], true)) {
            return $this->error('Invalid message type.');
        }

        try {
            $outboxId = $type === 'promotional'
                ? $this->smsService->queuePromotional(
                    companyId: (int) $session->company->id,
                    recipient: $recipient,
                    message: $message,
                    configId: $configId !== null && $configId !== '' ? (int) $configId : null,
                    context: [
                        'branch_id' => $session->branch->id ?? null,
                    ],
                )
                : $this->smsService->queueTransactional(
                    companyId: (int) $session->company->id,
                    recipient: $recipient,
                    message: $message,
                    configId: $configId !== null && $configId !== '' ? (int) $configId : null,
                    context: [
                        'branch_id' => $session->branch->id ?? null,
                    ],
                );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        return $this->success(sprintf('Test SMS queued successfully. sms_outbox.id = %d', $outboxId));
    }

    // =========================================================================
    // OUTBOX
    // =========================================================================

    #[Route('/outbox', name: 'admin_settings_sms_outbox', methods: ['GET'])]
    public function outbox(Request $request): Response
    {
        $session = $this->requireSettingsAccess($request);
        if ($session instanceof Response) return $session;

        $page     = max(1, (int) $request->query->get('page', '1'));
        $perPage  = 50;
        $offset   = ($page - 1) * $perPage;
        $status   = $request->query->get('status', '');
        $provider = $request->query->get('provider', '');

        $where  = ['o.company_id = :cid'];
        $params = ['cid' => $session->company->id];

        if ($status !== '' && in_array($status, ['pending', 'queued', 'sent', 'delivered', 'failed'], true)) {
            $where[]           = 'o.status = :status';
            $params['status']  = $status;
        }
        if ($provider !== '' && $this->registry->has($provider)) {
            $where[]            = 'o.provider_key = :provider';
            $params['provider'] = $provider;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM sms_outbox o WHERE {$whereClause}",
            $params,
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT o.id, o.provider_key, o.sender_id, o.message_type, o.recipient_msisdn,
                    o.message_body, o.status, o.provider_message_id, o.failure_reason,
                    o.sent_at, o.failed_at, o.created_at,
                    c.label AS config_label
               FROM sms_outbox o
               LEFT JOIN sms_configs c ON c.id = o.sms_config_id
              WHERE {$whereClause}
              ORDER BY o.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return $this->render('admin/settings/sms/outbox.html.twig', [
            'session'   => $session,
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'pages'     => (int) ceil($total / $perPage),
            'filter_status'   => $status,
            'filter_provider' => $provider,
            'providers'       => $this->registry->all(),
        ]);
    }

    // =========================================================================
    // CRUD — CONFIGS
    // =========================================================================

    #[Route('/create', name: 'admin_settings_sms_create', methods: ['POST'])]
    public function createConfig(Request $request): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $this->error('Unauthorised.', $session->getStatusCode());

        $providerKey = trim((string) $request->request->get('provider_key', ''));
        if (!$this->registry->has($providerKey)) {
            return $this->error('Unknown SMS provider.');
        }

        $adapter     = $this->registry->get($providerKey);
        $def         = $adapter->describeConfiguration();
        $label       = trim((string) $request->request->get('label', '')) ?: null;
        $senderTx    = trim((string) $request->request->get('default_sender_id_transactional', '')) ?: null;
        $senderPromo = trim((string) $request->request->get('default_sender_id_promotional', '')) ?: null;

        // Build + validate credential map
        $rawCreds = [];
        foreach ($def->credentialFields as $field) {
            $val = trim((string) $request->request->get($field->key, ''));
            if ($field->required && $val === '') {
                return $this->error(sprintf('"%s" is required.', $field->label));
            }
            if ($val !== '') {
                $rawCreds[$field->key] = $val;
            }
        }

        $credJson  = $this->encryption->encrypt(json_encode($rawCreds, JSON_UNESCAPED_UNICODE));
        $isDefault = (int) ($this->configCount($session->company->id) === 0); // auto-default if first

        $this->db->insert('sms_configs', [
            'company_id'                      => $session->company->id,
            'provider_key'                    => $providerKey,
            'label'                           => $label,
            'credentials_json'                => $credJson,
            'credentials_encrypted'           => 1,
            'default_sender_id_transactional' => $senderTx,
            'default_sender_id_promotional'   => $senderPromo,
            'is_active'                       => 1,
            'is_default'                      => $isDefault,
            'created_at'                      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at'                      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $this->success(sprintf('%s configuration added.', $def->displayName));
    }

    #[Route('/{configId}/save', name: 'admin_settings_sms_save', methods: ['POST'])]
    public function saveConfig(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $this->error('Unauthorised.', $session->getStatusCode());

        $config = $this->ownedConfig($configId, $session->company->id);
        if ($config === null) return $this->error('Configuration not found.', 404);

        $adapter     = $this->registry->get((string) $config['provider_key']);
        $def         = $adapter->describeConfiguration();
        $label       = trim((string) $request->request->get('label', '')) ?: null;
        $senderTx    = trim((string) $request->request->get('default_sender_id_transactional', '')) ?: null;
        $senderPromo = trim((string) $request->request->get('default_sender_id_promotional', '')) ?: null;
        $isActive    = $request->request->get('is_active') === '1' ? 1 : 0;

        // Only update credentials if any were submitted
        $newCreds = [];
        foreach ($def->credentialFields as $field) {
            $val = trim((string) $request->request->get($field->key, ''));
            if ($val !== '') {
                $newCreds[$field->key] = $val;
            }
        }

        $set    = 'label = ?, default_sender_id_transactional = ?, default_sender_id_promotional = ?, is_active = ?, updated_at = NOW()';
        $params = [$label, $senderTx, $senderPromo, $isActive];

        if ($newCreds !== []) {
            // Merge with existing (decrypt, merge, re-encrypt)
            try {
                $existing = (bool) $config['credentials_encrypted']
                    ? json_decode($this->encryption->decrypt((string) $config['credentials_json']), true) ?? []
                    : json_decode((string) $config['credentials_json'], true) ?? [];
            } catch (\Throwable) {
                $existing = [];
            }
            $merged   = array_merge($existing, $newCreds);
            $set     .= ', credentials_json = ?, credentials_encrypted = 1';
            $params[] = $this->encryption->encrypt(json_encode($merged, JSON_UNESCAPED_UNICODE));
        }

        $params[] = $configId;
        $this->db->executeStatement("UPDATE sms_configs SET {$set} WHERE id = ?", $params);

        return $this->success('Configuration saved.');
    }

    #[Route('/{configId}/delete', name: 'admin_settings_sms_delete', methods: ['POST'])]
    public function deleteConfig(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $this->error('Unauthorised.', $session->getStatusCode());

        $config = $this->ownedConfig($configId, $session->company->id);
        if ($config === null) return $this->error('Configuration not found.', 404);

        $this->db->executeStatement(
            'UPDATE sms_configs SET deleted_at = NOW(), is_active = 0, is_default = 0, updated_at = NOW() WHERE id = ?',
            [$configId],
        );

        return $this->success('Configuration deleted.');
    }

    #[Route('/{configId}/set-default', name: 'admin_settings_sms_set_default', methods: ['POST'])]
    public function setDefault(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $this->error('Unauthorised.', $session->getStatusCode());

        $config = $this->ownedConfig($configId, $session->company->id);
        if ($config === null) return $this->error('Configuration not found.', 404);

        $this->db->executeStatement(
            'UPDATE sms_configs SET is_default = 0, updated_at = NOW() WHERE company_id = ? AND deleted_at IS NULL',
            [$session->company->id],
        );
        $this->db->executeStatement(
            'UPDATE sms_configs SET is_default = 1, updated_at = NOW() WHERE id = ?',
            [$configId],
        );

        return $this->success('Default provider updated.');
    }

    // =========================================================================
    // CRUD — SENDER IDs
    // =========================================================================

    #[Route('/{configId}/sender-ids/add', name: 'admin_settings_sms_sender_id_add', methods: ['POST'])]
    public function addSenderId(Request $request, int $configId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $this->error('Unauthorised.', $session->getStatusCode());

        $config = $this->ownedConfig($configId, $session->company->id);
        if ($config === null) return $this->error('Configuration not found.', 404);

        $senderId = strtoupper(trim((string) $request->request->get('sender_id', '')));
        $type     = $request->request->get('type', 'transactional');
        $isDefault = $request->request->get('is_default') === '1' ? 1 : 0;

        if ($senderId === '') return $this->error('Sender ID is required.');
        if (!in_array($type, ['transactional', 'promotional', 'both'], true)) {
            return $this->error('Invalid sender ID type.');
        }

        // If setting as default, clear existing default for this type
        if ($isDefault) {
            $this->db->executeStatement(
                "UPDATE sms_sender_ids SET is_default = 0
                  WHERE sms_config_id = ? AND type IN (?, 'both') AND is_default = 1",
                [$configId, $type],
            );
        }

        try {
            $this->db->insert('sms_sender_ids', [
                'company_id'    => $session->company->id,
                'sms_config_id' => $configId,
                'sender_id'     => $senderId,
                'type'          => $type,
                'is_default'    => $isDefault,
                'is_active'     => 1,
                'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return $this->error('This sender ID is already registered for that type.');
        }

        return $this->success('Sender ID added.');
    }

    #[Route('/{configId}/sender-ids/{senderId}/delete', name: 'admin_settings_sms_sender_id_delete', methods: ['POST'])]
    public function deleteSenderId(Request $request, int $configId, int $senderId): JsonResponse
    {
        $session = $this->requireSettingsAccess($request, true);
        if ($session instanceof Response) return $this->error('Unauthorised.', $session->getStatusCode());

        $row = $this->db->fetchOne(
            'SELECT id FROM sms_sender_ids WHERE id = ? AND sms_config_id = ? AND company_id = ? LIMIT 1',
            [$senderId, $configId, $session->company->id],
        );
        if (!$row) return $this->error('Sender ID not found.', 404);

        $this->db->executeStatement(
            'UPDATE sms_sender_ids SET is_active = 0, updated_at = NOW() WHERE id = ?',
            [$senderId],
        );

        return $this->success('Sender ID removed.');
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function requireSettingsAccess(Request $request, bool $edit = false): \App\Services\Auth\DTO\AuthResult|Response
    {
        $session = $this->requireAdmin($request, $edit ? 'edit_settings' : 'view_settings');
        if ($session instanceof Response) return $session;

        if ($session->user->isSuperAdmin && !$this->platformCan->check($session, $edit ? 'edit_settings' : 'view_settings')) {
            return $this->denyAccess($request, 'You do not have permission to manage SMS settings.', 403, $session);
        }

        return $session;
    }

    private function loadConfigs(int $companyId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, provider_key, label, default_sender_id_transactional,
                    default_sender_id_promotional, is_active, is_default, created_at
               FROM sms_configs
              WHERE company_id = :cid AND deleted_at IS NULL
              ORDER BY is_default DESC, id ASC',
            ['cid' => $companyId],
        );
    }

    private function ownedConfig(int $configId, int $companyId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM sms_configs WHERE id = ? AND company_id = ? AND deleted_at IS NULL LIMIT 1',
            [$configId, $companyId],
        );

        return $row === false ? null : $row;
    }

    private function configCount(int $companyId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM sms_configs WHERE company_id = ? AND deleted_at IS NULL',
            [$companyId],
        );
    }

    private function rp(Request $request): array
    {
        return [
            'subdomain' => (string) $request->attributes->get('subdomain', ''),
            'domain'    => (string) $request->attributes->get('domain', ''),
            'branch'    => (string) $request->attributes->get('branch', ''),
        ];
    }
}
