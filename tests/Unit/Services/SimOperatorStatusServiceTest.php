<?php

namespace Tests\Unit\Services;

use App\Events\SimOperatorStatusChanged;
use App\Services\SimOperatorStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
        Event::fake();

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $this->service->setOperatorStatus($sim, 'paused', $company);

        $this->assertSame('paused', $sim->fresh()->operator_status);

        Event::assertDispatched(SimOperatorStatusChanged::class, function (SimOperatorStatusChanged $event) use ($sim, $company) {
            return $event->simId === (int) $sim->id
                && $event->companyId === (int) $company->id
                && $event->oldStatus === 'active'
                && $event->newStatus === 'paused';
        });
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
        Event::fake();

        $companyA = $this->createCompany(['code' => 'CMPA']);
        $companyB = $this->createCompany(['code' => 'CMPB']);
        $sim = $this->createSim($companyA);

        try {
            $this->service->setOperatorStatus($sim, 'paused', $companyB);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('SIM does not belong to authenticated company', $e->getMessage());
        }

        Event::assertNotDispatched(SimOperatorStatusChanged::class);
    }

    /** @test */
    public function same_status_is_a_noop(): void
    {
        Event::fake();

        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $this->service->setOperatorStatus($sim, 'active', $company);

        $this->assertSame('active', $sim->fresh()->operator_status);
        Event::assertNotDispatched(SimOperatorStatusChanged::class);
    }
}
