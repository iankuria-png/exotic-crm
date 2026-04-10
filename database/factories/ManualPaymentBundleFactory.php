<?php

namespace Database\Factories;

use App\Models\ManualPaymentBundle;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ManualPaymentBundle>
 */
class ManualPaymentBundleFactory extends Factory
{
    protected $model = ManualPaymentBundle::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'reference_root' => 'BUNDLE-' . Str::upper(fake()->bothify('??##??')),
            'total_amount' => fake()->randomFloat(2, 2000, 15000),
            'allocated_amount' => fake()->randomFloat(2, 2000, 15000),
            'unallocated_amount' => 0,
            'currency' => 'KES',
            'reason' => 'Factory bundle',
            'status' => ManualPaymentBundle::STATUS_COMMITTED,
            'audit_state' => ManualPaymentBundle::AUDIT_PENDING_FINANCE_REVIEW,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => User::factory(),
        ];
    }
}
