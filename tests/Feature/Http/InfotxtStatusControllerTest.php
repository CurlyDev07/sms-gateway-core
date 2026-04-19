<?php

namespace Tests\Feature\Http;

use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class InfotxtStatusControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    /** @test */
    public function it_returns_queued_code_for_pending_like_states(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $message = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09170000001',
            'message' => 'queued status test',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'queued',
        ]);

        $this->get('/api/v2/status.php?smsid='.$message->id)
            ->assertOk()
            ->assertJson([
                'status' => '0',
                'smsid' => (string) $message->id,
            ]);
    }

    /** @test */
    public function it_returns_sent_code_for_sent_state(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $message = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09170000002',
            'message' => 'sent status test',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'sent',
        ]);

        $this->get('/api/v2/status.php?smsid='.$message->id)
            ->assertOk()
            ->assertJson([
                'status' => '1',
                'smsid' => (string) $message->id,
            ]);
    }

    /** @test */
    public function it_returns_failed_code_for_failed_or_missing_state(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $message = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09170000003',
            'message' => 'failed status test',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'failed',
        ]);

        $this->get('/api/v2/status.php?smsid='.$message->id)
            ->assertOk()
            ->assertJson([
                'status' => '2',
                'smsid' => (string) $message->id,
            ]);

        $this->get('/api/v2/status.php?smsid=999999')
            ->assertOk()
            ->assertJson([
                'status' => '2',
                'smsid' => '999999',
                'message' => 'not_found',
            ]);
    }

    /** @test */
    public function it_supports_legacy_non_api_path(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $message = OutboundMessage::query()->create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => '09170000004',
            'message' => 'legacy path test',
            'message_type' => 'CHAT',
            'priority' => 100,
            'status' => 'sent',
        ]);

        $this->get('/v2/status.php?smsid='.$message->id)
            ->assertOk()
            ->assertJson([
                'status' => '1',
                'smsid' => (string) $message->id,
            ]);
    }
}
