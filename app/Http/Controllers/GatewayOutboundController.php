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

        $validator = Validator::make($request->all(), [
            'customer_phone' => ['required', 'string', 'max:30'],
            'message' => ['required', 'string'],
            'message_type' => ['required', 'in:CHAT,AUTO_REPLY,FOLLOW_UP,BLAST'],
            'scheduled_at' => ['nullable', 'date'],
            'client_message_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $customerPhone = trim((string) $validated['customer_phone']);

        $sim = $assignmentService->assignSim((int) $companyId, $customerPhone);

        if ($sim === null) {
            Log::warning('Outbound intake rejected: no SIM available', [
                'company_id' => $companyId,
                'customer_phone' => $customerPhone,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'no_sim_available',
            ], 503);
        }

        $operatorStatus = (string) ($sim->operator_status ?: 'active');

        if ($operatorStatus === 'blocked') {
            Log::warning('Outbound intake rejected: SIM blocked', [
                'company_id' => $companyId,
                'sim_id' => $sim->id,
                'customer_phone' => $customerPhone,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'sim_blocked',
            ], 503);
        }

        $isPaused = $operatorStatus === 'paused';
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

        if ($isPaused) {
            $outboundMessage = OutboundMessage::create(array_merge($messageData, [
                'status' => 'pending',
            ]));

            Log::info('Outbound intake accepted: SIM paused, saved without queue dispatch', [
                'outbound_message_id' => $outboundMessage->id,
                'company_id' => $companyId,
                'sim_id' => $sim->id,
                'status' => $outboundMessage->status,
            ]);

            return response()->json([
                'ok' => true,
                'status' => 'accepted',
                'queued' => false,
                'warning' => 'SIM is paused; message saved but not queued',
                'outbound_message_uuid' => $outboundMessage->uuid,
                'sim_id' => $sim->id,
            ], 202);
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

            return response()->json([
                'ok' => false,
                'error' => 'queue_enqueue_failed',
                'saved' => true,
                'queued' => false,
                'status' => 'pending',
                'outbound_message_uuid' => $outboundMessage->uuid,
                'sim_id' => $sim->id,
            ], 503);
        }

        Log::info('Outbound intake accepted', [
            'outbound_message_id' => $outboundMessage->id,
            'company_id' => $companyId,
            'sim_id' => $sim->id,
            'status' => 'queued',
        ]);

        return response()->json([
            'ok' => true,
            'status' => 'queued',
            'queued' => true,
            'outbound_message_uuid' => $outboundMessage->uuid,
            'sim_id' => $sim->id,
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
}
