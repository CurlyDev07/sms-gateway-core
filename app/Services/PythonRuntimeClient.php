<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class PythonRuntimeClient
{
    /**
     * Check Python runtime health endpoint.
     *
     * @return array{ok:bool,status:int|null,error:string|null,data:array<string,mixed>,raw:mixed}
     */
    public function health(): array
    {
        $result = $this->get((string) config('sms.python_api_health_path', '/health'));

        return [
            'ok' => $result['ok'],
            'status' => $result['status'],
            'error' => $result['error'],
            'data' => is_array($result['data']) ? $result['data'] : [],
            'raw' => $result['raw'],
        ];
    }

    /**
     * Query Python modem discovery endpoint.
     *
     * @return array{ok:bool,status:int|null,error:string|null,modems:array<int,array<string,mixed>>,data:array<string,mixed>,raw:mixed}
     */
    public function discover(): array
    {
        $result = $this->get((string) config('sms.python_api_discover_path', '/modems/discover'));
        $data = is_array($result['data']) ? $result['data'] : [];

        return [
            'ok' => $result['ok'],
            'status' => $result['status'],
            'error' => $result['error'],
            'modems' => $this->extractModems($data),
            'data' => $data,
            'raw' => $result['raw'],
        ];
    }

    /**
     * Execute one SMS send call against Python runtime.
     *
     * @param array<string,mixed> $payload
     * @return array{
     *   ok:bool,
     *   status:int|null,
     *   error:string|null,
     *   error_layer:string|null,
     *   send_success:bool|null,
     *   send_error:string|null,
     *   data:array<string,mixed>,
     *   raw:mixed
     * }
     */
    public function send(array $payload): array
    {
        $result = $this->post((string) config('sms.python_api_send_path', '/send'), $payload);
        $status = $result['status'];
        $data = is_array($result['data']) ? $result['data'] : [];
        $raw = $result['raw'];

        if (!$result['ok']) {
            $rawArray = $this->toArray($raw);
            $nestedRaw = isset($rawArray['raw']) && is_array($rawArray['raw']) ? $rawArray['raw'] : [];

            return [
                'ok' => false,
                'status' => $status,
                'error' => $result['error'],
                'error_layer' => $this->extractErrorLayer($nestedRaw, $rawArray) ?? $this->extractErrorLayer($rawArray, $data),
                'send_success' => null,
                'send_error' => null,
                'data' => $data,
                'raw' => $raw,
            ];
        }

        if (!array_key_exists('success', $data) || !is_bool($data['success'])) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'invalid_response',
                'error_layer' => 'python_api',
                'send_success' => null,
                'send_error' => null,
                'data' => $data,
                'raw' => $raw,
            ];
        }

        $normalizedRaw = isset($data['raw']) && is_array($data['raw']) ? $data['raw'] : $data;
        $errorLayer = $this->extractErrorLayer($normalizedRaw, $data);
        $sendError = null;

        if ($data['success'] !== true) {
            $sendError = $this->firstString($data, ['error'])
                ?? $this->firstString($normalizedRaw, ['error'])
                ?? 'PROVIDER_REJECTED';
        }

        return [
            'ok' => true,
            'status' => $status,
            'error' => null,
            'error_layer' => $errorLayer,
            'send_success' => (bool) $data['success'],
            'send_error' => $sendError,
            'data' => $data,
            'raw' => $normalizedRaw,
        ];
    }

    /**
     * @param string $path
     * @return array{ok:bool,status:int|null,error:string|null,data:mixed,raw:mixed}
     */
    protected function get(string $path): array
    {
        $baseUrl = rtrim((string) config('sms.python_api_url'), '/');

        if ($baseUrl === '') {
            return [
                'ok' => false,
                'status' => null,
                'error' => 'python_api_url_not_configured',
                'data' => [],
                'raw' => ['error' => 'SMS_PYTHON_API_URL is not configured'],
            ];
        }

        $fullUrl = $baseUrl.'/'.ltrim($path, '/');

        try {
            $response = $this->http()->get($fullUrl);

            return $this->normalizeResponse($response);
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'status' => null,
                'error' => 'connection_failed',
                'data' => [],
                'raw' => [
                    'exception' => $e->getMessage(),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'error' => 'runtime_client_exception',
                'data' => [],
                'raw' => [
                    'exception' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param string $path
     * @param array<string,mixed> $payload
     * @return array{ok:bool,status:int|null,error:string|null,data:mixed,raw:mixed}
     */
    protected function post(string $path, array $payload): array
    {
        $baseUrl = rtrim((string) config('sms.python_api_url'), '/');

        if ($baseUrl === '') {
            return [
                'ok' => false,
                'status' => null,
                'error' => 'python_api_url_not_configured',
                'data' => [],
                'raw' => ['error' => 'SMS_PYTHON_API_URL is not configured'],
            ];
        }

        $fullUrl = $baseUrl.'/'.ltrim($path, '/');

        try {
            $response = $this->http()->post($fullUrl, $payload);

            return $this->normalizeResponse($response);
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'status' => null,
                'error' => $this->classifySendConnectionException($e),
                'data' => [],
                'raw' => [
                    'exception' => $e->getMessage(),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'error' => 'runtime_client_exception',
                'data' => [],
                'raw' => [
                    'exception' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function http(): PendingRequest
    {
        $timeout = (int) config('sms.python_api_timeout_seconds', 35);
        $token = trim((string) config('sms.python_api_token', ''));

        $http = Http::timeout($timeout)->acceptJson();

        if ($token !== '') {
            $http = $http->withHeaders([
                'X-Gateway-Token' => $token,
            ]);
        }

        return $http;
    }

    /**
     * @param \Illuminate\Http\Client\Response $response
     * @return array{ok:bool,status:int|null,error:string|null,data:mixed,raw:mixed}
     */
    protected function normalizeResponse(Response $response): array
    {
        $status = $response->status();
        $json = $response->json();
        $data = is_array($json) ? $json : [];
        $raw = is_array($json) ? $json : ['body' => (string) $response->body()];

        if (!$response->successful()) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'http_error',
                'data' => $data,
                'raw' => $raw,
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'error' => null,
            'data' => $data,
            'raw' => $raw,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    protected function extractModems(array $data): array
    {
        $modems = [];

        if (isset($data['modems']) && is_array($data['modems'])) {
            $modems = $data['modems'];
        } elseif (isset($data['data']) && is_array($data['data']) && isset($data['data']['modems']) && is_array($data['data']['modems'])) {
            $modems = $data['data']['modems'];
        } elseif (array_is_list($data)) {
            $modems = $data;
        }

        if (!is_array($modems)) {
            return [];
        }

        $result = [];
        foreach ($modems as $modem) {
            if (is_array($modem)) {
                $result[] = $modem;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $first
     * @param array<string,mixed> $fallback
     * @return string|null
     */
    protected function extractErrorLayer(array $first, array $fallback): ?string
    {
        $layer = $this->firstString($first, ['error_layer']);

        if ($layer !== null && trim($layer) !== '') {
            return trim($layer);
        }

        $layer = $this->firstString($fallback, ['error_layer']);

        if ($layer !== null && trim($layer) !== '') {
            return trim($layer);
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    protected function toArray($value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param \Illuminate\Http\Client\ConnectionException $exception
     * @return string
     */
    protected function classifySendConnectionException(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28')) {
            return 'runtime_timeout';
        }

        return 'runtime_unreachable';
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $keys
     * @return string|null
     */
    protected function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            if (is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return null;
    }
}
