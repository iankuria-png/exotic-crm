<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MarketAuthorizationService
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUB_ADMIN = 'sub_admin';
    public const ROLE_SALES = 'sales';
    public const ROLE_MARKETING = 'marketing';
    public const ALLOWED_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_SUB_ADMIN,
        self::ROLE_SALES,
        self::ROLE_MARKETING,
    ];

    public function applyPlatformScope(Builder $query, User $user, string $column = 'platform_id'): Builder
    {
        $platformIds = $this->resolveAccessiblePlatformIds($user);

        if (is_array($platformIds)) {
            if (empty($platformIds)) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereIn($column, $platformIds);
        }

        return $query;
    }

    public function resolveAccessiblePlatformIds(User $user): ?array
    {
        if ($user->role === self::ROLE_ADMIN) {
            return null;
        }

        $assigned = collect($this->decodeMarketIds($user->assigned_market_ids ?? null))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $platformIds = $user->platforms()
            ->pluck('platforms.id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        return $assigned
            ->merge($platformIds)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function userCanAccessPlatform(User $user, ?int $platformId): bool
    {
        if (!$platformId) {
            return true;
        }

        $platformIds = $this->resolveAccessiblePlatformIds($user);

        if (is_array($platformIds)) {
            return in_array((int) $platformId, $platformIds, true);
        }

        return true;
    }

    public function ensureUserCanAccessPlatform(User $user, ?int $platformId, string $message = 'You do not have access to this market.'): void
    {
        if (!$this->userCanAccessPlatform($user, $platformId)) {
            abort(403, $message);
        }
    }

    public function ensureRequestedPlatformIsAccessible(
        Request $request,
        string $inputKey = 'platform_id',
        string $message = 'You do not have access to this market.'
    ): ?int {
        if (!$request->filled($inputKey)) {
            return null;
        }

        $platformId = (int) $request->input($inputKey);
        $this->ensureUserCanAccessPlatform($request->user(), $platformId, $message);

        return $platformId;
    }

    public function hasRole(User $user, array $roles): bool
    {
        return in_array($user->role, $roles, true);
    }

    public function ensureRole(User $user, array $roles, string $message = 'You do not have permission to perform this action.'): void
    {
        if (!$this->hasRole($user, $roles)) {
            abort(403, $message);
        }
    }

    public function isManager(User $user): bool
    {
        return $this->hasRole($user, [self::ROLE_ADMIN, self::ROLE_SUB_ADMIN]);
    }

    public function ensureManager(User $user, string $message = 'Only admin or sub-admin users can perform this action.'): void
    {
        if (!$this->isManager($user)) {
            abort(403, $message);
        }
    }

    public function eligibleOwnersForPlatform(int $platformId): Collection
    {
        $users = User::query()
            ->where('status', 'active')
            ->whereIn('role', [self::ROLE_SALES, self::ROLE_SUB_ADMIN, self::ROLE_ADMIN])
            ->with('platforms:id')
            ->orderBy('id')
            ->get();

        $scoped = $users
            ->filter(fn (User $user) => $this->userCanAccessPlatform($user, $platformId))
            ->values();

        return $scoped;
    }

    private function decodeMarketIds($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
