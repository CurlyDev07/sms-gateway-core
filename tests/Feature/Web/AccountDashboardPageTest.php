<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class AccountDashboardPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    public function test_account_dashboard_page_renders_logged_in_operator_details(): void
    {
        $company = $this->createCompany([
            'name' => 'Account Test Company',
        ]);

        $user = User::factory()->create([
            'name' => 'Account Operator',
            'email' => 'account-operator@example.com',
            'company_id' => $company->id,
            'operator_role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/dashboard/account');

        $response->assertOk()
            ->assertSee('My Account')
            ->assertSee('Account Operator')
            ->assertSee('account-operator@example.com')
            ->assertSee((string) $company->id)
            ->assertSee('Account Test Company')
            ->assertSee('admin')
            ->assertSee('yes');
    }

    public function test_guest_is_redirected_to_login_for_account_dashboard_page(): void
    {
        $this->get('/dashboard/account')
            ->assertRedirect('/login');
    }
}
