<?php

namespace Tests\Support;

use App\Models\ApiClient;
use App\Models\Company;
use App\Models\CustomerSimAssignment;
use App\Models\Sim;
use Illuminate\Support\Str;

trait CreatesGatewayEntities
{
    protected function createCompany(array $overrides = []): Company
    {
        static $companySequence = 1;

        $company = Company::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Company '.$companySequence,
            'code' => 'CMP'.str_pad((string) $companySequence, 4, '0', STR_PAD_LEFT),
            'status' => 'active',
            'timezone' => 'Asia/Manila',
        ], $overrides));

        $companySequence++;

        return $company;
    }

    protected function createSim(Company $company, array $overrides = []): Sim
    {
        static $phoneSequence = 170000000;
        $phoneSequence++;

        return Sim::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'phone_number' => '09'.$phoneSequence,
            'status' => 'active',
            'mode' => 'NORMAL',
            'operator_status' => 'active',
            'accept_new_assignments' => true,
            'disabled_for_new_assignments' => false,
            'daily_limit' => 4000,
            'recommended_limit' => 3000,
            'burst_limit' => 30,
            'burst_interval_min_seconds' => 2,
            'burst_interval_max_seconds' => 3,
            'normal_interval_min_seconds' => 5,
            'normal_interval_max_seconds' => 8,
            'cooldown_min_seconds' => 60,
            'cooldown_max_seconds' => 120,
            'burst_count' => 0,
        ], $overrides));
    }

    /**
     * @return array{0: \App\Models\ApiClient, 1: string}
     */
    protected function createApiClient(Company $company, array $overrides = []): array
    {
        static $keySequence = 1;

        $secret = (string) ($overrides['plain_secret'] ?? 'secret-'.$keySequence);
        unset($overrides['plain_secret']);

        $client = ApiClient::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'name' => 'Client '.$keySequence,
            'api_key' => 'key-'.$keySequence.'-'.uniqid('', false),
            'api_secret' => $secret,
            'status' => 'active',
        ], $overrides));

        $keySequence++;

        return [$client, $secret];
    }

    protected function createAssignment(Company $company, Sim $sim, string $customerPhone, array $overrides = []): CustomerSimAssignment
    {
        return CustomerSimAssignment::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'customer_phone' => $customerPhone,
            'sim_id' => $sim->id,
            'status' => 'active',
            'assigned_at' => now(),
            'last_used_at' => now(),
            'last_outbound_at' => now(),
            'has_replied' => false,
            'safe_to_migrate' => false,
            'migration_locked' => false,
        ], $overrides));
    }
}
