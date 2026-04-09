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

    public function test_operator_list_supports_role_and_active_filters_within_tenant_scope(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'owner',
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'support',
            'is_active' => true,
        ]);

        User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'support',
            'is_active' => false,
        ]);

        User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'admin',
            'is_active' => true,
        ]);

        User::factory()->create([
            'company_id' => $companyB->id,
            'operator_role' => 'support',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->getJson('/dashboard/api/operators?operator_role=support&is_active=1')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'operators')
            ->assertJsonPath('operators.0.id', $target->id)
            ->assertJsonPath('operators.0.company_id', $companyA->id)
            ->assertJsonPath('operators.0.operator_role', 'support')
            ->assertJsonPath('operators.0.is_active', true)
            ->assertJsonPath('meta.filters.search', null)
            ->assertJsonPath('meta.filters.operator_role', 'support')
            ->assertJsonPath('meta.filters.is_active', 1)
            ->assertJsonPath('meta.filters.sort_by', 'id')
            ->assertJsonPath('meta.filters.sort_dir', 'asc');
    }

    public function test_operator_list_supports_search_by_name_or_email_with_tenant_scope(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'owner',
        ]);

        $nameMatch = User::factory()->create([
            'company_id' => $companyA->id,
            'name' => 'Jane Ops',
            'email' => 'ops-jane@example.com',
            'operator_role' => 'support',
            'is_active' => true,
        ]);

        User::factory()->create([
            'company_id' => $companyA->id,
            'name' => 'Other User',
            'email' => 'other@example.com',
            'operator_role' => 'support',
            'is_active' => true,
        ]);

        User::factory()->create([
            'company_id' => $companyB->id,
            'name' => 'Jane Cross Tenant',
            'email' => 'cross-jane@example.com',
            'operator_role' => 'support',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->getJson('/dashboard/api/operators?search=jane&operator_role=support&is_active=1&sort_by=name&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'operators')
            ->assertJsonPath('operators.0.id', $nameMatch->id)
            ->assertJsonPath('operators.0.company_id', $companyA->id)
            ->assertJsonPath('operators.0.name', 'Jane Ops')
            ->assertJsonPath('meta.filters.search', 'jane')
            ->assertJsonPath('meta.filters.operator_role', 'support')
            ->assertJsonPath('meta.filters.is_active', 1)
            ->assertJsonPath('meta.filters.sort_by', 'name')
            ->assertJsonPath('meta.filters.sort_dir', 'asc');
    }

    public function test_operator_list_supports_sorting_by_allowed_fields(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
            'name' => 'Bravo',
            'email' => 'bravo@example.com',
        ]);

        User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
            'name' => 'Alpha',
            'email' => 'alpha@example.com',
        ]);

        User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
        ]);

        $this->actingAs($owner)
            ->getJson('/dashboard/api/operators?sort_by=name&sort_dir=desc')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(3, 'operators')
            ->assertJsonPath('operators.0.name', 'Charlie')
            ->assertJsonPath('operators.1.name', 'Bravo')
            ->assertJsonPath('operators.2.name', 'Alpha')
            ->assertJsonPath('meta.filters.sort_by', 'name')
            ->assertJsonPath('meta.filters.sort_dir', 'desc');
    }

    public function test_operator_list_rejects_invalid_sort_field(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
        ]);

        $this->actingAs($owner)
            ->getJson('/dashboard/api/operators?sort_by=company_id')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure([
                'details' => ['sort_by'],
            ]);
    }

    public function test_operator_list_rejects_search_longer_than_255_chars(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
        ]);

        $this->actingAs($owner)
            ->getJson('/dashboard/api/operators?search='.str_repeat('a', 256))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure([
                'details' => ['search'],
            ]);
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
        $this->assertTrue((bool) $createdUser->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, (string) $createdUser->password));

        $this->post('/logout')->assertRedirect('/login');

        $this->post('/login', [
            'email' => $createdUser->email,
            'password' => $temporaryPassword,
        ])->assertRedirect('/dashboard/password/change');

        $this->post('/dashboard/password/change', [
            'password' => 'new-operator-pass-123',
            'password_confirmation' => 'new-operator-pass-123',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($createdUser->fresh());
        $this->assertFalse((bool) $createdUser->fresh()->must_change_password);
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

    public function test_owner_can_reset_operator_password_within_tenant(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
            'password' => Hash::make('owner-pass-123'),
        ]);

        $target = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
            'must_change_password' => false,
            'password' => Hash::make('target-old-pass-123'),
        ]);

        $oldHash = (string) $target->password;

        $response = $this->actingAs($owner)
            ->postJson('/dashboard/api/operators/'.$target->id.'/reset-password');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('operator.id', $target->id)
            ->assertJsonPath('operator.company_id', $company->id)
            ->assertJsonPath('operator.email', $target->email)
            ->assertJsonPath('note', 'save_temporary_password_now');

        $temporaryPassword = (string) $response->json('temporary_password');
        $this->assertNotSame('', $temporaryPassword);

        $target->refresh();
        $this->assertTrue((bool) $target->must_change_password);
        $this->assertNotSame($oldHash, (string) $target->password);
        $this->assertTrue(Hash::check($temporaryPassword, (string) $target->password));

        $this->post('/logout')->assertRedirect('/login');

        $this->post('/login', [
            'email' => $target->email,
            'password' => $temporaryPassword,
        ])->assertRedirect('/dashboard/password/change');
    }

    public function test_owner_cannot_reset_own_password(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
            'must_change_password' => false,
            'password' => Hash::make('owner-pass-123'),
        ]);

        $oldHash = (string) $owner->password;

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators/'.$owner->id.'/reset-password')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'cannot_reset_own_password');

        $owner->refresh();
        $this->assertFalse((bool) $owner->must_change_password);
        $this->assertSame($oldHash, (string) $owner->password);
    }

    public function test_owner_cannot_reset_cross_tenant_operator_password(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'owner',
        ]);

        $otherTenantUser = User::factory()->create([
            'company_id' => $companyB->id,
            'operator_role' => 'support',
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators/'.$otherTenantUser->id.'/reset-password')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'operator_not_found');
    }

    public function test_admin_cannot_reset_operator_password(): void
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
            ->postJson('/dashboard/api/operators/'.$target->id.'/reset-password')
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'insufficient_operator_role');
    }

    public function test_support_cannot_reset_operator_password(): void
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
            ->postJson('/dashboard/api/operators/'.$target->id.'/reset-password')
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'insufficient_operator_role');
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

    public function test_owner_can_deactivate_and_reactivate_operator_within_tenant(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators/'.$target->id.'/activation', [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('operator.id', $target->id)
            ->assertJsonPath('operator.is_active', false)
            ->assertJsonPath('no_change', false);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'company_id' => $company->id,
            'is_active' => false,
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators/'.$target->id.'/activation', [
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('operator.id', $target->id)
            ->assertJsonPath('operator.is_active', true)
            ->assertJsonPath('no_change', false);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }

    public function test_owner_cannot_deactivate_self(): void
    {
        $company = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'owner',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators/'.$owner->id.'/activation', [
                'is_active' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'cannot_deactivate_self');

        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_and_support_cannot_update_operator_activation(): void
    {
        $company = $this->createCompany();

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
        ]);

        $support = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'support',
        ]);

        $target = User::factory()->create([
            'company_id' => $company->id,
            'operator_role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/dashboard/api/operators/'.$target->id.'/activation', [
                'is_active' => false,
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'insufficient_operator_role');

        $this->actingAs($support)
            ->postJson('/dashboard/api/operators/'.$target->id.'/activation', [
                'is_active' => false,
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'insufficient_operator_role');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => true,
        ]);
    }

    public function test_owner_cannot_update_cross_tenant_operator_activation(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        $owner = User::factory()->create([
            'company_id' => $companyA->id,
            'operator_role' => 'owner',
        ]);

        $otherTenantUser = User::factory()->create([
            'company_id' => $companyB->id,
            'operator_role' => 'support',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->postJson('/dashboard/api/operators/'.$otherTenantUser->id.'/activation', [
                'is_active' => false,
            ])
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'operator_not_found');

        $this->assertDatabaseHas('users', [
            'id' => $otherTenantUser->id,
            'company_id' => $companyB->id,
            'is_active' => true,
        ]);
    }
}
