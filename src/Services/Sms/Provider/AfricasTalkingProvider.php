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
 * Africa's Talking SMS adapter.
 *
 * API base:    https://api.africastalking.com/version1/messaging
 * Sandbox:     https://api.sandbox.africastalking.com/version1/messaging
 * Auth:        apiKey header (required) + username in POST body
 * Status code: 101 = delivered/queued, anything else = failure
 *
 * Credentials stored in sms_configs.credentials_json:
 *   { "username": "...", "api_key": "...", "sandbox": "0" }
 *
 * Set "sandbox" to "1" to route through the AT sandbox environment.
 */
final class AfricasTalkingProvider implements SmsProviderInterface
{
    private const LIVE_URL    = 'https://api.africastalking.com/version1/messaging';
    private const SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getProviderKey(): string
    {
        return 'africastalking';
    }

    public function describeConfiguration(): SmsProviderConfigurationDefinition
    {
        return new SmsProviderConfigurationDefinition(
            providerKey: 'africastalking',
            displayName: "Africa's Talking",
            credentialFields: [
                new SmsCredentialField(
                    key: 'username',
                    label: 'Username',
                    type: 'text',
                    required: true,
                    placeholder: 'Your Africa\'s Talking username',
                    helpText: 'Found in your AT dashboard under Settings → API.',
                ),
                new SmsCredentialField(
                    key: 'api_key',
                    label: 'API Key',
                    type: 'password',
                    required: true,
                    placeholder: 'AT API key',
                    helpText: 'Generate or copy from your AT dashboard under Settings → API.',
                ),
                new SmsCredentialField(
                    key: 'sandbox',
                    label: 'Sandbox mode',
                    type: 'select',
                    required: false,
                    placeholder: '',
                    helpText: 'Enable to route through the AT sandbox. Use "sandbox" as username in sandbox mode.',
                ),
            ],
            strictSenderIdEnforcement: false,
            systemOwnedSenderIds: false,
            supportsBalance: false,
            notes: "Sender IDs must be registered and approved in your Africa's Talking account before use.",
        );
    }

    public function send(SmsOutboundRequest $request, SmsCredentials $credentials): SmsProviderResult
    {
        $sandbox = $credentials->get('sandbox') === '1';
        $url     = $sandbox ? self::SANDBOX_URL : self::LIVE_URL;

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'apiKey' => $credentials->require('api_key'),
                    'Accept' => 'application/json',
                ],
                'body' => http_build_query([
                    'username' => $credentials->require('username'),
                    'to'       => $request->recipient,
                    'message'  => $request->message,
                    'from'     => $request->senderId,
                ]),
                'timeout'      => 15,
                'max_duration' => 20,
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            // Network / timeout — let Messenger retry.
            throw $e;
        }

        // Response shape:
        // {
        //   "SMSMessageData": {
        //     "Message": "Sent to 1/1 Total Cost: KES 0.8000",
        //     "Recipients": [{
        //       "statusCode": 101,
        //       "number": "+254711XXXYYY",
        //       "status": "Success",
        //       "cost": "KES 0.8000",
        //       "messageId": "ATXid_xxx"
        //     }]
        //   }
        // }
        //
        // statusCode 101 = Success, 401 = RiskHold, 402 = InvalidSenderId,
        // 403 = InvalidPhoneNumber, 404 = UnsupportedNumberType, 405 = InsufficientCredit,
        // 406 = UserInBlacklist, 407 = CouldNotRoute, 500 = InternalServerError
        $recipients = $data['SMSMessageData']['Recipients'] ?? [];
        $first      = is_array($recipients) && isset($recipients[0]) ? $recipients[0] : [];
        $statusCode = (int) ($first['statusCode'] ?? 0);

        if ($statusCode === 101) {
            return SmsProviderResult::success(
                (string) ($first['messageId'] ?? ''),
                $data,
            );
        }

        $errorMessage = (string) ($first['status'] ?? $data['SMSMessageData']['Message'] ?? 'Unknown Africa\'s Talking error');

        return SmsProviderResult::failure($errorMessage, $data);
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
