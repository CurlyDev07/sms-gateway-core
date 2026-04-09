<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class DashboardIdentityContextTest extends TestCase
{
    use CreatesGatewayEntities;
    use RefreshDatabase;

    public function test_dashboard_and_password_pages_show_tenant_and_operator_identity(): void
    {
        $company = $this->createCompany([
            'name' => 'Identity Test Company',
        ]);

        $user = User::factory()->create([
            'name' => 'Identity Operator',
            'email' => 'identity.operator@example.com',
            'company_id' => $company->id,
            'operator_role' => User::ROLE_ADMIN,
            'must_change_password' => false,
        ]);

        $this->actingAs($user);

        $paths = [
            '/dashboard',
            '/dashboard/sims',
            '/dashboard/assignments',
            '/dashboard/sims/1',
            '/dashboard/migration',
            '/dashboard/messages/status',
            '/dashboard/operators',
            '/dashboard/audit',
            '/dashboard/account',
            '/dashboard/password',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);

            $response->assertOk()
                ->assertSee('Tenant:')
                ->assertSee('Identity Test Company')
                ->assertSee('Operator:')
                ->assertSee('Identity Operator')
                ->assertSee('Role:')
                ->assertSee(User::ROLE_ADMIN);
        }
    }
}
