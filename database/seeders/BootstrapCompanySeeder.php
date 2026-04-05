<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class BootstrapCompanySeeder extends Seeder
{
    /**
     * Create one default active company for bootstrap / dev use.
     *
     * Idempotent: uses updateOrCreate on the unique `code` column.
     * Safe to run multiple times — will not create duplicates.
     */
    public function run(): void
    {
        $name = env('BOOTSTRAP_COMPANY_NAME', 'Bootstrap Company');

        $company = Company::updateOrCreate(
            ['code' => 'bootstrap'],
            [
                'name'     => $name,
                'status'   => 'active',
                'timezone' => 'Asia/Manila',
            ]
        );

        $this->command->info("[BootstrapCompanySeeder] Company '{$company->name}' (id={$company->id}) ready.");
    }
}
