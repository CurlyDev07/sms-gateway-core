<?php

namespace App\Services;

use App\Jobs\RelayOutboundStatusJob;
use App\Models\CompanyChatAppIntegration;
use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutboundStatusRelayService
{
    /**
     * @var array<int, string>
     */
    private const TERMINAL_STATUSES = ['sent', 'failed', 'cancelled'];

    /**
     * Queue a signed delivery-status callback for a terminal outbound status change.
     *
     * @param \App\Models\OutboundMessage $message
     * @param string|null $fromStatus
     * @return bool
     */
    public function queueIfEligible(OutboundMessage $message, ?string $fromStatus = null): bool
    {
        $status = strtolower((string) $message->status);
        $previous = strtolower((string) ($fromStatus ?? ''));

        if (!$this->isTerminalStatus($status)) {
            return false;
        }

        if ($previous !== '' && $previous === $status) {
            return false;
        }

        $settings = $this->resolveRelaySettings($message);

        if ($settings['url'] === '' || $settings['tenant_key'] === '' || $settings['secret'] === '') {
            return false;
        }

        $occurredAt = optional($message->updated_at)->toIso8601String() ?? now()->toIso8601String();
        $eventId = sprintf('GW-OUT-%s-%s-%s', (string) $message->uuid, strtoupper($status), sha1($occurredAt));

        RelayOutboundStatusJob::dispatch(
            (int) $message->id,
            $status,
            $eventId,
            $previous !== '' ? $previous : null
        );

        return true;
    }

    /**
     * Relay one delivery-status event to ChatApp.
     *
     * @param \App\Models\OutboundMessage $message
     * @param string $eventId
     * @param string|null $fromStatus
     * @return bool
     */
    public function relay(OutboundMessage $message, string $eventId, ?string $fromStatus = null): bool
    {
        $settings = $this->resolveRelaySettings($message);
        $url = $settings['url'];
        $tenantKey = $settings['tenant_key'];
        $secret = $settings['secret'];
        $timeout = (int) config('services.chat_app.timeout', 10);

        if ($url === '' || $tenantKey === '' || $secret === '') {
            Log::warning('Outbound status callback skipped: missing relay settings', [
                'outbound_message_id' => $message->id,
                'company_id' => $message->company_id,
                'url_configured' => $url !== '',
                'tenant_key_configured' => $tenantKey !== '',
                'secret_configured' => $secret !== '',
            ]);

            return true;
        }

        $runtimeSimId = $this->resolveRuntimeSimId($message);
        $payload = [
            'TENANT_KEY' => $tenantKey,
            'EVENT_ID' => $eventId,
            'SMSID' => (string) $message->id,
            'STATUS' => (string) $message->status,
            'FROM_STATUS' => $fromStatus,
            'FAILURE_REASON' => $message->failure_reason,
            'RETRY_COUNT' => (string) ((int) $message->retry_count),
            'COMPANY_ID' => (string) ((int) $message->company_id),
            'SIM_ID' => $message->sim_id !== null ? (string) ((int) $message->sim_id) : null,
            'RUNTIME_SIM_ID' => $runtimeSimId,
            'CLIENT_MESSAGE_ID' => $message->client_message_id,
            'MESSAGE_TYPE' => (string) $message->message_type,
            'OCCURRED_AT' => optional($message->updated_at)->toIso8601String(),
        ];

        $payload = array_filter($payload, static fn ($value) => $value !== null && $value !== '');
        $rawFormBody = http_build_query($payload, '', '&');
        $timestamp = (string) time();

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-Gateway-Timestamp' => $timestamp,
            'X-Gateway-Key-Id' => $tenantKey,
            'X-Gateway-Signature' => hash_hmac('sha256', $timestamp.'.'.$rawFormBody, $secret),
        ];

        try {
            $response = Http::withHeaders($headers)
                ->withBody($rawFormBody, 'application/x-www-form-urlencoded')
                ->timeout($timeout)
                ->post($url);

            if ($response->successful() && $this->isAcknowledged($response->json())) {
                Log::info('Outbound status callback success', [
                    'outbound_message_id' => $message->id,
                    'company_id' => $message->company_id,
                    'status' => $message->status,
                    'event_id' => $eventId,
                    'status_code' => $response->status(),
                ]);

                return true;
            }

            Log::warning('Outbound status callback failed', [
                'outbound_message_id' => $message->id,
                'company_id' => $message->company_id,
                'status' => $message->status,
                'event_id' => $eventId,
                'status_code' => $response->status(),
                'response_excerpt' => substr((string) $response->body(), 0, 300),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('Outbound status callback exception', [
                'outbound_message_id' => $message->id,
                'company_id' => $message->company_id,
                'status' => $message->status,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Resolve per-company callback settings with env fallback for local/dev.
     *
     * @param \App\Models\OutboundMessage $message
     * @return array{url:string,tenant_key:string,secret:string}
     */
    protected function resolveRelaySettings(OutboundMessage $message): array
    {
        $message->loadMissing('company.chatAppIntegration');
        $integration = optional($message->company)->chatAppIntegration;

        if ($integration instanceof CompanyChatAppIntegration && (string) $integration->status === 'active') {
            $url = trim((string) ($integration->chatapp_delivery_status_url ?? ''));

            if ($url === '') {
                $url = trim((string) config('services.chat_app.delivery_status_url', ''));
            }

            return [
                'url' => $url,
                'tenant_key' => trim((string) $integration->chatapp_tenant_key),
                'secret' => trim((string) $integration->inboundSecret()),
            ];
        }

        return [
            'url' => trim((string) config('services.chat_app.delivery_status_url', '')),
            'tenant_key' => trim((string) config('services.chat_app.tenant_key', '')),
            'secret' => trim((string) config('services.chat_app.inbound_secret', '')),
        ];
    }

    /**
     * @param string $status
     * @return bool
     */
    protected function isTerminalStatus(string $status): bool
    {
        return in_array(strtolower($status), self::TERMINAL_STATUSES, true);
    }

    /**
     * Determine whether ChatApp acknowledged callback.
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

    /**
     * Resolve runtime SIM identity from metadata or SIM relation for diagnostics.
     *
     * @param \App\Models\OutboundMessage $message
     * @return string|null
     */
    protected function resolveRuntimeSimId(OutboundMessage $message): ?string
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];

        $runtimeSimId = data_get($metadata, 'python_runtime.raw.sim_id');
        $runtimeSimId = is_string($runtimeSimId) ? trim($runtimeSimId) : '';

        if ($runtimeSimId !== '') {
            return $runtimeSimId;
        }

        $sim = $message->relationLoaded('sim') ? $message->sim : $message->sim()->first();
        $imsi = $sim !== null ? trim((string) ($sim->imsi ?? '')) : '';

        return $imsi !== '' ? $imsi : null;
    }
}

