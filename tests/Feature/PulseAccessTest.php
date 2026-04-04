<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PulseAccessTest extends TestCase
{
    use RefreshDatabase;

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
