<?php

namespace App\Http\Controllers;

use App\Models\OutboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InfotxtStatusController extends Controller
{
    /**
     * InfoText-compatible delivery status lookup.
     *
     * Response contract expected by ChatApp:
     * - status: "0" queued, "1" sent, "2" failed
     * - smsid: outbound message id
     */
    public function show(Request $request): JsonResponse
    {
        $smsId = trim((string) $request->query('smsid', $request->query('SMSID', '')));

        if ($smsId === '') {
            return response()->json([
                'status' => '2',
                'message' => 'smsid_required',
            ], 422);
        }

        $message = OutboundMessage::query()
            ->where('id', $smsId)
            ->first();

        if ($message === null) {
            return response()->json([
                'status' => '2',
                'smsid' => (string) $smsId,
                'message' => 'not_found',
            ], 200);
        }

        return response()->json([
            'status' => $this->toInfotxtStatus((string) $message->status),
            'smsid' => (string) $message->id,
        ], 200);
    }

    private function toInfotxtStatus(string $status): string
    {
        if ($status === 'sent') {
            return '1';
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            return '2';
        }

        return '0';
    }
}
