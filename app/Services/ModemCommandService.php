<?php

namespace App\Services;

use App\Models\Sim;
use Illuminate\Support\Facades\Log;

class ModemCommandService
{
    /**
     * Send SMS via modem transport.
     *
     * @param \App\Models\Sim $sim
     * @param string $customerPhone
     * @param string $message
     * @return array<string, mixed>
     */
    public function sendSms(Sim $sim, string $customerPhone, string $message): array
    {
        Log::warning('ModemCommandService::sendSms stub called; modem transport not yet implemented', [
            'sim_id' => $sim->id,
            'company_id' => $sim->company_id,
            'customer_phone' => $customerPhone,
        ]);

        return [
            'success' => false,
            'error' => 'Modem transport not implemented',
            'provider_message_id' => null,
        ];
    }
}
