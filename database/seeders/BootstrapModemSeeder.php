<?php

namespace Database\Seeders;

use App\Models\Modem;
use Illuminate\Database\Seeder;

class BootstrapModemSeeder extends Seeder
{
    /**
     * Create one placeholder modem record for bootstrap / dev use.
     *
     * All hardware fields are nullable in the schema; this seeder seeds
     * just a recognisable device_name so FK references from SIMs can
     * resolve. Status is 'offline' — this is intentionally a placeholder,
     * not a real hardware device.
     *
     * Idempotent: uses firstOrCreate on device_name.
     */
    public function run(): void
    {
        $modem = Modem::firstOrCreate(
            ['device_name' => 'Bootstrap Modem (placeholder)'],
            [
                'vendor'  => null,
                'model'   => null,
                'chipset' => null,
                'status'  => 'offline',
            ]
        );

        $this->command->info("[BootstrapModemSeeder] Modem '{$modem->device_name}' (id={$modem->id}) ready.");
    }
}
