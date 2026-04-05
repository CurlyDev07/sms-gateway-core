<?php

namespace Database\Seeders;

use App\Models\ApiClient;
use App\Models\Company;
use Illuminate\Database\Seeder;

class BootstrapApiClientSeeder extends Seeder
{
    /**
     * Create one active API client linked to the bootstrap company.
     *
     * api_secret handling: the ApiClient model's setApiSecretAttribute mutator
     * hashes any plain-text value via Hash::make() on every assignment.
     * To preserve idempotency without re-hashing on every seed run, this
     * seeder uses firstOrCreate (not updateOrCreate) keyed on api_key.
     * The secret is therefore only written once; subsequent runs return the
     * existing row untouched.
     *
     * Override credentials via .env:
     *   BOOTSTRAP_API_KEY=...
     *   BOOTSTRAP_API_SECRET=...
     *
     * Never put real production secrets here or in .env.example.
     */
    public function run(): void
    {
        $company = Company::where('code', 'bootstrap')->firstOrFail();

        $apiKey    = env('BOOTSTRAP_API_KEY', 'bootstrap-api-key');
        $apiSecret = env('BOOTSTRAP_API_SECRET', 'bootstrap-secret');

        $client = ApiClient::firstOrCreate(
            ['api_key' => $apiKey],
            [
                'company_id' => $company->id,
                'name'       => 'Bootstrap API Client',
                'api_secret' => $apiSecret, // mutator hashes this on first create only
                'status'     => 'active',
            ]
        );

        $this->command->info("[BootstrapApiClientSeeder] ApiClient '{$client->name}' (id={$client->id}, key={$client->api_key}) ready.");

        if ($apiKey === 'bootstrap-api-key') {
            $this->command->warn(
                '[BootstrapApiClientSeeder] Using default bootstrap API key. ' .
                'Set BOOTSTRAP_API_KEY and BOOTSTRAP_API_SECRET in .env for local testing.'
            );
        }
    }
}
