<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class DashboardOperatorManagementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    public function test_support_can_list_only_tenant_operators(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $support = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'support',
        ]);

        $ownerA = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'owner',
        ]);

        User::factory()->create([
            'company_id' => $companyB->id,
            'operator_role' => 'admin',
        ]);

        $this->actingAs($support)
            ->getJson('/dashboard/api/operators')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('meta.current_user_id', $support->id)
            ->assertJsonPath('meta.current_user_role', 'support')
            ->assertJsonPath('meta.can_manage_roles', false)
            ->assertJsonCount(2, 'operators')
            ->assertJsonPath('operators.0.company_id', $companyA->id)
            ->assertJsonPath('operators.1.company_id', $companyA->id);

        $this->assertSame($companyA->id, $ownerA->company_id);
    }

    public function test_owner_can_update_operator_role_within_tenant(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
        ]);

        $target = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators/'.$target->id.'/role', [
                'operator_role' => 'support',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('operator.id', $target->id)
            ->assertJsonPath('operator.operator_role', 'support')
            ->assertJsonPath('no_change', false);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);
    }

    public function test_owner_can_create_operator_and_new_operator_can_login(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
            'password' => Hash::make('owner-pass-123'),
        ]);

        $response = $this->actingAs($owner)
            ->postJson('/dashboard/api/operators', [
                'name' => 'New Tenant Admin',
                'email' => 'new-tenant-admin@example.com',
                'operator_role' => 'admin',
            ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('operator.company_id', $company->id)
            ->assertJsonPath('operator.operator_role', 'admin')
            ->assertJsonPath('operator.email', 'new-tenant-admin@example.com')
            ->assertJsonPath('note', 'save_temporary_password_now');

        $temporaryPassword = (string) $response->json('temporary_password');
        $createdUserId = (int) $response->json('operator.id');

        $this->assertNotSame('', $temporaryPassword);

        $createdUser = User::query()->findOrFail($createdUserId);

        $this->assertSame($company->id, (int) $createdUser->company_id);
        $this->assertSame('admin', (string) $createdUser->operator_role);
        $this->assertTrue(Hash::check($temporaryPassword, (string) $createdUser->password));

        $this->post('/logout')->assertRedirect('/login');

        $this->post('/login', [
            'email' => $createdUser->email,
            'password' => $temporaryPassword,
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($createdUser->fresh());
    }

    public function test_owner_create_operator_ignores_cross_tenant_company_input_and_binds_to_actor_tenant(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'owner',
        ]);

        $response = $this->actingAs($owner)
            ->postJson('/dashboard/api/operators', [
                'name' => 'Tenant Bound User',
                'email' => 'tenant-bound-user@example.com',
                'operator_role' => 'support',
                'company_id' => $companyB->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('operator.company_id', $companyA->id);

        $createdUserId = (int) $response->json('operator.id');

        $this->assertDatabaseHas('users', [
            'id' => $createdUserId,
            'company_id' => $companyA->id,
            'operator_role' => 'support',
        ]);

        $this->assertDatabaseMissing('users', [
            'id' => $createdUserId,
            'company_id' => $companyB->id,
        ]);
    }

    public function test_admin_cannot_create_operator(): void
    {
        $company = $this->createCompany();

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->postJson('/dashboard/api/operators', [
                'name' => 'Blocked By Rbac',
                'email' => 'blocked-by-rbac@example.com',
                'operator_role' => 'support',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'insufficient_operator_role');

        $this->assertDatabaseMissing('users', [
            'email' => 'blocked-by-rbac@example.com',
        ]);
    }

    public function test_support_cannot_create_operator(): void
    {
        $company = $this->createCompany();

        $support = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $this->actingAs($support)
            ->postJson('/dashboard/api/operators', [
                'name' => 'Support Cannot Create',
                'email' => 'support-cannot-create@example.com',
                'operator_role' => 'admin',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'insufficient_operator_role');

        $this->assertDatabaseMissing('users', [
            'email' => 'support-cannot-create@example.com',
        ]);
    }

    public function test_owner_create_operator_requires_unique_email(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
        ]);

        User::factory()->create([
            'company_id' => $company->id,
            'email' => 'duplicate-email@example.com',
            'operator_role' => 'support',
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators', [
                'name' => 'Duplicate Email User',
                'email' => 'duplicate-email@example.com',
                'operator_role' => 'admin',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure([
                'details' => ['email'],
            ]);
    }

    public function test_admin_cannot_update_operator_roles(): void
    {
        $company = $this->createCompany();

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
        ]);

        $target = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $this->actingAs($admin)
            ->postJson('/dashboard/api/operators/'.$target->id.'/role', [
                'operator_role' => 'admin',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'insufficient_operator_role');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'operator_role' => 'support',
        ]);
    }

    public function test_support_cannot_update_operator_roles(): void
    {
        $company = $this->createCompany();

        $support = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $target = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
        ]);

        $this->actingAs($support)
            ->postJson('/dashboard/api/operators/'.$target->id.'/role', [
                'operator_role' => 'support',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'insufficient_operator_role');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'operator_role' => 'admin',
        ]);
    }

    public function test_owner_cannot_update_cross_tenant_operator(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'owner',
        ]);

        $otherTenantUser = User::factory()->create([
            'company_id' => $companyB->id,
            'operator_role' => 'admin',
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators/'.$otherTenantUser->id.'/role', [
                'operator_role' => 'support',
            ])
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'operator_not_found');

        $this->assertDatabaseHas('users', [
            'id' => $otherTenantUser->id,
            'company_id' => $companyB->id,
            'operator_role' => 'admin',
        ]);
    }
}
