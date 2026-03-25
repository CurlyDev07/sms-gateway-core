<?php

namespace App\Services\Sms;

use App\Contracts\SmsSenderInterface;
use App\DTO\SmsSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PythonApiSmsSender implements SmsSenderInterface
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
        $baseUrl = rtrim((string) config('sms.python_api_url'), '/');

        Log::info('SMS_SEND_ATTEMPT', [
            'sim_id' => $simId,
            'phone' => $phone,
            'meta' => $meta,
        ]);

        if ($baseUrl === '') {
            Log::error('SMS_SEND_FAILED', [
                'sim_id' => $simId,
                'phone' => $phone,
                'error' => 'UNKNOWN_ERROR',
                'raw' => ['error' => 'SMS_PYTHON_API_URL is not configured'],
            ]);

            return SmsSendResult::failed('UNKNOWN_ERROR', [
                'error' => 'SMS_PYTHON_API_URL is not configured',
            ]);
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->retry(2, 200)
                ->post($baseUrl.'/send', [
                    'sim_id' => $simId,
                    'phone' => $phone,
                    'message' => $message,
                    'meta' => $meta,
                ]);

            $json = $response->json();
            $raw = is_array($json) ? $json : ['body' => (string) $response->body()];

            if (!$response->successful()) {
                Log::error('SMS_SEND_FAILED', [
                    'sim_id' => $simId,
                    'phone' => $phone,
                    'error' => 'HTTP_ERROR',
                    'raw' => $raw,
                ]);

                return SmsSendResult::failed('HTTP_ERROR', [
                    'status' => $response->status(),
                    'body' => $raw,
                ]);
            }

            if (!isset($raw['status']) || $raw['status'] !== 'success') {
                Log::error('SMS_SEND_FAILED', [
                    'sim_id' => $simId,
                    'phone' => $phone,
                    'error' => 'PROVIDER_REJECTED',
                    'raw' => $raw,
                ]);

                return SmsSendResult::failed('PROVIDER_REJECTED', $raw);
            }

            Log::info('SMS_SEND_SUCCESS', [
                'sim_id' => $simId,
                'phone' => $phone,
                'provider_message_id' => isset($raw['message_id']) ? (string) $raw['message_id'] : null,
                'raw' => $raw,
            ]);

            return SmsSendResult::success(
                isset($raw['message_id']) ? (string) $raw['message_id'] : null,
                $raw
            );
        } catch (ConnectionException $e) {
            Log::error('SMS_SEND_EXCEPTION', [
                'sim_id' => $simId,
                'phone' => $phone,
                'exception' => $e->getMessage(),
            ]);

            return SmsSendResult::failed('NETWORK_ERROR', [
                'exception' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            Log::error('SMS_SEND_EXCEPTION', [
                'sim_id' => $simId,
                'phone' => $phone,
                'exception' => $e->getMessage(),
            ]);

            return SmsSendResult::failed('UNKNOWN_ERROR', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
