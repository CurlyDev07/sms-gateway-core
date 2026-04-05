<?php

namespace App\Services\Sms;

use App\Contracts\SmsSenderInterface;
use App\DTO\SmsSendResult;
use App\Models\Sim;
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
        $imsi = $this->resolveImsi($simId);

        Log::info('SMS_SEND_ATTEMPT', [
            'sim_id' => $simId,
            'imsi' => $imsi,
            'phone' => $phone,
            'meta' => $meta,
        ]);

        if ($baseUrl === '') {
            Log::error('SMS_SEND_FAILED', [
                'sim_id' => $simId,
                'phone' => $phone,
                'error' => 'UNKNOWN_ERROR',
                'error_layer' => 'gateway',
                'raw' => ['error' => 'SMS_PYTHON_API_URL is not configured'],
            ]);

            return SmsSendResult::failed('UNKNOWN_ERROR', [
                'error' => 'SMS_PYTHON_API_URL is not configured',
            ], 'gateway');
        }

        if ($imsi === null || trim($imsi) === '') {
            Log::error('SMS_SEND_FAILED', [
                'sim_id' => $simId,
                'phone' => $phone,
                'error' => 'SIM_IMSI_MISSING',
                'error_layer' => 'gateway',
                'raw' => ['error' => 'SIM IMSI is missing'],
            ]);

            return SmsSendResult::failed('SIM_IMSI_MISSING', [
                'error' => 'SIM IMSI is missing',
                'sim_id' => $simId,
            ], 'gateway');
        }

        try {
            $sendPath = (string) config('sms.python_api_send_path', '/send');
            $response = Http::timeout(35)
                ->post($baseUrl.$sendPath, [
                    'sim_id' => (string) $imsi,
                    'phone' => $phone,
                    'message' => $message,
                    'meta' => $meta,
                ]);

            $json = $response->json();
            $payload = is_array($json) ? $json : ['body' => (string) $response->body()];
            $raw = isset($payload['raw']) && is_array($payload['raw']) ? $payload['raw'] : $payload;
            $errorLayer = $this->extractErrorLayer($payload, $raw);

            if (!$response->successful()) {
                Log::error('SMS_SEND_FAILED', [
                    'sim_id' => $simId,
                    'imsi' => $imsi,
                    'phone' => $phone,
                    'error' => 'HTTP_ERROR',
                    'error_layer' => $errorLayer ?: 'python_api',
                    'raw' => $raw,
                ]);

                return SmsSendResult::failed('HTTP_ERROR', [
                    'status' => $response->status(),
                    'body' => $raw,
                ], $errorLayer ?: 'python_api');
            }

            if (($payload['success'] ?? false) !== true) {
                $error = isset($payload['error']) && is_string($payload['error']) && trim($payload['error']) !== ''
                    ? trim($payload['error'])
                    : 'PROVIDER_REJECTED';

                Log::error('SMS_SEND_FAILED', [
                    'sim_id' => $simId,
                    'imsi' => $imsi,
                    'phone' => $phone,
                    'error' => $error,
                    'error_layer' => $errorLayer,
                    'raw' => $raw,
                ]);

                return SmsSendResult::failed($error, $raw, $errorLayer);
            }

            $providerMessageId = null;

            if (isset($payload['message_id']) && $payload['message_id'] !== null) {
                $providerMessageId = (string) $payload['message_id'];
            } elseif (isset($raw['message_id']) && $raw['message_id'] !== null) {
                $providerMessageId = (string) $raw['message_id'];
            }

            Log::info('SMS_SEND_SUCCESS', [
                'sim_id' => $simId,
                'imsi' => $imsi,
                'phone' => $phone,
                'provider_message_id' => $providerMessageId,
                'raw' => $payload,
            ]);

            return SmsSendResult::success($providerMessageId, $raw);
        } catch (ConnectionException $e) {
            Log::error('SMS_SEND_EXCEPTION', [
                'sim_id' => $simId,
                'imsi' => $imsi,
                'phone' => $phone,
                'exception' => $e->getMessage(),
            ]);

            return SmsSendResult::failed('NETWORK_ERROR', [
                'exception' => $e->getMessage(),
            ], 'transport');
        } catch (Throwable $e) {
            Log::error('SMS_SEND_EXCEPTION', [
                'sim_id' => $simId,
                'imsi' => $imsi,
                'phone' => $phone,
                'exception' => $e->getMessage(),
            ]);

            return SmsSendResult::failed('UNKNOWN_ERROR', [
                'exception' => $e->getMessage(),
            ], 'gateway');
        }
    }

    /**
     * @param int $simId
     * @return string|null
     */
    protected function resolveImsi(int $simId): ?string
    {
        $sim = Sim::query()->find($simId);

        if ($sim === null) {
            return null;
        }

        $imsi = $sim->imsi;

        if (!is_string($imsi)) {
            return null;
        }

        $imsi = trim($imsi);

        return $imsi === '' ? null : $imsi;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $raw
     * @return string|null
     */
    protected function extractErrorLayer(array $payload, array $raw): ?string
    {
        if (isset($raw['error_layer']) && is_string($raw['error_layer'])) {
            $layer = trim($raw['error_layer']);

            if ($layer !== '') {
                return $layer;
            }
        }

        if (isset($payload['error_layer']) && is_string($payload['error_layer'])) {
            $layer = trim($payload['error_layer']);

            if ($layer !== '') {
                return $layer;
            }
        }

        return null;
    }
}
