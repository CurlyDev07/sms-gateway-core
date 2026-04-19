<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Models\InboundMessage;
use App\Models\OutboundMessage;
use App\Models\Sim;
use App\Services\InboundRelayRetryService;
use App\Services\PythonRuntimeClient;
use App\Services\RedisQueueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

class OpsPanelController extends Controller
{
    /**
     * Render no-auth operations panel.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index(): View
    {
        return view('ops.index');
    }

    /**
     * Read-only telemetry payload for ops panel.
     *
     * @param \App\Services\PythonRuntimeClient $runtimeClient
     * @param \App\Services\RedisQueueService $redisQueueService
     * @return \Illuminate\Http\JsonResponse
     */
    public function data(
        Request $request,
        PythonRuntimeClient $runtimeClient,
        RedisQueueService $redisQueueService
    ): JsonResponse {
        $runtimeHealth = $runtimeClient->health();
        $runtimeDiscoverySnapshot = $this->resolveRuntimeDiscoverySnapshot(
            $runtimeClient,
            $request->boolean('refresh_discover')
        );
        $runtimeDiscovery = $runtimeDiscoverySnapshot['discovery'];
        $normalizedModems = $runtimeDiscoverySnapshot['normalized_modems'];

        $runtimeByImsi = [];
        foreach ($normalizedModems as $modem) {
            $runtimeSimId = trim((string) ($modem['sim_id'] ?? ''));

            if ($runtimeSimId === '' || !preg_match('/^[0-9]{15}$/', $runtimeSimId)) {
                continue;
            }

            if (!isset($runtimeByImsi[$runtimeSimId])) {
                $runtimeByImsi[$runtimeSimId] = $modem;
            }
        }

        $sims = Sim::query()
            ->with(['company:id,name,code'])
            ->orderBy('company_id')
            ->orderBy('id')
            ->get([
                'id',
                'company_id',
                'phone_number',
                'imsi',
                'status',
                'operator_status',
                'accept_new_assignments',
                'disabled_for_new_assignments',
                'cooldown_until',
                'last_success_at',
                'last_error_at',
            ]);

        $simRows = [];
        $perSimQueueTotal = 0;
        foreach ($sims as $sim) {
            $simId = (int) $sim->id;
            $runtime = $runtimeByImsi[(string) $sim->imsi] ?? null;

            $queueChat = $this->safeRedisInt(function () use ($redisQueueService, $simId) {
                return $redisQueueService->depth($simId, 'chat');
            });
            $queueFollowup = $this->safeRedisInt(function () use ($redisQueueService, $simId) {
                return $redisQueueService->depth($simId, 'followup');
            });
            $queueBlasting = $this->safeRedisInt(function () use ($redisQueueService, $simId) {
                return $redisQueueService->depth($simId, 'blasting');
            });
            $queueTotal = $queueChat + $queueFollowup + $queueBlasting;
            $perSimQueueTotal += $queueTotal;

            $simRows[] = [
                'sim_id' => $simId,
                'company_id' => (int) $sim->company_id,
                'company_code' => (string) optional($sim->company)->code,
                'company_name' => (string) optional($sim->company)->name,
                'phone_number' => (string) $sim->phone_number,
                'imsi' => $sim->imsi !== null ? (string) $sim->imsi : null,
                'status' => (string) $sim->status,
                'operator_status' => (string) $sim->operator_status,
                'accept_new_assignments' => (bool) $sim->accept_new_assignments,
                'disabled_for_new_assignments' => (bool) $sim->disabled_for_new_assignments,
                'cooldown_until' => $sim->cooldown_until !== null ? $sim->cooldown_until->toIso8601String() : null,
                'last_success_at' => $sim->last_success_at !== null ? $sim->last_success_at->toIso8601String() : null,
                'last_error_at' => $sim->last_error_at !== null ? $sim->last_error_at->toIso8601String() : null,
                'queue_depth' => [
                    'total' => $queueTotal,
                    'chat' => $queueChat,
                    'followup' => $queueFollowup,
                    'blasting' => $queueBlasting,
                ],
                'runtime' => $runtime,
            ];
        }

        $inboundRecent = InboundMessage::query()
            ->with(['company:id,name,code'])
            ->latest('id')
            ->limit(100)
            ->get([
                'id',
                'uuid',
                'company_id',
                'sim_id',
                'runtime_sim_id',
                'customer_phone',
                'message',
                'idempotency_key',
                'relay_status',
                'relay_error',
                'relay_retry_count',
                'relay_next_attempt_at',
                'relayed_at',
                'received_at',
                'created_at',
            ])
            ->map(function (InboundMessage $row) {
                return [
                    'id' => (int) $row->id,
                    'uuid' => (string) $row->uuid,
                    'company_id' => (int) $row->company_id,
                    'company_code' => (string) optional($row->company)->code,
                    'sim_id' => (int) $row->sim_id,
                    'runtime_sim_id' => $row->runtime_sim_id !== null ? (string) $row->runtime_sim_id : null,
                    'customer_phone' => (string) $row->customer_phone,
                    'message' => (string) $row->message,
                    'idempotency_key' => $row->idempotency_key !== null ? (string) $row->idempotency_key : null,
                    'relay_status' => (string) $row->relay_status,
                    'relay_error' => $row->relay_error !== null ? (string) $row->relay_error : null,
                    'relay_retry_count' => (int) $row->relay_retry_count,
                    'relay_next_attempt_at' => $row->relay_next_attempt_at !== null ? $row->relay_next_attempt_at->toIso8601String() : null,
                    'relayed_at' => $row->relayed_at !== null ? $row->relayed_at->toIso8601String() : null,
                    'received_at' => $row->received_at !== null ? $row->received_at->toIso8601String() : null,
                    'created_at' => $row->created_at !== null ? $row->created_at->toIso8601String() : null,
                ];
            })
            ->values()
            ->all();

        $outboundRecent = OutboundMessage::query()
            ->with(['company:id,name,code'])
            ->latest('id')
            ->limit(100)
            ->get([
                'id',
                'uuid',
                'company_id',
                'sim_id',
                'customer_phone',
                'message_type',
                'status',
                'failure_reason',
                'retry_count',
                'client_message_id',
                'queued_at',
                'scheduled_at',
                'locked_at',
                'sent_at',
                'failed_at',
                'created_at',
                'updated_at',
            ])
            ->map(function (OutboundMessage $row) {
                return [
                    'id' => (int) $row->id,
                    'uuid' => (string) $row->uuid,
                    'company_id' => (int) $row->company_id,
                    'company_code' => (string) optional($row->company)->code,
                    'sim_id' => (int) $row->sim_id,
                    'customer_phone' => (string) $row->customer_phone,
                    'message_type' => (string) $row->message_type,
                    'status' => (string) $row->status,
                    'failure_reason' => $row->failure_reason !== null ? (string) $row->failure_reason : null,
                    'retry_count' => (int) $row->retry_count,
                    'client_message_id' => $row->client_message_id !== null ? (string) $row->client_message_id : null,
                    'queued_at' => $row->queued_at !== null ? $row->queued_at->toIso8601String() : null,
                    'scheduled_at' => $row->scheduled_at !== null ? $row->scheduled_at->toIso8601String() : null,
                    'locked_at' => $row->locked_at !== null ? $row->locked_at->toIso8601String() : null,
                    'sent_at' => $row->sent_at !== null ? $row->sent_at->toIso8601String() : null,
                    'failed_at' => $row->failed_at !== null ? $row->failed_at->toIso8601String() : null,
                    'created_at' => $row->created_at !== null ? $row->created_at->toIso8601String() : null,
                    'updated_at' => $row->updated_at !== null ? $row->updated_at->toIso8601String() : null,
                ];
            })
            ->values()
            ->all();

        $activeApiClients = ApiClient::query()
            ->with(['company:id,name,code'])
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'company_id', 'name', 'api_key', 'status', 'updated_at'])
            ->map(function (ApiClient $client) {
                return [
                    'id' => (int) $client->id,
                    'company_id' => $client->company_id !== null ? (int) $client->company_id : null,
                    'company_code' => (string) optional($client->company)->code,
                    'name' => (string) $client->name,
                    'api_key' => (string) $client->api_key,
                    'status' => (string) $client->status,
                    'updated_at' => $client->updated_at !== null ? $client->updated_at->toIso8601String() : null,
                ];
            })
            ->values()
            ->all();

        $now = now();
        $oneHourAgo = $now->copy()->subHour();

        $inboundHourCounts = InboundMessage::query()
            ->where('created_at', '>=', $oneHourAgo)
            ->selectRaw('relay_status, count(*) as c')
            ->groupBy('relay_status')
            ->pluck('c', 'relay_status');

        $outboundHourCounts = OutboundMessage::query()
            ->where('created_at', '>=', $oneHourAgo)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $runtimeSendReadyCount = 0;
        $runtimeProbeErrorCount = 0;
        foreach ($normalizedModems as $modem) {
            if ($this->runtimeSendReady($modem)) {
                $runtimeSendReadyCount++;
            }

            if (!empty($modem['probe_error'])) {
                $runtimeProbeErrorCount++;
            }
        }

        $defaultQueueDepth = $this->safeRedisInt(function () {
            return Redis::llen('default');
        });

        $sendingStaleCount = OutboundMessage::query()
            ->where('status', 'sending')
            ->where('updated_at', '<=', Carbon::now()->subMinutes(3))
            ->count();

        $queuedOldCount = OutboundMessage::query()
            ->where('status', 'queued')
            ->where('created_at', '<=', Carbon::now()->subMinutes(5))
            ->count();

        $relayPendingTotal = InboundMessage::query()->where('relay_status', 'pending')->count();
        $relayFailedTotal = InboundMessage::query()->where('relay_status', 'failed')->count();

        $pipelineHint = $this->buildPipelineHint(
            (bool) ($runtimeHealth['ok'] ?? false),
            (bool) ($runtimeDiscovery['ok'] ?? false),
            $relayPendingTotal,
            $relayFailedTotal,
            $sendingStaleCount,
            $queuedOldCount
        );

        return response()->json([
            'ok' => true,
            'generated_at' => $now->toIso8601String(),
            'pipeline_hint' => $pipelineHint,
            'summary' => [
                'inbound_1h' => [
                    'total' => (int) array_sum($inboundHourCounts->toArray()),
                    'success' => (int) ($inboundHourCounts['success'] ?? 0),
                    'pending' => (int) ($inboundHourCounts['pending'] ?? 0),
                    'failed' => (int) ($inboundHourCounts['failed'] ?? 0),
                ],
                'outbound_1h' => [
                    'total' => (int) array_sum($outboundHourCounts->toArray()),
                    'sent' => (int) ($outboundHourCounts['sent'] ?? 0),
                    'queued' => (int) ($outboundHourCounts['queued'] ?? 0),
                    'sending' => (int) ($outboundHourCounts['sending'] ?? 0),
                    'pending' => (int) ($outboundHourCounts['pending'] ?? 0),
                    'failed' => (int) ($outboundHourCounts['failed'] ?? 0),
                ],
                'relay' => [
                    'pending_total' => $relayPendingTotal,
                    'failed_total' => $relayFailedTotal,
                ],
                'queues' => [
                    'default_depth' => $defaultQueueDepth,
                    'per_sim_total_depth' => $perSimQueueTotal,
                    'sending_stale_count' => $sendingStaleCount,
                    'queued_old_count' => $queuedOldCount,
                ],
                'runtime' => [
                    'health_ok' => (bool) ($runtimeHealth['ok'] ?? false),
                    'health_status' => $runtimeHealth['status'] ?? null,
                    'health_error' => $runtimeHealth['error'] ?? null,
                    'discover_ok' => (bool) ($runtimeDiscovery['ok'] ?? false),
                    'discover_status' => $runtimeDiscovery['status'] ?? null,
                    'discover_error' => $runtimeDiscovery['error'] ?? null,
                    'modems_total' => count($normalizedModems),
                    'send_ready_total' => $runtimeSendReadyCount,
                    'probe_error_total' => $runtimeProbeErrorCount,
                ],
                'entities' => [
                    'sims_total' => count($simRows),
                    'active_api_clients_total' => count($activeApiClients),
                ],
            ],
            'runtime' => [
                'health' => $runtimeHealth,
                'discovery' => [
                    'ok' => (bool) ($runtimeDiscovery['ok'] ?? false),
                    'status' => $runtimeDiscovery['status'] ?? null,
                    'error' => $runtimeDiscovery['error'] ?? null,
                    'modems' => $normalizedModems,
                ],
                'discover_refreshed' => $runtimeDiscoverySnapshot['refreshed'],
                'discover_cached_at' => $runtimeDiscoverySnapshot['cached_at'],
            ],
            'tables' => [
                'sims' => $simRows,
                'inbound_recent' => $inboundRecent,
                'outbound_recent' => $outboundRecent,
                'webhook_recent' => $inboundRecent,
                'api_clients' => $activeApiClients,
            ],
        ]);
    }

    /**
     * Force all inbound relay rows back to pending and dispatch retries now.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\InboundRelayRetryService $inboundRelayRetryService
     * @return \Illuminate\Http\JsonResponse
     */
    public function retryAllInbound(Request $request, InboundRelayRetryService $inboundRelayRetryService): JsonResponse
    {
        $limit = min(5000, max(1, (int) $request->input('limit', 500)));
        $retryAt = now();

        $resetCount = InboundMessage::query()
            ->whereIn('relay_status', ['pending', 'failed'])
            ->update([
                'relay_status' => 'pending',
                'relay_retry_count' => 0,
                'relay_next_attempt_at' => $retryAt,
                'relay_failed_at' => null,
                'relay_locked_at' => null,
            ]);

        $dispatched = $inboundRelayRetryService->dispatchDueRetries($limit);

        return response()->json([
            'ok' => true,
            'message' => 'Inbound relay retry-all queued.',
            'reset_count' => (int) $resetCount,
            'dispatched' => (int) $dispatched,
            'limit' => $limit,
            'retry_at' => $retryAt->toIso8601String(),
        ]);
    }

    /**
     * Force outbound retry rows to pending and enqueue due retries now.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function retryAllOutbound(Request $request): JsonResponse
    {
        $limit = min(10000, max(1, (int) $request->input('limit', 1000)));
        $retryAt = now();

        $resetCount = OutboundMessage::query()
            ->whereIn('status', ['failed', 'pending', 'queued', 'sending'])
            ->update([
                'status' => 'pending',
                'scheduled_at' => $retryAt,
                'queued_at' => null,
                'locked_at' => null,
                'failed_at' => null,
            ]);

        $dueCandidateIds = OutboundMessage::query()
            ->where('status', 'pending')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $retryAt)
            ->whereHas('sim', function ($query) {
                $query->where('operator_status', '!=', 'paused');
            })
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $exitCode = Artisan::call('gateway:retry-scheduler', [
            '--limit' => $limit,
        ]);
        $schedulerOutput = trim(Artisan::output());
        $enqueuedCount = OutboundMessage::query()
            ->whereIn('id', $dueCandidateIds)
            ->where('status', 'queued')
            ->count();

        if ($exitCode !== 0) {
            return response()->json([
                'ok' => false,
                'error' => 'retry_scheduler_failed',
                'message' => 'Outbound rows were reset, but retry scheduler reported enqueue failures.',
                'reset_count' => (int) $resetCount,
                'limit' => $limit,
                'enqueued' => (int) $enqueuedCount,
                'scheduler_output' => $schedulerOutput,
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Outbound retry-all enqueued.',
            'reset_count' => (int) $resetCount,
            'limit' => $limit,
            'retry_at' => $retryAt->toIso8601String(),
            'enqueued' => (int) $enqueuedCount,
            'scheduler_output' => $schedulerOutput,
        ]);
    }

    /**
     * Resolve runtime discovery payload with cache fallback to reduce probe pressure.
     *
     * @param \App\Services\PythonRuntimeClient $runtimeClient
     * @param bool $forceRefresh
     * @return array{discovery:array<string,mixed>,normalized_modems:array<int,array<string,mixed>>,refreshed:bool,cached_at:?string}
     */
    protected function resolveRuntimeDiscoverySnapshot(PythonRuntimeClient $runtimeClient, bool $forceRefresh = false): array
    {
        $cacheKey = 'ops:runtime_discovery_snapshot';
        $ttlSeconds = 120;
        $cached = Cache::get($cacheKey);
        $hasValidCache = is_array($cached)
            && isset($cached['discovery'])
            && is_array($cached['discovery'])
            && isset($cached['normalized_modems'])
            && is_array($cached['normalized_modems']);

        if (!$forceRefresh && $hasValidCache) {
            return [
                'discovery' => $cached['discovery'],
                'normalized_modems' => $cached['normalized_modems'],
                'refreshed' => false,
                'cached_at' => isset($cached['cached_at']) ? (string) $cached['cached_at'] : null,
            ];
        }

        $freshDiscovery = $runtimeClient->discover();
        $freshModems = $this->normalizeModemRows($freshDiscovery['modems'] ?? []);
        $cachedAt = now()->toIso8601String();

        if (($freshDiscovery['ok'] ?? false) === true || !$hasValidCache) {
            Cache::put($cacheKey, [
                'discovery' => $freshDiscovery,
                'normalized_modems' => $freshModems,
                'cached_at' => $cachedAt,
            ], now()->addSeconds($ttlSeconds));

            return [
                'discovery' => $freshDiscovery,
                'normalized_modems' => $freshModems,
                'refreshed' => true,
                'cached_at' => $cachedAt,
            ];
        }

        // Fallback to last known-good snapshot when forced refresh fails.
        return [
            'discovery' => $cached['discovery'],
            'normalized_modems' => $cached['normalized_modems'],
            'refreshed' => false,
            'cached_at' => isset($cached['cached_at']) ? (string) $cached['cached_at'] : null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $modems
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeModemRows(array $modems): array
    {
        $rows = [];
        foreach ($modems as $modem) {
            if (!is_array($modem)) {
                continue;
            }

            $rows[] = [
                'device_id' => $this->firstString($modem, ['device_id', 'modem_id', 'id']),
                'sim_id' => $this->firstString($modem, ['sim_id', 'imsi']),
                'port' => $this->firstString($modem, ['port', 'device_port', 'tty']),
                'identifier_source' => $this->firstString($modem, ['identifier_source']),
                'effective_send_ready' => is_bool($modem['effective_send_ready'] ?? null) ? $modem['effective_send_ready'] : null,
                'realtime_probe_ready' => is_bool($modem['realtime_probe_ready'] ?? null) ? $modem['realtime_probe_ready'] : null,
                'send_ready' => is_bool($modem['send_ready'] ?? null) ? $modem['send_ready'] : null,
                'at_ok' => isset($modem['at_ok']) ? (bool) $modem['at_ok'] : null,
                'sim_ready' => isset($modem['sim_ready']) ? (bool) $modem['sim_ready'] : null,
                'creg_registered' => isset($modem['creg_registered']) ? (bool) $modem['creg_registered'] : null,
                'signal' => $modem['signal'] ?? null,
                'readiness_reason_code' => $this->firstString($modem, ['readiness_reason_code']),
                'probe_error' => $this->firstString($modem, ['probe_error']),
                'last_seen_at' => $this->firstString($modem, ['last_seen_at', 'last_seen']),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $modem
     * @return bool
     */
    protected function runtimeSendReady(array $modem): bool
    {
        if (is_bool($modem['effective_send_ready'] ?? null)) {
            return (bool) $modem['effective_send_ready'];
        }

        if (is_bool($modem['realtime_probe_ready'] ?? null)) {
            return (bool) $modem['realtime_probe_ready'];
        }

        return (bool) ($modem['at_ok'] ?? false)
            && (bool) ($modem['sim_ready'] ?? false)
            && (bool) ($modem['creg_registered'] ?? false);
    }

    /**
     * @param callable():mixed $reader
     * @return int
     */
    protected function safeRedisInt(callable $reader): int
    {
        try {
            return max(0, (int) $reader());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @param bool $healthOk
     * @param bool $discoverOk
     * @param int $relayPendingTotal
     * @param int $relayFailedTotal
     * @param int $sendingStaleCount
     * @param int $queuedOldCount
     * @return array<string,mixed>
     */
    protected function buildPipelineHint(
        bool $healthOk,
        bool $discoverOk,
        int $relayPendingTotal,
        int $relayFailedTotal,
        int $sendingStaleCount,
        int $queuedOldCount
    ): array {
        if (!$healthOk || !$discoverOk) {
            return [
                'layer' => 'python_runtime',
                'severity' => 'critical',
                'message' => 'Python runtime health/discovery is failing. Inbound/outbound may be blocked before Gateway processing.',
            ];
        }

        if ($relayFailedTotal > 0 || $relayPendingTotal > 0) {
            return [
                'layer' => 'gateway_to_chatapp_webhook',
                'severity' => 'warning',
                'message' => 'Inbound relay has pending/failed rows. Python -> Gateway may be fine, but Gateway -> ChatApp delivery needs attention.',
            ];
        }

        if ($sendingStaleCount > 0 || $queuedOldCount > 0) {
            return [
                'layer' => 'outbound_processing',
                'severity' => 'warning',
                'message' => 'Outbound has stale sending/queued rows. Check SIM workers, queue depth, and modem readiness.',
            ];
        }

        return [
            'layer' => 'healthy',
            'severity' => 'ok',
            'message' => 'No immediate pipeline blockers detected from Gateway-side telemetry.',
        ];
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
