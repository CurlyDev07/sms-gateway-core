<?php

namespace Tests\Feature\Commands;

use App\Models\GatewaySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class SyncRuntimeReadinessCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sms.python_api_url', 'http://python-engine.test');
        config()->set('sms.python_api_discover_path', '/modems/discover');
    }

    /** @test */
    public function it_syncs_disable_flags_from_runtime_readiness_with_company_filter(): void
    {
        $company = $this->createCompany(['code' => 'SYNC-A']);
        $otherCompany = $this->createCompany(['code' => 'SYNC-B']);

        $readySim = $this->createSim($company, [
            'imsi' => '515020241752004',
            'disabled_for_new_assignments' => true,
        ]);
        $notReadySim = $this->createSim($company, [
            'imsi' => '515020241752005',
            'disabled_for_new_assignments' => false,
        ]);
        $otherSim = $this->createSim($otherCompany, [
            'imsi' => '515039219149367',
            'disabled_for_new_assignments' => false,
        ]);

        Http::fake([
            'http://python-engine.test/modems/discover' => Http::response([
                'modems' => [
                    [
                        'sim_id' => '515020241752004',
                        'effective_send_ready' => true,
                    ],
                    [
                        'sim_id' => '515020241752005',
                        'effective_send_ready' => false,
                    ],
                    [
                        'sim_id' => '515039219149367',
                        'effective_send_ready' => false,
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('gateway:sync-runtime-readiness', [
            '--company-id' => $company->id,
        ])
            ->expectsOutput('Runtime readiness sync completed.')
            ->expectsOutput('Company filter: '.$company->id)
            ->expectsOutput('SIMs scanned: 2')
            ->assertExitCode(0);

        $this->assertFalse($readySim->fresh()->disabled_for_new_assignments);
        $this->assertTrue($notReadySim->fresh()->disabled_for_new_assignments);
        $this->assertFalse($otherSim->fresh()->disabled_for_new_assignments);
    }

    /** @test */
    public function it_keeps_single_company_sim_enabled_when_runtime_not_ready_guardrail_applies(): void
    {
        $company = $this->createCompany(['code' => 'SYNC-SINGLE']);
        $sim = $this->createSim($company, [
            'imsi' => '515020241752004',
            'disabled_for_new_assignments' => false,
        ]);

        Http::fake([
            'http://python-engine.test/modems/discover' => Http::response([
                'modems' => [
                    [
                        'sim_id' => '515020241752004',
                        'effective_send_ready' => false,
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('gateway:sync-runtime-readiness')
            ->expectsOutput('Runtime readiness sync completed.')
            ->expectsOutput('SIMs scanned: 1')
            ->expectsOutput('SIMs disabled: 0')
            ->expectsOutput('Guardrail skipped: 1')
            ->assertExitCode(0);

        $this->assertFalse($sim->fresh()->disabled_for_new_assignments);
    }

    /** @test */
    public function it_uses_slot_name_fallback_when_runtime_discovery_returns_non_imsi_identity(): void
    {
        $company = $this->createCompany(['code' => 'SYNC-SLOT']);

        $slotFallbackSim = $this->createSim($company, [
            'imsi' => '515020241752004',
            'slot_name' => '3-7.4.2',
            'disabled_for_new_assignments' => false,
        ]);

        $readyImsiSim = $this->createSim($company, [
            'imsi' => '515020241752005',
            'slot_name' => '3-7.4.4',
            'disabled_for_new_assignments' => false,
        ]);

        Http::fake([
            'http://python-engine.test/modems/discover' => Http::response([
                'modems' => [
                    [
                        'sim_id' => '3-7.4.2',
                        'effective_send_ready' => true,
                    ],
                    [
                        'sim_id' => '515020241752005',
                        'effective_send_ready' => true,
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('gateway:sync-runtime-readiness', [
            '--company-id' => $company->id,
        ])
            ->expectsOutput('Runtime readiness sync completed.')
            ->expectsOutput('Company filter: '.$company->id)
            ->expectsOutput('SIMs scanned: 2')
            ->expectsOutput('SIMs disabled: 0')
            ->assertExitCode(0);

        $this->assertFalse($slotFallbackSim->fresh()->disabled_for_new_assignments);
        $this->assertFalse($readyImsiSim->fresh()->disabled_for_new_assignments);
    }

    /** @test */
    public function it_respects_not_ready_streak_threshold_before_disabling_assignment_pool(): void
    {
        $company = $this->createCompany(['code' => 'SYNC-STREAK']);

        $target = $this->createSim($company, [
            'imsi' => '515020241752004',
            'disabled_for_new_assignments' => false,
        ]);

        $healthy = $this->createSim($company, [
            'imsi' => '515020241752005',
            'disabled_for_new_assignments' => false,
        ]);

        GatewaySetting::query()->updateOrCreate(
            ['key' => 'runtime_sync_disable_after_not_ready_checks'],
            ['value' => '3']
        );

        Http::fake([
            'http://python-engine.test/modems/discover' => Http::response([
                'modems' => [
                    [
                        'sim_id' => '515020241752004',
                        'effective_send_ready' => false,
                    ],
                    [
                        'sim_id' => '515020241752005',
                        'effective_send_ready' => true,
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('gateway:sync-runtime-readiness', ['--company-id' => $company->id])->assertExitCode(0);
        $this->assertFalse($target->fresh()->disabled_for_new_assignments);

        $this->artisan('gateway:sync-runtime-readiness', ['--company-id' => $company->id])->assertExitCode(0);
        $this->assertFalse($target->fresh()->disabled_for_new_assignments);

        $this->artisan('gateway:sync-runtime-readiness', ['--company-id' => $company->id])->assertExitCode(0);
        $this->assertTrue($target->fresh()->disabled_for_new_assignments);
        $this->assertFalse($healthy->fresh()->disabled_for_new_assignments);
    }
}
