<?php

namespace App\Services;

use App\Models\InboundMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboundRelayService
{
    /**
     * Relay inbound message to Chat App webhook.
     *
     * @param \App\Models\InboundMessage $message
     * @return bool
     */
    public function relay(InboundMessage $message): bool
    {
        $message->loadMissing('sim');

        $url = (string) config('services.chat_app.inbound_url');
        $timeout = (int) config('services.chat_app.timeout', 10);

        if ($url === '') {
            $message->update([
                'relay_status' => 'failed',
                'relay_error' => 'Chat App inbound URL is not configured',
            ]);

            Log::warning('Inbound relay failure: missing chat app inbound URL', [
                'inbound_message_id' => $message->id,
            ]);

            return false;
        }

        $payload = [
            // InfoTxt-compatible contract expected by ChatApp.
            'ID' => 'GW-IN-'.$message->id,
            'MOBILE' => (string) $message->customer_phone,
            'SMS' => (string) $message->message,
            'RECEIVED' => $message->received_at !== null ? $message->received_at->format('Y-m-d H:i:s') : null,
        ];

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->post($url, array_filter($payload, static fn ($value) => $value !== null));

            if ($response->successful()) {
                $message->update([
                    'relayed_to_chat_app' => true,
                    'relay_status' => 'success',
                    'relayed_at' => now(),
                    'relay_error' => null,
                ]);

                Log::info('Inbound relay success', [
                    'inbound_message_id' => $message->id,
                    'status_code' => $response->status(),
                ]);

                return true;
            }

            $error = sprintf('HTTP %d: %s', $response->status(), substr((string) $response->body(), 0, 300));

            $message->update([
                'relay_status' => 'failed',
                'relay_error' => $error,
            ]);

            Log::warning('Inbound relay failure', [
                'inbound_message_id' => $message->id,
                'status_code' => $response->status(),
                'error' => $error,
            ]);

            return false;
        } catch (Throwable $e) {
            $message->update([
                'relay_status' => 'failed',
                'relay_error' => $e->getMessage(),
            ]);

            Log::error('Inbound relay exception', [
                'inbound_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
