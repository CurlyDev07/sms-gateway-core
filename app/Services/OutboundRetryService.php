<?php

namespace App\Services;

use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Log;

class OutboundRetryService
{
    private const RETRYABLE_ERRORS = [
        'RUNTIME_UNREACHABLE',
        'RUNTIME_TIMEOUT',
        'CONNECTION_FAILED',
        'TEMPORARY_NETWORK_FAILURE',
        'SIM_NOT_REGISTERED',
        'NETWORK_NOT_REGISTERED',
        'NO_SIGNAL',
        'MODEM_TIMEOUT',
        'MODEM_BUSY',
        'PORT_BUSY',
    ];
    private const NON_RETRYABLE_ERRORS = [
        'INVALID_RESPONSE',
        'SIM_IMSI_MISSING',
    ];
    private const RETRYABLE_NETWORK_ERRORS = [
        'TEMPORARY_NETWORK_FAILURE',
        'SIM_NOT_REGISTERED',
        'NETWORK_NOT_REGISTERED',
        'NO_SIGNAL',
    ];

    /**
     * Handle outbound send failure by scheduling fixed-interval retry.
     *
     * @param \App\Models\OutboundMessage $message
     * @param string|null $error
     * @param string $source
     * @return void
     */
    public function handleSendFailure(OutboundMessage $message, ?string $error = null, string $source = 'send'): void
    {
        $nextRetryCount = (int) $message->retry_count + 1;
        $reason = $error ?: 'Outbound send failed';
        $retryDelaySeconds = $this->retryDelaySeconds();
        $nextAttemptAt = now()->addSeconds($retryDelaySeconds);

        $message->update([
            'status' => 'pending',
            'retry_count' => $nextRetryCount,
            'failed_at' => now(),
            'failure_reason' => $reason,
            'scheduled_at' => $nextAttemptAt,
            'locked_at' => null,
        ]);

        Log::warning('Outbound retry scheduled', [
            'message_id' => $message->id,
            'company_id' => $message->company_id,
            'sim_id' => $message->sim_id,
            'retry_count' => $nextRetryCount,
            'retry_delay_seconds' => $retryDelaySeconds,
            'next_attempt_at' => $nextAttemptAt->toDateTimeString(),
            'source' => $source,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Handle a permanent carrier/provider rejection with no retry scheduled.
     *
     * Use this when the errorLayer is 'network' (Python-confirmed carrier rejection).
     * The message is marked failed and will not be retried automatically.
     * Operator may manually intervene or migrate the message.
     *
     * @param \App\Models\OutboundMessage $message
     * @param string|null $error
     * @param string $source
     * @return void
     */
    public function handlePermanentFailure(OutboundMessage $message, ?string $error = null, string $source = 'send'): void
    {
        $nextRetryCount = (int) $message->retry_count + 1;
        $reason = $error ?: 'Permanent carrier rejection';

        $message->update([
            'status' => 'failed',
            'retry_count' => $nextRetryCount,
            'failed_at' => now(),
            'failure_reason' => $reason,
            'scheduled_at' => null,
            'locked_at' => null,
        ]);

        Log::warning('Outbound permanent failure (no retry scheduled)', [
            'message_id' => $message->id,
            'company_id' => $message->company_id,
            'sim_id' => $message->sim_id,
            'retry_count' => $nextRetryCount,
            'source' => $source,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Classify one send failure into retryable vs non-retryable behavior.
     *
     * @param string|null $error
     * @param string|null $errorLayer
     * @return array{retryable:bool,classification:string,reason:string,error:?string,error_layer:?string}
     */
    public function classifyFailure(?string $error = null, ?string $errorLayer = null): array
    {
        $normalizedError = strtoupper(trim((string) $error));
        $normalizedLayer = strtolower(trim((string) $errorLayer));

        if ($this->retryAllFailuresEnabled()) {
            return [
                'retryable' => true,
                'classification' => 'retryable',
                'reason' => 'operator_policy_retry_all_failures',
                'error' => $error,
                'error_layer' => $errorLayer,
            ];
        }

        if ($normalizedError !== '' && in_array($normalizedError, self::RETRYABLE_ERRORS, true)) {
            return [
                'retryable' => true,
                'classification' => 'retryable',
                'reason' => 'explicit_retryable_error_code',
                'error' => $error,
                'error_layer' => $errorLayer,
            ];
        }

        if ($normalizedError !== '' && in_array($normalizedError, self::NON_RETRYABLE_ERRORS, true)) {
            return [
                'retryable' => false,
                'classification' => 'non_retryable',
                'reason' => 'explicit_non_retryable_error_code',
                'error' => $error,
                'error_layer' => $errorLayer,
            ];
        }

        if ($normalizedLayer === 'network') {
            if ($normalizedError !== '' && in_array($normalizedError, self::RETRYABLE_NETWORK_ERRORS, true)) {
                return [
                    'retryable' => true,
                    'classification' => 'retryable',
                    'reason' => 'temporary_network_signal',
                    'error' => $error,
                    'error_layer' => $errorLayer,
                ];
            }

            return [
                'retryable' => false,
                'classification' => 'non_retryable',
                'reason' => 'carrier_rejection_network_layer',
                'error' => $error,
                'error_layer' => $errorLayer,
            ];
        }

        if (in_array($normalizedLayer, ['python_api', 'hardware'], true)) {
            return [
                'retryable' => false,
                'classification' => 'non_retryable',
                'reason' => 'non_retryable_error_layer',
                'error' => $error,
                'error_layer' => $errorLayer,
            ];
        }

        if ($normalizedLayer === '' || in_array($normalizedLayer, ['transport', 'gateway', 'modem', 'unknown'], true)) {
            return [
                'retryable' => true,
                'classification' => 'retryable',
                'reason' => 'layer_retryable_by_policy',
                'error' => $error,
                'error_layer' => $errorLayer,
            ];
        }

        return [
            'retryable' => true,
            'classification' => 'retryable',
            'reason' => 'fallback_retryable',
            'error' => $error,
            'error_layer' => $errorLayer,
        ];
    }

    /**
     * Determine if outbound message can still be retried.
     *
     * @param \App\Models\OutboundMessage $message
     * @return bool
     */
    public function canRetry(OutboundMessage $message): bool
    {
        return true;
    }

    /**
     * Resolve fixed outbound retry delay in seconds.
     *
     * @return int
     */
    protected function retryDelaySeconds(): int
    {
        return max(1, (int) config('services.gateway.outbound_retry_base_delay_seconds', 10));
    }

    /**
     * @return bool
     */
    protected function retryAllFailuresEnabled(): bool
    {
        return (bool) config('services.gateway.outbound_retry_all_failures', true);
    }
}
