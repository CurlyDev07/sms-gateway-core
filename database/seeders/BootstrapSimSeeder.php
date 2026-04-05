<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Modem;
use App\Models\Sim;
use Illuminate\Database\Seeder;

class BootstrapSimSeeder extends Seeder
{
    /**
     * Create one active SIM linked to the bootstrap company for dev use.
     *
     * IMSI: seeded with an obvious placeholder (15 zeros).
     * In production, IMSI must match the physical SIM's IMSI so the
     * Python engine can route correctly.  Set BOOTSTRAP_SIM_IMSI in .env
     * if you have a real modem attached during local development.
     *
     * phone_number is unique in the schema; idempotency is achieved by
     * keying on phone_number via updateOrCreate.
     *
     * accept_new_assignments is explicitly set to true — the migration
     * backfill only runs for rows that existed before the column was added,
     * and the column's DB default is false.
     */
    public function run(): void
    {
        $company = Company::where('code', 'bootstrap')->firstOrFail();

        // Modem is optional — attach if the placeholder modem exists.
        $modem = Modem::where('device_name', 'Bootstrap Modem (placeholder)')->first();

        $phone = env('BOOTSTRAP_SIM_PHONE', '+639000000000');

        // 15-character string of zeros is a recognisably fake IMSI.
        // Overridable via BOOTSTRAP_SIM_IMSI for devs with real hardware.
        $imsi = env('BOOTSTRAP_SIM_IMSI', '000000000000000');

        $sim = Sim::updateOrCreate(
            ['phone_number' => $phone],
            [
                'company_id'               => $company->id,
                'modem_id'                 => $modem?->id,
                'slot_name'                => 'slot-0',
                'carrier'                  => 'Bootstrap Carrier',
                'sim_label'                => 'bootstrap-sim-01',
                'imsi'                     => $imsi,
                'status'                   => 'active',
                'mode'                     => 'NORMAL',
                'operator_status'          => 'active',
                'accept_new_assignments'   => true,
                'disabled_for_new_assignments' => false,
            ]
        );

        $this->command->info("[BootstrapSimSeeder] SIM '{$sim->phone_number}' (id={$sim->id}, imsi={$sim->imsi}) ready.");

        if ($imsi === '000000000000000') {
            $this->command->warn(
                '[BootstrapSimSeeder] IMSI is placeholder (000000000000000). ' .
                'Set BOOTSTRAP_SIM_IMSI in .env if a real modem is attached.'
            );
        }
    }
}
