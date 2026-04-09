<?php

namespace Tests\Feature\Web;

use App\Models\OperatorAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class DashboardAuditLogTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    public function test_audit_dashboard_page_renders_for_authenticated_operator(): void
    {
        $company = $this->createCompany();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $this->actingAs($user)
            ->get('/dashboard/audit')
            ->assertOk()
            ->assertSee('Audit Log')
            ->assertSee('/dashboard/api/audit-logs');
    }

    public function test_support_can_view_tenant_local_audit_log_entries_only(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $support = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'support',
        ]);

        $ownerB = User::factory()->create([
            'company_id' => $companyB->id,
            'operator_role' => 'owner',
        ]);

        OperatorAuditLog::query()->create([
            'company_id' => $companyA->id,
            'actor_user_id' => $support->id,
            'action' => 'assignment.set',
            'target_type' => 'customer_sim_assignment',
            'target_id' => 1001,
            'metadata' => ['source' => 'test-a'],
        ]);

        OperatorAuditLog::query()->create([
            'company_id' => $companyB->id,
            'actor_user_id' => $ownerB->id,
            'action' => 'sim.status_updated',
            'target_type' => 'sim',
            'target_id' => 2002,
            'metadata' => ['source' => 'test-b'],
        ]);

        $this->actingAs($support)
            ->getJson('/dashboard/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.company_id', $companyA->id)
            ->assertJsonPath('logs.0.actor_user_id', $support->id)
            ->assertJsonPath('logs.0.action', 'assignment.set');
    }

    public function test_support_can_filter_audit_logs_by_action_and_actor_user_id(): void
    {
        $company = $this->createCompany();
        $support = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);
        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
        ]);

        OperatorAuditLog::query()->create([
            'company_id' => $company->id,
            'actor_user_id' => $support->id,
            'action' => 'sim.status_updated',
            'target_type' => 'sim',
            'target_id' => 11,
            'metadata' => ['source' => 'match'],
        ]);

        OperatorAuditLog::query()->create([
            'company_id' => $company->id,
            'actor_user_id' => $support->id,
            'action' => 'assignment.set',
            'target_type' => 'customer_sim_assignment',
            'target_id' => 12,
            'metadata' => ['source' => 'different-action'],
        ]);

        OperatorAuditLog::query()->create([
            'company_id' => $company->id,
            'actor_user_id' => $owner->id,
            'action' => 'sim.status_updated',
            'target_type' => 'sim',
            'target_id' => 13,
            'metadata' => ['source' => 'different-actor'],
        ]);

        $this->actingAs($support)
            ->getJson('/dashboard/api/audit-logs?action=sim.status_updated&actor_user_id='.$support->id)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.action', 'sim.status_updated')
            ->assertJsonPath('logs.0.actor_user_id', $support->id)
            ->assertJsonPath('logs.0.target_id', 11);
    }

    public function test_support_can_search_audit_logs_by_action_or_target_type_within_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $support = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'support',
        ]);
        $otherTenantUser = User::factory()->create([
            'company_id' => $companyB->id,
            'operator_role' => 'owner',
        ]);

        OperatorAuditLog::query()->create([
            'company_id' => $companyA->id,
            'actor_user_id' => $support->id,
            'action' => 'assignment.set',
            'target_type' => 'customer_sim_assignment',
            'target_id' => 901,
            'metadata' => [],
        ]);

        $targetMatch = OperatorAuditLog::query()->create([
            'company_id' => $companyA->id,
            'actor_user_id' => $support->id,
            'action' => 'migration.bulk',
            'target_type' => 'sim',
            'target_id' => 902,
            'metadata' => [],
        ]);

        OperatorAuditLog::query()->create([
            'company_id' => $companyB->id,
            'actor_user_id' => $otherTenantUser->id,
            'action' => 'migration.bulk',
            'target_type' => 'sim',
            'target_id' => 903,
            'metadata' => [],
        ]);

        $this->actingAs($support)
            ->getJson('/dashboard/api/audit-logs?search=migration&action=migration.bulk')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.id', $targetMatch->id)
            ->assertJsonPath('logs.0.company_id', $companyA->id)
            ->assertJsonPath('logs.0.action', 'migration.bulk')
            ->assertJsonPath('logs.0.target_type', 'sim');
    }

    public function test_support_can_filter_audit_logs_by_inclusive_date_range(): void
    {
        $company = $this->createCompany();
        $support = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $logOne = OperatorAuditLog::query()->create([
            'company_id' => $company->id,
            'actor_user_id' => $support->id,
            'action' => 'assignment.set',
            'target_type' => 'customer_sim_assignment',
            'target_id' => 1001,
            'metadata' => [],
        ]);

        $logTwo = OperatorAuditLog::query()->create([
            'company_id' => $company->id,
            'actor_user_id' => $support->id,
            'action' => 'assignment.mark_safe',
            'target_type' => 'customer_sim_assignment',
            'target_id' => 1002,
            'metadata' => [],
        ]);

        $logThree = OperatorAuditLog::query()->create([
            'company_id' => $company->id,
            'actor_user_id' => $support->id,
            'action' => 'migration.bulk',
            'target_type' => 'sim',
            'target_id' => 1003,
            'metadata' => [],
        ]);

        DB::table('operator_audit_logs')->where('id', $logOne->id)->update([
            'created_at' => '2026-04-01 08:00:00',
            'updated_at' => '2026-04-01 08:00:00',
        ]);
        DB::table('operator_audit_logs')->where('id', $logTwo->id)->update([
            'created_at' => '2026-04-05 12:00:00',
            'updated_at' => '2026-04-05 12:00:00',
        ]);
        DB::table('operator_audit_logs')->where('id', $logThree->id)->update([
            'created_at' => '2026-04-10 18:00:00',
            'updated_at' => '2026-04-10 18:00:00',
        ]);

        $this->actingAs($support)
            ->getJson('/dashboard/api/audit-logs?date_from=2026-04-05&date_to=2026-04-05')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.id', $logTwo->id)
            ->assertJsonPath('logs.0.target_id', 1002);
    }

    public function test_audit_log_filters_validate_date_range_order(): void
    {
        $company = $this->createCompany();
        $support = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $this->actingAs($support)
            ->getJson('/dashboard/api/audit-logs?date_from=2026-04-09&date_to=2026-04-01')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('details.date_to.0', 'date_to must be on or after date_from.');
    }

    public function test_audit_log_filters_reject_search_longer_than_255_chars(): void
    {
        $company = $this->createCompany();
        $support = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $this->actingAs($support)
            ->getJson('/dashboard/api/audit-logs?search='.str_repeat('x', 256))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure([
                'details' => ['search'],
            ]);
    }

    public function test_operator_creation_writes_audit_log_entry(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators', [
                'name' => 'Audit Created Operator',
                'email' => 'audit-created-operator@example.com',
                'operator_role' => 'support',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('operator_audit_logs', [
            'company_id' => $company->id,
            'actor_user_id' => $owner->id,
            'action' => 'operator.created',
            'target_type' => 'user',
        ]);
    }

    public function test_sim_status_change_writes_audit_log_entry(): void
    {
        $company = $this->createCompany();
        $sim = $this->createSim($company, ['operator_status' => 'active']);

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/admin/sim/'.$sim->id.'/status', [
                'operator_status' => 'paused',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sim.operator_status', 'paused');

        $this->assertDatabaseHas('operator_audit_logs', [
            'company_id' => $company->id,
            'actor_user_id' => $owner->id,
            'action' => 'sim.status_updated',
            'target_type' => 'sim',
            'target_id' => $sim->id,
        ]);
    }

    public function test_assignment_set_and_migrate_bulk_write_audit_entries(): void
    {
        $company = $this->createCompany();
        $fromSim = $this->createSim($company, ['operator_status' => 'active']);
        $toSim = $this->createSim($company, ['operator_status' => 'active']);

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/assignments/set', [
                'customer_phone' => '09171234567',
                'sim_id' => $fromSim->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/admin/migrate-bulk', [
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $toSim->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('operator_audit_logs', [
            'company_id' => $company->id,
            'actor_user_id' => $owner->id,
            'action' => 'assignment.set',
            'target_type' => 'customer_sim_assignment',
        ]);

        $this->assertDatabaseHas('operator_audit_logs', [
            'company_id' => $company->id,
            'actor_user_id' => $owner->id,
            'action' => 'migration.bulk',
            'target_type' => 'sim',
            'target_id' => $fromSim->id,
        ]);
    }
}
