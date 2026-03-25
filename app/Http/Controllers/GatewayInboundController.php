<?php

namespace App\Http\Controllers;

use App\Jobs\RelayInboundMessageJob;
use App\Models\InboundMessage;
use App\Models\Sim;
use App\Services\CustomerSimAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GatewayInboundController extends Controller
{
    /**
     * Receive inbound SMS from modem transport and relay to Chat App.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\CustomerSimAssignmentService $assignmentService
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(
        Request $request,
        CustomerSimAssignmentService $assignmentService
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'sim_id' => ['required', 'integer'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'message' => ['required', 'string'],
            'received_at' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            Log::warning('Inbound message validation failed', [
                'errors' => $validator->errors()->toArray(),
            ]);

            // Always ACK inbound webhook to avoid sender retry loops.
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
            ], 200);
        }

        $validated = $validator->validated();

        $sim = Sim::query()->find($validated['sim_id']);

        if ($sim === null) {
            Log::warning('Inbound message ignored: SIM not found', [
                'sim_id' => $validated['sim_id'],
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'sim_not_found',
            ], 200);
        }

        $customerPhone = trim($validated['customer_phone']);
        $receivedAt = $validated['received_at'];
        $duplicateWindowStart = now()->subSeconds(10);

        $duplicateMessage = InboundMessage::query()
            ->where('sim_id', $sim->id)
            ->where('customer_phone', $customerPhone)
            ->where('message', $validated['message'])
            ->where('received_at', '>=', $duplicateWindowStart)
            ->first();

        if ($duplicateMessage !== null) {
            Log::info('Inbound duplicate ignored', [
                'sim_id' => $sim->id,
                'company_id' => $sim->company_id,
                'customer_phone' => $customerPhone,
                'inbound_message_id' => $duplicateMessage->id,
            ]);

            return response()->json([
                'ok' => true,
                'duplicate' => true,
                'inbound_message_uuid' => $duplicateMessage->uuid,
            ], 200);
        }

        $inboundMessage = InboundMessage::create([
            'company_id' => $sim->company_id,
            'sim_id' => $sim->id,
            'customer_phone' => $customerPhone,
            'message' => $validated['message'],
            'received_at' => $receivedAt,
            'relay_status' => 'pending',
            'relayed_to_chat_app' => false,
        ]);

        Log::info('Inbound message received', [
            'inbound_message_id' => $inboundMessage->id,
            'company_id' => $inboundMessage->company_id,
            'sim_id' => $inboundMessage->sim_id,
            'customer_phone' => $inboundMessage->customer_phone,
        ]);

        $assignmentService->markReplied((int) $sim->company_id, (string) $customerPhone);

        RelayInboundMessageJob::dispatch($inboundMessage);

        return response()->json([
            'ok' => true,
            'inbound_message_uuid' => $inboundMessage->uuid,
            'queued_for_relay' => true,
        ], 200);
    }
}
