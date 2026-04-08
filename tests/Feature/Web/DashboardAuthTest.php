<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesGatewayEntities;
use Tests\TestCase;

class DashboardAuthTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGatewayEntities;

    public function test_guest_is_redirected_to_login_for_dashboard_routes(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Dashboard Login')
            ->assertSee('Email')
            ->assertSee('Password');
    }

    public function test_user_can_login_and_access_dashboard(): void
    {
        $company = $this->createCompany();
        [$apiClient, $plainSecret] = $this->createApiClient($company);

        $user = User::factory()->create([
            'password' => Hash::make('secret-pass-123'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass-123',
            'dashboard_api_key' => $apiClient->api_key,
            'dashboard_api_secret' => $plainSecret,
        ])->assertRedirect('/dashboard');

        $this->assertSame((int) $apiClient->id, (int) session('dashboard_api_client_id'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Gateway Dashboard');
    }

    public function test_invalid_login_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-pass-123'),
        ]);

        $this->from('/login')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_login_without_tenant_binding_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-pass-123'),
        ]);

        $this->from('/login')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'secret-pass-123',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('dashboard_api_key');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
