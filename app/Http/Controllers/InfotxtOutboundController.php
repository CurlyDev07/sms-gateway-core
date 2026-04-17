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

class InfotxtOutboundController extends Controller
{
    /**
     * Accept InfoText-style outbound payload and enqueue through gateway pipeline.
     *
     * Expected form fields:
     * - UserID (maps to api_clients.api_key via middleware)
     * - ApiKey (maps to api_clients.api_secret via middleware)
     * - Mobile
     * - SMS
     * - optional Type / MessageType
     */
    public function store(
        Request $request,
        CustomerSimAssignmentService $assignmentService,
        RedisQueueService $redisQueueService
    ): JsonResponse {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return $this->providerError('99', 'forbidden', 403);
        }

        $validator = Validator::make($request->all(), [
            'UserID' => ['required', 'string', 'max:255'],
            'ApiKey' => ['required', 'string', 'max:255'],
            'Mobile' => ['required', 'string', 'max:30'],
            'SMS' => ['required', 'string', 'max:1000'],
            'Type' => ['nullable', 'string', 'max:50'],
            'MessageType' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->providerError('99', 'validation_failed', 422, [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        $mobile = $this->normalizeMobile((string) $request->input('Mobile'));
        $messageType = $this->resolveMessageType($request);

        $sim = $assignmentService->assignSim((int) $companyId, $mobile);

        if ($sim === null) {
            Log::warning('InfoText-style outbound rejected: no SIM available', [
                'company_id' => $companyId,
                'customer_phone' => $mobile,
            ]);

            return $this->providerError('99', 'no_sim_available', 503);
        }

        $operatorStatus = (string) ($sim->operator_status ?: 'active');

        if ($operatorStatus === 'blocked') {
            Log::warning('InfoText-style outbound rejected: SIM blocked', [
                'company_id' => $companyId,
                'sim_id' => $sim->id,
                'customer_phone' => $mobile,
            ]);

            return $this->providerError('99', 'sim_blocked', 503);
        }

        $messageData = [
            'company_id' => (int) $companyId,
            'sim_id' => (int) $sim->id,
            'customer_phone' => $mobile,
            'message' => (string) $request->input('SMS'),
            'message_type' => $messageType,
            'priority' => $this->priorityForType($messageType),
            'scheduled_at' => null,
            'client_message_id' => null,
            'metadata' => [
                'source' => 'infotxt_outbound_compat',
                'infotxt' => [
                    'user_id' => (string) $request->input('UserID'),
                    'type_input' => (string) ($request->input('MessageType') ?? $request->input('Type') ?? ''),
                ],
            ],
        ];

        if ($operatorStatus === 'paused') {
            $outboundMessage = OutboundMessage::query()->create(array_merge($messageData, [
                'status' => 'pending',
            ]));

            Log::info('InfoText-style outbound accepted: SIM paused, saved without queue dispatch', [
                'outbound_message_id' => $outboundMessage->id,
                'company_id' => $companyId,
                'sim_id' => $sim->id,
            ]);

            return response()->json([
                'status' => '00',
                'smsid' => (string) $outboundMessage->id,
            ], 200);
        }

        $outboundMessage = OutboundMessage::query()->create(array_merge($messageData, [
            'status' => 'pending',
        ]));

        try {
            $redisQueueService->enqueue(
                (int) $sim->id,
                (int) $outboundMessage->id,
                $messageType
            );

            $outboundMessage->update([
                'status' => 'queued',
                'queued_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('InfoText-style outbound enqueue failed: message saved as pending', [
                'outbound_message_id' => $outboundMessage->id,
                'company_id' => $companyId,
                'sim_id' => $sim->id,
                'error' => $e->getMessage(),
            ]);

            return $this->providerError('99', 'queue_enqueue_failed', 503, [
                'saved' => true,
                'smsid' => (string) $outboundMessage->id,
            ]);
        }

        Log::info('InfoText-style outbound accepted', [
            'outbound_message_id' => $outboundMessage->id,
            'company_id' => $companyId,
            'sim_id' => $sim->id,
        ]);

        return response()->json([
            'status' => '00',
            'smsid' => (string) $outboundMessage->id,
        ], 200);
    }

    protected function providerError(
        string $status,
        string $message,
        int $httpStatus,
        array $extra = []
    ): JsonResponse {
        return response()->json(array_merge([
            'status' => $status,
            'message' => $message,
        ], $extra), $httpStatus);
    }

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

    protected function resolveMessageType(Request $request): string
    {
        $rawType = (string) ($request->input('MessageType') ?? $request->input('Type') ?? 'CHAT');
        $normalized = strtoupper(str_replace(['-', ' '], '_', trim($rawType)));

        if ($normalized === 'FOLLOWUP') {
            return 'FOLLOW_UP';
        }

        if ($normalized === 'BLASTING') {
            return 'BLAST';
        }

        if (in_array($normalized, ['CHAT', 'AUTO_REPLY', 'FOLLOW_UP', 'BLAST'], true)) {
            return $normalized;
        }

        return 'CHAT';
    }

    protected function normalizeMobile(string $mobile): string
    {
        $mobile = preg_replace('/\s+/', '', trim($mobile)) ?? '';

        if (str_starts_with($mobile, '+63') && strlen($mobile) === 13) {
            return '0'.substr($mobile, 3);
        }

        if (str_starts_with($mobile, '63') && strlen($mobile) === 12) {
            return '0'.substr($mobile, 2);
        }

        return $mobile;
    }
}

