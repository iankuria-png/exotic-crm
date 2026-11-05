<?php

namespace App\Services\Reconciliation;

use App\Models\User;
use App\Services\MarketAuthorizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReviewAccess
{
    public function ready(): void
    {
        abort_unless(config('reconciliation.enabled') && Schema::hasTable('daily_payment_reviews'), 503, 'Daily review is unavailable. Apply the CRM workflow migration.');
    }

    public function admin(User $user): bool
    {
        return $user->isActive() && $user->role === 'admin';
    }

    public function markets(User $user, string $capability = 'review'): array
    {
        $this->ready();
        abort_unless($user->isActive(), 403, 'Your staff account is inactive.');
        $ids = DB::table('platforms')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($this->admin($user)) {
            return $ids;
        }
        $scope = app(MarketAuthorizationService::class)->resolveAccessiblePlatformIds($user) ?? [];
        $grants = DB::table('payment_review_grants')->where('user_id', $user->id)->where('capability', $capability)->pluck('platform_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_intersect($scope, $grants));
    }

    public function ensure(User $user, array $markets, string $capability = 'review'): void
    {
        abort_if(! $markets || array_diff($markets, $this->markets($user, $capability)), 403, 'You do not have review access to every market in this receipt.');
    }

    public function assignee(User $actor, int $target, array $markets): User
    {
        $user = User::findOrFail($target);
        abort_unless($user->isActive() && in_array($user->role, ['admin', 'sub_admin', 'sales', 'field_sales']), 422, 'Choose an active sales owner.');
        foreach ($markets as $id) {
            app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($user, (int) $id);
        }

        return $user;
    }
}
