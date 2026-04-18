<?php

declare(strict_types=1);

namespace App\Services\Sms\Provider;

use App\Services\Sms\Contract\SmsCredentialField;
use App\Services\Sms\Contract\SmsCredentials;
use App\Services\Sms\Contract\SmsOutboundRequest;
use App\Services\Sms\Contract\SmsProviderConfigurationDefinition;
use App\Services\Sms\Contract\SmsProviderInterface;
use App\Services\Sms\Contract\SmsProviderResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HostPinnacle SMS adapter.
 *
 * API base: https://smsportal.hostpinnacle.co.ke/SMSApi
 * Auth:     userid + password in POST form-data body; apikey in request header
 * Docs:     Postman collection — smsportal.hostpinnacle.co.ke
 *
 * Credentials stored in sms_configs.credentials_json:
 *   { "userid": "...", "password": "...", "api_key": "..." }
 */
final class HostPinnacleProvider implements SmsProviderInterface
{
    private const BASE_URL = 'https://smsportal.hostpinnacle.co.ke/SMSApi';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getProviderKey(): string
    {
        return 'hostpinnacle';
    }

    public function describeConfiguration(): SmsProviderConfigurationDefinition
    {
        return new SmsProviderConfigurationDefinition(
            providerKey: 'hostpinnacle',
            displayName: 'HostPinnacle',
            credentialFields: [
                new SmsCredentialField(
                    key: 'userid',
                    label: 'Username',
                    type: 'text',
                    required: true,
                    placeholder: 'Your HostPinnacle portal username',
                ),
                new SmsCredentialField(
                    key: 'password',
                    label: 'Password',
                    type: 'password',
                    required: true,
                    placeholder: 'Your HostPinnacle portal password',
                ),
                new SmsCredentialField(
                    key: 'api_key',
                    label: 'API Key',
                    type: 'password',
                    required: true,
                    placeholder: 'API key from your HostPinnacle portal',
                    helpText: 'Sent as the apikey request header on every POST request.',
                ),
            ],
            strictSenderIdEnforcement: false,
            systemOwnedSenderIds: false,
            supportsBalance: false,
            notes: 'Username, password, and API key are all available from your HostPinnacle portal at smsportal.hostpinnacle.co.ke.',
        );
    }

    public function send(SmsOutboundRequest $request, SmsCredentials $credentials): SmsProviderResult
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . '/send', [
                'headers' => [
                    'apikey'       => $credentials->require('api_key'),
                    'content-type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'userid'         => $credentials->require('userid'),
                    'password'       => $credentials->require('password'),
                    'mobile'         => $request->recipient,
                    'senderid'       => $request->senderId,
                    'msg'            => $request->message,
                    'sendMethod'     => 'quick',
                    'msgType'        => 'text',
                    'output'         => 'json',
                    'duplicatecheck' => 'true',
                ],
                'timeout'      => 15,
                'max_duration' => 20,
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            // Network / timeout — let Messenger retry.
            throw $e;
        }

        // Response shape (success):
        //   {"status":"success","message":"1 Messages Submitted Successfully","data":[{"number":"...","id":"..."}]}
        // Response shape (failure):
        //   {"status":"failed","message":"Invalid credentials"} or similar
        $status = strtolower((string) ($data['status'] ?? ''));

        if ($status === 'success') {
            $messageId = (string) ($data['data'][0]['id'] ?? $data['messageid'] ?? '');

            return SmsProviderResult::success($messageId, $data);
        }

        return SmsProviderResult::failure(
            (string) ($data['message'] ?? $data['error'] ?? 'Unknown HostPinnacle error'),
            $data,
        );
    }

    public function supportsBalance(): bool
    {
        return false;
    }

    public function getBalance(SmsCredentials $credentials): ?float
    {
        return null;
    }
}
