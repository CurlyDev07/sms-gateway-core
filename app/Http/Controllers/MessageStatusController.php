<?php

namespace App\Http\Controllers;

use App\Models\OutboundMessage;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageStatusController extends Controller
{
    /**
     * Look up outbound message status by client_message_id (tenant-scoped).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);

        if ($companyId === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden',
            ], 403);
        }

        $clientMessageId = $request->query('client_message_id');

        if ($clientMessageId === null || $clientMessageId === '') {
            return response()->json([
                'ok'    => false,
                'error' => 'client_message_id is required',
            ], 422);
        }

        $query = OutboundMessage::query()
            ->where('company_id', $companyId)
            ->where('client_message_id', $clientMessageId);

        $simId = $request->query('sim_id');
        if ($simId !== null && $simId !== '') {
            $query->where('sim_id', (int) $simId);
        }

        $messages = $query->orderBy('id')->get();

        $data = $messages->map(function (OutboundMessage $message) {
            return [
                'id'                => $message->id,
                'client_message_id' => $message->client_message_id,
                'sim_id'            => $message->sim_id,
                'customer_phone'    => $message->customer_phone,
                'message_type'      => $message->message_type,
                'status'            => $message->status,
                'retry_count'       => $message->retry_count,
                'queued_at'         => $message->queued_at !== null ? $message->queued_at->toIso8601String() : null,
                'sent_at'           => $message->sent_at !== null ? $message->sent_at->toIso8601String() : null,
                'failed_at'         => $message->failed_at !== null ? $message->failed_at->toIso8601String() : null,
                'failure_reason'    => $message->failure_reason,
                'scheduled_at'      => $message->scheduled_at !== null ? $message->scheduled_at->toIso8601String() : null,
            ];
        });

        return response()->json([
            'ok'       => true,
            'messages' => $data,
        ]);
    }
}
