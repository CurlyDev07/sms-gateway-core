<?php

namespace Tests\Feature\Smoke;

use App\Console\Kernel as AppConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;
use Tests\TestCase;

class Phase0SmokeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function sim_operator_control_columns_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('sims'));

        $this->assertTrue(Schema::hasColumns('sims', [
            'operator_status',
            'accept_new_assignments',
            'disabled_for_new_assignments',
            'last_success_at',
        ]));
    }

    /** @test */
    public function phase_zero_commands_are_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('gateway:check-sim-health', $commands);
        $this->assertArrayHasKey('gateway:set-sim-operator-status', $commands);
        $this->assertArrayHasKey('gateway:enable-sim-new-assignments', $commands);
        $this->assertArrayHasKey('gateway:disable-sim-new-assignments', $commands);
        $this->assertArrayHasKey('gateway:sync-runtime-readiness', $commands);
        $this->assertArrayHasKey('gateway:supervise-sim-workers', $commands);
    }

    /** @test */
    public function outbound_send_route_resolves_to_gateway_outbound_controller_store(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/messages/send', 'POST'));

        $this->assertSame('App\\Http\\Controllers\\GatewayOutboundController@store', $route->getActionName());
        $this->assertContains('api.client', $route->middleware());
        $this->assertContains('tenant.resolve', $route->middleware());
    }

    /** @test */
    public function scheduler_contains_sim_health_check_every_five_minutes(): void
    {
        $kernel = $this->app->make(AppConsoleKernel::class);
        $schedule = new Schedule($this->app);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        $matched = collect($schedule->events())->contains(function ($event) {
            return str_contains($event->command, 'gateway:check-sim-health')
                && $event->expression === '*/5 * * * *';
        });

        $this->assertTrue($matched, 'gateway:check-sim-health was not scheduled every five minutes.');
    }

    /** @test */
    public function scheduler_contains_retry_scheduler_every_five_minutes(): void
    {
        $kernel = $this->app->make(AppConsoleKernel::class);
        $schedule = new Schedule($this->app);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        $matched = collect($schedule->events())->contains(function ($event) {
            return str_contains($event->command, 'gateway:retry-scheduler')
                && $event->expression === '*/5 * * * *';
        });

        $this->assertTrue($matched, 'gateway:retry-scheduler was not scheduled every five minutes.');
    }

    /** @test */
    public function scheduler_contains_runtime_readiness_sync_every_minute(): void
    {
        $kernel = $this->app->make(AppConsoleKernel::class);
        $schedule = new Schedule($this->app);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        $matched = collect($schedule->events())->contains(function ($event) {
            return str_contains($event->command, 'gateway:sync-runtime-readiness')
                && $event->expression === '* * * * *';
        });

        $this->assertTrue($matched, 'gateway:sync-runtime-readiness was not scheduled every minute.');
    }
}
