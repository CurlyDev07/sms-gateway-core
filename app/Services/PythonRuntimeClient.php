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
}
