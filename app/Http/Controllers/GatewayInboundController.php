<?php

namespace App\Http\Controllers;

use App\Jobs\RelayInboundMessageJob;
use App\Models\InboundMessage;
use App\Models\Sim;
use App\Services\CustomerSimAssignmentService;
use Illuminate\Support\Carbon;
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
            'sim_id' => ['nullable', 'integer', 'min:1'],
            'runtime_sim_id' => ['nullable', 'string', 'max:64'],
            'imsi' => ['nullable', 'string', 'max:64'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'message' => ['required', 'string'],
            'received_at' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
            'metadata' => ['nullable', 'array'],
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
        $simId = isset($validated['sim_id']) ? (int) $validated['sim_id'] : null;
        $runtimeSimId = $this->resolveRuntimeSimIdentifier($validated);

        if ($simId === null && $runtimeSimId === null) {
            Log::warning('Inbound message ignored: missing SIM identifier', [
                'payload_keys' => array_keys($validated),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'sim_identifier_missing',
            ], 200);
        }

        $sim = $simId !== null
            ? Sim::query()->find($simId)
            : Sim::query()->where('imsi', $runtimeSimId)->first();

        if ($sim === null) {
            Log::warning('Inbound message ignored: SIM not found', [
                'sim_id' => $simId,
                'runtime_sim_id' => $runtimeSimId,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'sim_not_found',
            ], 200);
        }

        $customerPhone = trim($validated['customer_phone']);
        $receivedAt = $validated['received_at'];
        $idempotencyKey = $this->resolveIdempotencyKey($validated, $sim, $runtimeSimId);

        $duplicateMessage = InboundMessage::query()
            ->where('company_id', $sim->company_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($duplicateMessage !== null) {
            Log::info('Inbound duplicate ignored', [
                'sim_id' => $sim->id,
                'company_id' => $sim->company_id,
                'customer_phone' => $customerPhone,
                'inbound_message_id' => $duplicateMessage->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json([
                'ok' => true,
                'duplicate' => true,
                'inbound_message_uuid' => $duplicateMessage->uuid,
                'idempotency_key' => $idempotencyKey,
            ], 200);
        }

        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
        $metadata['inbound_runtime'] = [
            'runtime_sim_id' => $runtimeSimId,
            'resolved_via' => $simId !== null ? 'sim_id' : 'imsi',
        ];

        $inboundMessage = InboundMessage::create([
            'company_id' => $sim->company_id,
            'sim_id' => $sim->id,
            'runtime_sim_id' => $runtimeSimId,
            'customer_phone' => $customerPhone,
            'message' => $validated['message'],
            'received_at' => $receivedAt,
            'idempotency_key' => $idempotencyKey,
            'relay_status' => 'pending',
            'relayed_to_chat_app' => false,
            'metadata' => $metadata,
        ]);

        Log::info('Inbound message received', [
            'inbound_message_id' => $inboundMessage->id,
            'company_id' => $inboundMessage->company_id,
            'sim_id' => $inboundMessage->sim_id,
            'runtime_sim_id' => $runtimeSimId,
            'customer_phone' => $inboundMessage->customer_phone,
            'idempotency_key' => $idempotencyKey,
        ]);

        $assignmentService->markReplied((int) $sim->company_id, (string) $customerPhone);

        RelayInboundMessageJob::dispatch($inboundMessage);

        return response()->json([
            'ok' => true,
            'inbound_message_uuid' => $inboundMessage->uuid,
            'idempotency_key' => $idempotencyKey,
            'queued_for_relay' => true,
        ], 200);
    }

    /**
     * Resolve runtime SIM identifier from supported payload fields.
     *
     * @param array<string,mixed> $validated
     * @return string|null
     */
    protected function resolveRuntimeSimIdentifier(array $validated): ?string
    {
        $runtimeSimId = trim((string) ($validated['runtime_sim_id'] ?? ''));

        if ($runtimeSimId !== '') {
            return $runtimeSimId;
        }

        $imsi = trim((string) ($validated['imsi'] ?? ''));

        return $imsi !== '' ? $imsi : null;
    }

    /**
     * Build idempotency key for inbound message retry safety.
     *
     * @param array<string,mixed> $validated
     * @param \App\Models\Sim $sim
     * @param string|null $runtimeSimId
     * @return string
     */
    protected function resolveIdempotencyKey(array $validated, Sim $sim, ?string $runtimeSimId): string
    {
        $provided = trim((string) ($validated['idempotency_key'] ?? ''));

        if ($provided !== '') {
            return $provided;
        }

        $customerPhone = trim((string) ($validated['customer_phone'] ?? ''));
        $message = (string) ($validated['message'] ?? '');
        $receivedAt = Carbon::parse((string) ($validated['received_at'] ?? now()->toIso8601String()))->toIso8601String();
        $identity = $runtimeSimId !== null ? $runtimeSimId : (string) $sim->id;

        return hash('sha256', implode('|', [
            $identity,
            $customerPhone,
            $message,
            $receivedAt,
        ]));
    }
}
