<?php

namespace App\Services\Sms;

use App\Contracts\SmsSenderInterface;
use App\DTO\SmsSendResult;
use App\Models\Sim;
use App\Services\PythonRuntimeClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class PythonApiSmsSender implements SmsSenderInterface
{
    /**
     * @var \App\Services\PythonRuntimeClient
     */
    protected $runtimeClient;

    /**
     * @param \App\Services\PythonRuntimeClient $runtimeClient
     */
    public function __construct(PythonRuntimeClient $runtimeClient)
    {
        $this->runtimeClient = $runtimeClient;
    }

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
            $runtimeResult = $this->runtimeClient->send([
                'sim_id' => (string) $imsi,
                'to' => $phone,
                'phone' => $phone,
                'message' => $message,
                'client_message_id' => isset($meta['client_message_id']) ? (string) $meta['client_message_id'] : null,
                'meta' => $meta,
            ]);

            if ($runtimeResult['ok'] !== true) {
                $runtimeError = (string) ($runtimeResult['error'] ?? '');
                $runtimeRaw = is_array($runtimeResult['raw'] ?? null) ? $runtimeResult['raw'] : [];
                $runtimeLayer = isset($runtimeResult['error_layer']) && is_string($runtimeResult['error_layer'])
                    ? trim((string) $runtimeResult['error_layer'])
                    : null;

                if ($runtimeError === 'http_error') {
                    $status = isset($runtimeResult['status']) ? (int) $runtimeResult['status'] : null;
                    $errorLayer = $runtimeLayer ?: $this->extractErrorLayer($runtimeRaw, $runtimeRaw) ?: 'python_api';

                    Log::error('SMS_SEND_FAILED', [
                        'sim_id' => $simId,
                        'imsi' => $imsi,
                        'phone' => $phone,
                        'error' => 'HTTP_ERROR',
                        'error_layer' => $errorLayer,
                        'raw' => $runtimeRaw,
                    ]);

                    return SmsSendResult::failed('HTTP_ERROR', [
                        'status' => $status,
                        'body' => $runtimeRaw,
                    ], $errorLayer);
                }

                if ($runtimeError === 'runtime_unreachable') {
                    Log::error('SMS_SEND_FAILED', [
                        'sim_id' => $simId,
                        'imsi' => $imsi,
                        'phone' => $phone,
                        'error' => 'RUNTIME_UNREACHABLE',
                        'error_layer' => 'transport',
                        'raw' => $runtimeRaw,
                    ]);

                    return SmsSendResult::failed('RUNTIME_UNREACHABLE', $runtimeRaw, 'transport');
                }

                if ($runtimeError === 'runtime_timeout') {
                    Log::error('SMS_SEND_FAILED', [
                        'sim_id' => $simId,
                        'imsi' => $imsi,
                        'phone' => $phone,
                        'error' => 'RUNTIME_TIMEOUT',
                        'error_layer' => 'transport',
                        'raw' => $runtimeRaw,
                    ]);

                    return SmsSendResult::failed('RUNTIME_TIMEOUT', $runtimeRaw, 'transport');
                }

                if ($runtimeError === 'invalid_response') {
                    Log::error('SMS_SEND_FAILED', [
                        'sim_id' => $simId,
                        'imsi' => $imsi,
                        'phone' => $phone,
                        'error' => 'INVALID_RESPONSE',
                        'error_layer' => 'python_api',
                        'raw' => $runtimeRaw,
                    ]);

                    return SmsSendResult::failed('INVALID_RESPONSE', $runtimeRaw, 'python_api');
                }

                if ($runtimeError === 'python_api_url_not_configured') {
                    Log::error('SMS_SEND_FAILED', [
                        'sim_id' => $simId,
                        'phone' => $phone,
                        'error' => 'UNKNOWN_ERROR',
                        'error_layer' => 'gateway',
                        'raw' => $runtimeRaw,
                    ]);

                    return SmsSendResult::failed('UNKNOWN_ERROR', $runtimeRaw, 'gateway');
                }

                Log::error('SMS_SEND_FAILED', [
                    'sim_id' => $simId,
                    'imsi' => $imsi,
                    'phone' => $phone,
                    'error' => 'UNKNOWN_ERROR',
                    'error_layer' => 'gateway',
                    'raw' => $runtimeRaw,
                ]);

                return SmsSendResult::failed('UNKNOWN_ERROR', $runtimeRaw, 'gateway');
            }

            $payload = is_array($runtimeResult['data'] ?? null) ? $runtimeResult['data'] : [];
            $raw = is_array($runtimeResult['raw'] ?? null) ? $runtimeResult['raw'] : [];
            $errorLayer = isset($runtimeResult['error_layer']) && is_string($runtimeResult['error_layer'])
                ? trim((string) $runtimeResult['error_layer'])
                : null;
            $sendSuccess = (bool) ($runtimeResult['send_success'] ?? false);

            if ($sendSuccess !== true) {
                $error = isset($runtimeResult['send_error']) && is_string($runtimeResult['send_error']) && trim($runtimeResult['send_error']) !== ''
                    ? trim($runtimeResult['send_error'])
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
