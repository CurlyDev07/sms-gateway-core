<?php

namespace App\Services;

use App\Models\InboundMessage;
use App\Models\CompanyChatAppIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboundRelayService
{
    /**
     * Relay inbound message to Chat App webhook.
     *
     * @param \App\Models\InboundMessage $message
     * @return bool
     */
    public function relay(InboundMessage $message): bool
    {
        $message->loadMissing('sim', 'company.chatAppIntegration');

        $timeout = (int) config('services.chat_app.timeout', 10);
        $settings = $this->relaySettings($message);
        $url = $settings['url'];
        $tenantKey = $settings['tenant_key'];
        $inboundSecret = $settings['inbound_secret'];

        if ($url === '') {
            $message->update([
                'relay_status' => 'failed',
                'relay_error' => 'Chat App inbound URL is not configured',
            ]);

            Log::warning('Inbound relay failure: missing chat app inbound URL', [
                'inbound_message_id' => $message->id,
            ]);

            return false;
        }

        $payload = [
            // InfoTxt-compatible contract expected by ChatApp.
            'ID' => 'GW-IN-'.$message->uuid,
            'MOBILE' => $this->normalizeMobile((string) $message->customer_phone),
            'SMS' => (string) $message->message,
            'RECEIVED' => $message->received_at !== null ? $message->received_at->format('Y-m-d H:i:s') : null,
        ];

        if ($tenantKey !== '') {
            $payload['TENANT_KEY'] = $tenantKey;
        }

        $payload = array_filter($payload, static fn ($value) => $value !== null);
        $rawFormBody = http_build_query($payload, '', '&');
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        if ($inboundSecret !== '') {
            $timestamp = (string) time();
            $headers['X-Gateway-Timestamp'] = $timestamp;
            $headers['X-Gateway-Key-Id'] = $tenantKey !== '' ? $tenantKey : 'env';
            $headers['X-Gateway-Signature'] = hash_hmac(
                'sha256',
                $timestamp.'.'.$rawFormBody,
                $inboundSecret
            );
        }

        try {
            $response = Http::withHeaders($headers)
                ->withBody($rawFormBody, 'application/x-www-form-urlencoded')
                ->timeout($timeout)
                ->post($url);

            if ($response->successful() && $this->isAcknowledged($response->json())) {
                $message->update([
                    'relayed_to_chat_app' => true,
                    'relay_status' => 'success',
                    'relayed_at' => now(),
                    'relay_error' => null,
                ]);

                Log::info('Inbound relay success', [
                    'inbound_message_id' => $message->id,
                    'status_code' => $response->status(),
                ]);

                return true;
            }

            $error = sprintf('HTTP %d: %s', $response->status(), substr((string) $response->body(), 0, 300));

            $message->update([
                'relay_status' => 'failed',
                'relay_error' => $error,
            ]);

            Log::warning('Inbound relay failure', [
                'inbound_message_id' => $message->id,
                'status_code' => $response->status(),
                'error' => $error,
            ]);

            return false;
        } catch (Throwable $e) {
            $message->update([
                'relay_status' => 'failed',
                'relay_error' => $e->getMessage(),
            ]);

            Log::error('Inbound relay exception', [
                'inbound_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Resolve per-company ChatApp relay settings with env fallback for dev/bootstrap.
     *
     * @param \App\Models\InboundMessage $message
     * @return array{url: string, tenant_key: string, inbound_secret: string}
     */
    protected function relaySettings(InboundMessage $message): array
    {
        $integration = optional($message->company)->chatAppIntegration;

        if ($integration instanceof CompanyChatAppIntegration && $integration->status === 'active') {
            return [
                'url' => (string) $integration->chatapp_inbound_url,
                'tenant_key' => (string) $integration->chatapp_tenant_key,
                'inbound_secret' => $integration->inboundSecret(),
            ];
        }

        return [
            'url' => (string) config('services.chat_app.inbound_url'),
            'tenant_key' => (string) config('services.chat_app.tenant_key', ''),
            'inbound_secret' => (string) config('services.chat_app.inbound_secret', ''),
        ];
    }

    /**
     * Normalize mobile number for ChatApp InfoTxt inbox expectations.
     *
     * @param string $mobile
     * @return string
     */
    protected function normalizeMobile(string $mobile): string
    {
        $mobile = preg_replace('/\s+/', '', trim($mobile)) ?? '';

        if (str_starts_with($mobile, '+63') && strlen($mobile) === 13) {
            return '0'.substr($mobile, 3);
        }

        if (str_starts_with($mobile, '63') && strlen($mobile) === 12) {
            return '0'.substr($mobile, 2);
        }

        return $mobile;
    }

    /**
     * Determine whether ChatApp acknowledged relay.
     *
     * @param mixed $decodedBody
     * @return bool
     */
    protected function isAcknowledged($decodedBody): bool
    {
        if (!is_array($decodedBody)) {
            return true;
        }

        if (!array_key_exists('ok', $decodedBody)) {
            return true;
        }

        return $decodedBody['ok'] === true;
    }
}
