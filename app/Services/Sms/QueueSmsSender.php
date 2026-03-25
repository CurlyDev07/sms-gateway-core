<?php

namespace App\Services\Sms;

use App\Contracts\SmsSenderInterface;
use App\DTO\SmsSendResult;
use Illuminate\Support\Facades\Redis;
use Throwable;

class QueueSmsSender implements SmsSenderInterface
{
    /**
     * @param int $simId
     * @param string $phone
     * @param string $message
     * @param array<string, mixed> $meta
     * @return \App\DTO\SmsSendResult
     */
    public function send(int $simId, string $phone, string $message, array $meta = []): SmsSendResult
    {
        $payload = [
            'sim_id' => $simId,
            'phone' => $phone,
            'message' => $message,
            'meta' => $meta,
            'queued_at' => now()->toIso8601String(),
        ];

        try {
            Redis::rpush('sms_outbound_queue', json_encode($payload));

            return SmsSendResult::success(null, [
                'queued' => true,
                'queue' => 'sms_outbound_queue',
            ]);
        } catch (Throwable $e) {
            return SmsSendResult::failed('UNKNOWN_ERROR', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
