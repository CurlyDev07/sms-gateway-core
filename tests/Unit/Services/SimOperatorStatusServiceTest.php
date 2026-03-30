<?php

namespace Tests\Unit\Services;

use App\Services\SimOperatorStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SimOperatorStatusServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    private SimOperatorStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SimOperatorStatusService::class);
    }

    /** @test */
    public function validate_status_accepts_only_active_paused_or_blocked(): void
    {
        $this->assertTrue($this->service->validateStatus('active'));
        $this->assertTrue($this->service->validateStatus('paused'));
        $this->assertTrue($this->service->validateStatus('blocked'));
        $this->assertFalse($this->service->validateStatus('disabled'));
    }

    /** @test */
    public function it_updates_operator_status_for_same_company_sim(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $this->service->setOperatorStatus($sim, 'paused', $company);

        $this->assertSame('paused', $sim->fresh()->operator_status);
    }

    /** @test */
    public function it_throws_for_invalid_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $company = $this->createCompany();
        $sim = $this->createSim($company);

        $this->service->setOperatorStatus($sim, 'invalid', $company);
    }

    /** @test */
    public function it_throws_when_sim_does_not_belong_to_company(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $companyA = $this->createCompany(['code' => 'CMPA']);
        $companyB = $this->createCompany(['code' => 'CMPB']);
        $sim = $this->createSim($companyA);

        $this->service->setOperatorStatus($sim, 'paused', $companyB);
    }

    /** @test */
    public function same_status_is_a_noop(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $this->service->setOperatorStatus($sim, 'active', $company);

        $this->assertSame('active', $sim->fresh()->operator_status);
    }
}
