<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\CompanyChatAppIntegration;
use App\Services\ChatAppTenantRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatAppTenantController extends Controller
{
    private ChatAppTenantRegistrationService $registrationService;

    public function __construct(ChatAppTenantRegistrationService $registrationService)
    {
        $this->registrationService = $registrationService;
    }

    /**
     * Provision a gateway tenant for an approved ChatApp company.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chatapp_company_id' => ['required', 'string', 'max:191'],
            'chatapp_company_uuid' => ['nullable', 'uuid'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_code' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'chatapp_inbound_url' => ['required', 'url', 'max:2048'],
            'chatapp_tenant_key' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->registrationService->provision($data);

        return response()->json($this->registrationPayload(
            $result['integration'],
            $result['outbound_credentials'],
            $result['inbound_credentials'],
            (bool) $result['created']
        ), $result['created'] ? 201 : 200);
    }

    /**
     * Show a gateway tenant registration without returning secrets.
     *
     * @param string $chatappCompanyId
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(string $chatappCompanyId): JsonResponse
    {
        $integration = $this->findIntegration($chatappCompanyId);

        return response()->json($this->registrationPayload($integration));
    }

    /**
     * Rotate outbound credentials for a ChatApp tenant.
     *
     * @param string $chatappCompanyId
     * @return \Illuminate\Http\JsonResponse
     */
    public function rotateOutbound(string $chatappCompanyId): JsonResponse
    {
        $integration = $this->findIntegration($chatappCompanyId);
        $credentials = $this->registrationService->rotateOutbound($integration);

        return response()->json([
            'ok' => true,
            'outbound_credentials' => $credentials,
        ]);
    }

    /**
     * Rotate inbound relay signing credentials for a ChatApp tenant.
     *
     * @param string $chatappCompanyId
     * @return \Illuminate\Http\JsonResponse
     */
    public function rotateInbound(string $chatappCompanyId): JsonResponse
    {
        $integration = $this->findIntegration($chatappCompanyId);
        $credentials = $this->registrationService->rotateInbound($integration);

        return response()->json([
            'ok' => true,
            'inbound_credentials' => $credentials,
        ]);
    }

    /**
     * Suspend, disable, or reactivate a gateway tenant.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $chatappCompanyId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, string $chatappCompanyId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'disabled'])],
        ]);

        $integration = $this->findIntegration($chatappCompanyId);
        $company = $integration->company;
        $company->status = (string) $data['status'];
        $company->save();

        if ($data['status'] === 'disabled') {
            ApiClient::query()
                ->where('company_id', $company->id)
                ->update(['status' => 'disabled']);

            $integration->status = 'disabled';
            $integration->save();
        } elseif ($data['status'] === 'active') {
            $integration->status = 'active';
            $integration->save();
        }

        return response()->json($this->registrationPayload($integration->fresh(['company'])));
    }

    /**
     * Load an integration by ChatApp company id.
     *
     * @param string $chatappCompanyId
     * @return \App\Models\CompanyChatAppIntegration
     */
    protected function findIntegration(string $chatappCompanyId): CompanyChatAppIntegration
    {
        return CompanyChatAppIntegration::query()
            ->where('chatapp_company_id', $chatappCompanyId)
            ->with('company')
            ->firstOrFail();
    }

    /**
     * Build the public registration response.
     *
     * @param \App\Models\CompanyChatAppIntegration $integration
     * @param array<string, string>|null $outboundCredentials
     * @param array<string, string>|null $inboundCredentials
     * @param bool|null $created
     * @return array<string, mixed>
     */
    protected function registrationPayload(
        CompanyChatAppIntegration $integration,
        ?array $outboundCredentials = null,
        ?array $inboundCredentials = null,
        ?bool $created = null
    ): array {
        $integration->loadMissing('company');

        $payload = [
            'ok' => true,
            'gateway_company' => [
                'id' => (int) $integration->company->id,
                'uuid' => (string) $integration->company->uuid,
                'code' => (string) $integration->company->code,
                'name' => (string) $integration->company->name,
                'status' => (string) $integration->company->status,
            ],
            'registration' => [
                'chatapp_company_id' => (string) $integration->chatapp_company_id,
                'chatapp_company_uuid' => $integration->chatapp_company_uuid,
                'chatapp_tenant_key' => (string) $integration->chatapp_tenant_key,
                'chatapp_inbound_url' => (string) $integration->chatapp_inbound_url,
                'status' => (string) $integration->status,
                'outbound_rotated_at' => optional($integration->outbound_rotated_at)->toIso8601String(),
                'inbound_rotated_at' => optional($integration->inbound_rotated_at)->toIso8601String(),
            ],
        ];

        if ($created !== null) {
            $payload['created'] = $created;
        }

        if ($outboundCredentials !== null) {
            $payload['outbound_credentials'] = $outboundCredentials;
        }

        if ($inboundCredentials !== null) {
            $payload['inbound_credentials'] = $inboundCredentials;
        }

        return $payload;
    }
}
