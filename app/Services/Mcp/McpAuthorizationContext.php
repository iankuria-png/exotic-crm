<?php

namespace App\Services\Mcp;

use App\Models\User;
use App\Services\MarketAuthorizationService;

class McpAuthorizationContext
{
    public function __construct(
        public readonly User $user,
        public readonly array $abilities,
        public readonly ?array $platformIds,
    ) {}

    public static function for(User $user, array $abilities, MarketAuthorizationService $markets): self
    {
        $scope = $markets->resolveAccessiblePlatformIds($user);

        // A non-admin account without an assignment is never silently global.
        if ($user->role !== 'admin' && $scope === null) {
            $scope = [];
        }

        return new self($user, array_values(array_unique($abilities)), $scope);
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    public function roleIs(array $roles): bool
    {
        return in_array($this->user->role, $roles, true);
    }
}
