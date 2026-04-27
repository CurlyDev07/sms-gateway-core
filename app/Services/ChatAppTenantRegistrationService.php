<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Models\Company;
use App\Models\CompanyChatAppIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatAppTenantRegistrationService
{
    /**
     * Provision or return an existing gateway tenant registration.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $existing = CompanyChatAppIntegration::query()
                ->where('chatapp_company_id', (string) $data['chatapp_company_id'])
                ->with(['company.apiClients'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'created' => false,
                    'company' => $existing->company,
                    'integration' => $existing,
                    'outbound_credentials' => null,
                    'inbound_credentials' => null,
                ];
            }

            $company = Company::query()->firstOrCreate(
                ['code' => (string) $data['company_code']],
                [
                    'name' => (string) $data['company_name'],
                    'status' => 'active',
                    'timezone' => (string) ($data['timezone'] ?? 'Asia/Manila'),
                ]
            );

            $company->forceFill([
                'name' => (string) $data['company_name'],
                'status' => 'active',
                'timezone' => (string) ($data['timezone'] ?? $company->timezone ?? 'Asia/Manila'),
            ])->save();

            $outboundSecret = $this->generateSecret();
            $apiClient = ApiClient::query()->create([
                'company_id' => $company->id,
                'name' => 'ChatApp '.$data['chatapp_company_id'],
                'api_key' => $this->generateUniqueApiKey(),
                'api_secret' => $outboundSecret,
                'status' => 'active',
            ]);

            $inboundSecret = $this->generateSecret();
            $integration = new CompanyChatAppIntegration([
                'company_id' => $company->id,
                'chatapp_company_id' => (string) $data['chatapp_company_id'],
                'chatapp_company_uuid' => $data['chatapp_company_uuid'] ?? null,
                'chatapp_inbound_url' => (string) $data['chatapp_inbound_url'],
                'chatapp_tenant_key' => (string) $data['chatapp_tenant_key'],
                'status' => 'active',
                'outbound_rotated_at' => now(),
                'inbound_rotated_at' => now(),
            ]);
            $integration->setInboundSecret($inboundSecret);
            $integration->save();

            return [
                'created' => true,
                'company' => $company->fresh(),
                'integration' => $integration->fresh(),
                'outbound_credentials' => [
                    'user_id' => $apiClient->api_key,
                    'api_key' => $outboundSecret,
                ],
                'inbound_credentials' => [
                    'tenant_key' => $integration->chatapp_tenant_key,
                    'secret' => $inboundSecret,
                ],
            ];
        });
    }

    /**
     * Rotate active outbound credentials for a ChatApp tenant.
     *
     * @param \App\Models\CompanyChatAppIntegration $integration
     * @return array<string, string>
     */
    public function rotateOutbound(CompanyChatAppIntegration $integration): array
    {
        return DB::transaction(function () use ($integration) {
            $integration = CompanyChatAppIntegration::query()
                ->whereKey($integration->id)
                ->lockForUpdate()
                ->firstOrFail();

            ApiClient::query()
                ->where('company_id', $integration->company_id)
                ->where('name', 'like', 'ChatApp %')
                ->update(['status' => 'disabled']);

            $secret = $this->generateSecret();
            $client = ApiClient::query()->create([
                'company_id' => $integration->company_id,
                'name' => 'ChatApp '.$integration->chatapp_company_id,
                'api_key' => $this->generateUniqueApiKey(),
                'api_secret' => $secret,
                'status' => 'active',
            ]);

            $integration->forceFill(['outbound_rotated_at' => now()])->save();

            return [
                'user_id' => $client->api_key,
                'api_key' => $secret,
            ];
        });
    }

    /**
     * Rotate inbound signing secret for a ChatApp tenant.
     *
     * @param \App\Models\CompanyChatAppIntegration $integration
     * @return array<string, string>
     */
    public function rotateInbound(CompanyChatAppIntegration $integration): array
    {
        return DB::transaction(function () use ($integration) {
            $integration = CompanyChatAppIntegration::query()
                ->whereKey($integration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $secret = $this->generateSecret();
            $integration->setInboundSecret($secret);
            $integration->inbound_rotated_at = now();
            $integration->save();

            return [
                'tenant_key' => $integration->chatapp_tenant_key,
                'secret' => $secret,
            ];
        });
    }

    /**
     * Generate a random API or signing secret.
     *
     * @return string
     */
    protected function generateSecret(): string
    {
        return Str::random(64);
    }

    /**
     * Generate a unique outbound UserID/API key.
     *
     * @return string
     */
    protected function generateUniqueApiKey(): string
    {
        do {
            $key = 'chatapp_'.Str::random(40);
        } while (ApiClient::query()->where('api_key', $key)->exists());

        return $key;
    }
}
