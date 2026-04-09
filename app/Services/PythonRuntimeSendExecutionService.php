<?php

namespace App\Services;

use App\Contracts\SmsSenderInterface;
use App\DTO\SmsSendResult;
use App\Models\OutboundMessage;
use App\Models\Sim;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PythonRuntimeSendExecutionService
{
    /**
     * @var \App\Contracts\SmsSenderInterface
     */
    protected $smsSender;

    /**
     * @param \App\Contracts\SmsSenderInterface $smsSender
     */
    public function __construct(SmsSenderInterface $smsSender)
    {
        $this->smsSender = $smsSender;
    }

    /**
     * Execute one direct runtime send-test for a tenant-owned SIM and persist result details.
     *
     * This flow is intentionally dashboard/session scoped for controlled operator verification.
     * It does not enqueue to Redis and does not schedule retries.
     *
     * @param int $companyId
     * @param int $simId
     * @param string $customerPhone
     * @param string $message
     * @param string|null $clientMessageId
     * @return array<string,mixed>
     */
    public function executeForTenant(
        int $companyId,
        int $simId,
        string $customerPhone,
        string $message,
        ?string $clientMessageId = null
    ): array {
        $sim = Sim::query()
            ->where('company_id', $companyId)
            ->where('id', $simId)
            ->first();

        if ($sim === null) {
            throw new InvalidArgumentException('sim_not_found');
        }

        if (!$sim->isActive() || !$sim->isOperatorActive()) {
            throw new InvalidArgumentException('sim_not_send_ready');
        }

        $outboundMessage = OutboundMessage::query()->create([
            'company_id' => $companyId,
            'sim_id' => $simId,
            'customer_phone' => trim($customerPhone),
            'message' => $message,
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'sending',
            'locked_at' => now(),
            'client_message_id' => $clientMessageId,
            'metadata' => [
                'runtime_send_test' => [
                    'source' => 'dashboard_runtime_send_test',
                    'requested_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $result = $this->smsSender->send(
            $simId,
            trim($customerPhone),
            $message,
            [
                'client_message_id' => $clientMessageId,
                'message_id' => $outboundMessage->id,
                'sim_id' => $simId,
                'company_id' => $companyId,
                'execution_surface' => 'dashboard_runtime_send_test',
            ]
        );

        return DB::transaction(function () use ($sim, $outboundMessage, $result): array {
            $locked = OutboundMessage::query()
                ->where('id', $outboundMessage->id)
                ->lockForUpdate()
                ->firstOrFail();

            $metadata = $this->mergeRuntimeMetadata($locked->metadata, $result);

            if ($result->success) {
                $locked->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                    'scheduled_at' => null,
                    'locked_at' => null,
                    'metadata' => $metadata,
                ]);

                Sim::query()
                    ->where('id', $sim->id)
                    ->update([
                        'last_success_at' => now(),
                        'last_sent_at' => now(),
                    ]);
            } else {
                $locked->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'failure_reason' => $result->error ?: 'RUNTIME_SEND_FAILED',
                    'scheduled_at' => null,
                    'locked_at' => null,
                    'metadata' => $metadata,
                ]);
            }

            $locked->refresh();

            return [
                'success' => (bool) $result->success,
                'status' => (string) $locked->status,
                'message_id' => (int) $locked->id,
                'outbound_message_uuid' => (string) $locked->uuid,
                'sim_id' => (int) $locked->sim_id,
                'provider_message_id' => $result->providerMessageId,
                'error' => $result->error,
                'error_layer' => $result->errorLayer,
            ];
        });
    }

    /**
     * @param mixed $existing
     * @param \App\DTO\SmsSendResult $result
     * @return array<string,mixed>
     */
    protected function mergeRuntimeMetadata($existing, SmsSendResult $result): array
    {
        $metadata = is_array($existing) ? $existing : [];

        $metadata['python_runtime'] = [
            'source' => 'dashboard_runtime_send_test',
            'processed_at' => now()->toIso8601String(),
            'success' => (bool) $result->success,
            'provider_message_id' => $result->providerMessageId,
            'error' => $result->error,
            'error_layer' => $result->errorLayer,
            'raw' => $result->raw,
        ];

        return $metadata;
    }
}

