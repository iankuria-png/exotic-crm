<?php

namespace App\Services;

use App\Models\User;

class CrmAccessPolicy
{
    public const RESTRICTED = 'no_payments_or_dashboards';

    public function capabilities(User $user): array
    {
        $active = $user->isActive();
        $unrestricted = $active && $user->access_profile === null;
        $admin = $unrestricted && $user->role === 'admin';

        return [
            'payments_workspace' => $unrestricted && in_array($user->role, ['admin', 'sub_admin', 'sales', 'field_sales'], true),
            'admin_dashboard' => $unrestricted && in_array($user->role, ['admin', 'sub_admin'], true),
            'sales_dashboard' => $unrestricted && $user->role === 'sales',
            'manage_staff_access' => $admin,
            'impersonate_staff' => $admin,
        ];
    }

    public function allows(User $user, string $capability): bool
    {
        $caps = $this->capabilities($user);

        return $capability === 'dashboard'
            ? ($caps['admin_dashboard'] || $caps['sales_dashboard'])
            : ($caps[$capability] ?? false);
    }

    public function homePath(User $user): string
    {
        if (! $user->isActive()) {
            return '/login';
        }
        if ($user->access_profile !== null && in_array($user->role, ['admin', 'sub_admin'], true)) {
            return '/team';
        }

        return match ($user->role) {
            'admin', 'sub_admin' => '/',
            'sales' => $user->access_profile === null ? '/' : '/clients',
            'field_sales' => '/field/home',
            'marketing' => '/push-campaigns',
            default => '/faq',
        };
    }

    public function snapshot(User $user): array
    {
        return [
            'access_profile' => $user->access_profile,
            'home_path' => $this->homePath($user),
            'access_policy_version' => hash('sha256', json_encode([
                'v1', (int) $user->id, $user->role, $user->status, $user->access_profile,
            ])),
        ];
    }

    public function allowsTool(User $user, string $name): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        if ($this->allows($user, 'dashboard') || $this->allows($user, 'payments_workspace')) {
            return true;
        }

        // New reporting tools require a policy decision before restricted staff
        // can execute them; technical diagnostics retain their existing grants.
        return in_array($name, [
            'exotic_system_vitals', 'exotic_system_vitals_live',
            'exotic_error_digest', 'exotic_error_digest_live', 'exotic_market_health',
        ], true);
    }
}
