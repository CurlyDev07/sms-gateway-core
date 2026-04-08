<?php

namespace App\Http\Controllers;

use App\Models\OutboundMessage;
use App\Services\CustomerSimAssignmentService;
use App\Services\RedisQueueService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class GatewayOutboundController extends Controller
{
    /**
     * Store outbound SMS request with Phase 2 intake + Redis queue semantics.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\CustomerSimAssignmentService $assignmentService
     * @param \App\Services\RedisQueueService $redisQueueService
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(
        Request $request,
        CustomerSimAssignmentService $assignmentService,
        RedisQueueService $redisQueueService
    ): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), $this->singleMessageRules());

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $result = $this->processSingleMessage(
            (int) $companyId,
            $validator->validated(),
            $assignmentService,
            $redisQueueService
        );

        return response()->json($result['response'], $result['http_status']);
    }

    /**
     * Store outbound SMS requests in bulk using the same intake rules as single-send.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\CustomerSimAssignmentService $assignmentService
     * @param \App\Services\RedisQueueService $redisQueueService
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulk(
        Request $request,
        CustomerSimAssignmentService $assignmentService,
        RedisQueueService $redisQueueService
    ): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'messages' => ['required', 'array', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ((array) $request->input('messages', []) as $index => $item) {
            if (!is_array($item)) {
                $failed++;
                $results[] = [
                    'index' => $index,
                    'client_message_id' => null,
                    'customer_phone' => null,
                    'status' => 'failed',
                    'result' => 'failed',
                    'message_id' => null,
                    'error' => 'validation_failed',
                    'details' => ['messages.'.$index => ['Each message item must be an object.']],
                ];
                continue;
            }

            $itemValidator = Validator::make($item, $this->singleMessageRules(true));

            if ($itemValidator->fails()) {
                $failed++;
                $results[] = [
                    'index' => $index,
                    'client_message_id' => $item['client_message_id'] ?? null,
                    'customer_phone' => isset($item['customer_phone']) ? trim((string) $item['customer_phone']) : null,
                    'status' => 'failed',
                    'result' => 'failed',
                    'message_id' => null,
                    'error' => 'validation_failed',
                    'details' => $itemValidator->errors()->toArray(),
                ];
                continue;
            }

            $processed = $this->processSingleMessage(
                (int) $companyId,
                $itemValidator->validated(),
                $assignmentService,
                $redisQueueService
            );

            $response = $processed['response'];
            $isOk = (bool) ($response['ok'] ?? false);

            if ($isOk) {
                $succeeded++;
            } else {
                $failed++;
            }

            $results[] = [
                'index' => $index,
                'client_message_id' => $itemValidator->validated()['client_message_id'],
                'customer_phone' => trim((string) $itemValidator->validated()['customer_phone']),
                'status' => $isOk ? (string) ($response['status'] ?? 'accepted') : 'failed',
                'result' => $isOk ? (string) ($response['status'] ?? 'accepted') : 'failed',
                'queued' => (bool) ($response['queued'] ?? false),
                'message_id' => $processed['message_id'],
                'outbound_message_uuid' => $response['outbound_message_uuid'] ?? null,
                'sim_id' => $response['sim_id'] ?? null,
                'error' => $isOk ? null : (string) ($response['error'] ?? 'unknown_error'),
            ];
        }

        return response()->json([
            'ok' => true,
            'results' => $results,
            'summary' => [
                'total' => count($results),
                'succeeded' => $succeeded,
                'failed' => $failed,
            ],
        ], 200);
    }

    /**
     * Determine outbound priority from transport message type.
     *
     * @param string $messageType
     * @return int
     */
    protected function priorityForType(string $messageType): int
    {
        if ($messageType === 'CHAT') {
            return 100;
        }

        if ($messageType === 'AUTO_REPLY') {
            return 90;
        }

        if ($messageType === 'FOLLOW_UP') {
            return 50;
        }

        return 10;
    }

    /**
     * @param bool $requireClientMessageId
     * @return array<string, array<int, string>>
     */
    protected function singleMessageRules(bool $requireClientMessageId = false): array
    {
        $clientMessageIdRules = ['nullable', 'string', 'max:255'];

        if ($requireClientMessageId) {
            $clientMessageIdRules[0] = 'required';
        }

        return [
            'customer_phone' => ['required', 'string', 'max:30'],
            'message' => ['required', 'string'],
            'message_type' => ['required', 'in:CHAT,AUTO_REPLY,FOLLOW_UP,BLAST'],
            'scheduled_at' => ['nullable', 'date'],
            'client_message_id' => $clientMessageIdRules,
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @param int $companyId
     * @param array<string, mixed> $validated
     * @param \App\Services\CustomerSimAssignmentService $assignmentService
     * @param \App\Services\RedisQueueService $redisQueueService
     * @return array{http_status:int,response:array<string,mixed>,message_id:int|null}
     */
    protected function processSingleMessage(
        int $companyId,
        array $validated,
        CustomerSimAssignmentService $assignmentService,
        RedisQueueService $redisQueueService
    ): array
    {
        $customerPhone = trim((string) $validated['customer_phone']);
        $sim = $assignmentService->assignSim($companyId, $customerPhone);

        if ($sim === null) {
            Log::warning('Outbound intake rejected: no SIM available', [
                'company_id' => $companyId,
                'customer_phone' => $customerPhone,
            ]);

            return [
                'http_status' => 503,
                'response' => [
                    'ok' => false,
                    'error' => 'no_sim_available',
                ],
                'message_id' => null,
            ];
        }

        $operatorStatus = (string) ($sim->operator_status ?: 'active');

        if ($operatorStatus === 'blocked') {
            Log::warning('Outbound intake rejected: SIM blocked', [
                'company_id' => $companyId,
                'sim_id' => $sim->id,
                'customer_phone' => $customerPhone,
            ]);

            return [
                'http_status' => 503,
                'response' => [
                    'ok' => false,
                    'error' => 'sim_blocked',
                ],
                'message_id' => null,
            ];
        }

        $messageData = [
            'company_id' => $companyId,
            'sim_id' => $sim->id,
            'customer_phone' => $customerPhone,
            'message' => (string) $validated['message'],
            'message_type' => (string) $validated['message_type'],
            'priority' => $this->priorityForType((string) $validated['message_type']),
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'client_message_id' => $validated['client_message_id'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ];

        if ($operatorStatus === 'paused') {
            $outboundMessage = OutboundMessage::create(array_merge($messageData, [
                'status' => 'pending',
            ]));

            Log::info('Outbound intake accepted: SIM paused, saved without queue dispatch', [
                'outbound_message_id' => $outboundMessage->id,
                'company_id' => $companyId,
                'sim_id' => $sim->id,
                'status' => $outboundMessage->status,
            ]);

            return [
                'http_status' => 202,
                'response' => [
                    'ok' => true,
                    'status' => 'accepted',
                    'queued' => false,
                    'warning' => 'SIM is paused; message saved but not queued',
                    'outbound_message_uuid' => $outboundMessage->uuid,
                    'sim_id' => $sim->id,
                ],
                'message_id' => (int) $outboundMessage->id,
            ];
        }

        $outboundMessage = OutboundMessage::create(array_merge($messageData, [
            'status' => 'pending',
        ]));

        try {
            $redisQueueService->enqueue(
                (int) $sim->id,
                (int) $outboundMessage->id,
                (string) $outboundMessage->message_type
            );

            $outboundMessage->update([
                'status' => 'queued',
                'queued_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Outbound intake enqueue failed: message saved as pending', [
                'outbound_message_id' => $outboundMessage->id,
                'company_id' => $companyId,
                'sim_id' => $sim->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'http_status' => 503,
                'response' => [
                    'ok' => false,
                    'error' => 'queue_enqueue_failed',
                    'saved' => true,
                    'queued' => false,
                    'status' => 'pending',
                    'outbound_message_uuid' => $outboundMessage->uuid,
                    'sim_id' => $sim->id,
                ],
                'message_id' => (int) $outboundMessage->id,
            ];
        }

        Log::info('Outbound intake accepted', [
            'outbound_message_id' => $outboundMessage->id,
            'company_id' => $companyId,
            'sim_id' => $sim->id,
            'status' => 'queued',
        ]);

        return [
            'http_status' => 200,
            'response' => [
                'ok' => true,
                'status' => 'queued',
                'queued' => true,
                'outbound_message_uuid' => $outboundMessage->uuid,
                'sim_id' => $sim->id,
            ],
            'message_id' => (int) $outboundMessage->id,
        ];
    }
}
