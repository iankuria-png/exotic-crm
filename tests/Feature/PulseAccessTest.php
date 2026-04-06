<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PulseAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_pulse_dashboard_after_crm_login(): void
    {
        $password = 'secret-password';

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt($password),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->postJson('/api/crm/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk();

        $this->get('/pulse')
            ->assertOk();
    }

    public function test_admin_can_open_pulse_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/pulse')
            ->assertOk();
    }

    public function test_sub_admin_can_open_pulse_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'sub_admin',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/pulse')
            ->assertOk();
    }

    public function test_sales_user_cannot_open_pulse_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/pulse')
            ->assertForbidden();
    }
}
