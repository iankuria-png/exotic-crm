<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentMarket;
use App\Models\Platform;
use App\Models\User;

class ContainmentPolicy
{
    public function authorize(?User $actor): void
    {
        if (! $actor || $actor->role !== 'admin' || ($actor->status ?? 'active') !== 'active') {
            throw new ContainmentException('active_admin_required', 403);
        }
        if (request()->attributes->get('crm_impersonator') || request()->attributes->get('crm_impersonation') || request()->attributes->get('is_impersonating')) {
            throw new ContainmentException('impersonation_forbidden', 403);
        }
    }

    public function market(Platform $platform, string $kind = 'database'): DbContainmentMarket
    {
        $market = DbContainmentMarket::query()->find($platform->id);
        if (! config('db_containment.enabled') || ! $market?->enabled || ! $platform->is_active) {
            throw new ContainmentException('containment_disabled', 409);
        }
        if ($kind === 'filesystem' && (! config('db_containment.filesystem_enabled') || ! $market->filesystem_enabled)) {
            throw new ContainmentException('filesystem_not_enabled');
        }
        if ($kind === 'quarantine' && (! config('db_containment.filesystem_enabled') || ! $market->filesystem_enabled || ! config('db_containment.quarantine_enabled') || ! $market->quarantine_enabled)) {
            throw new ContainmentException('quarantine_not_enabled');
        }

        return $market;
    }

    public function protectedUser(array $user, array $extra = []): bool
    {
        $emails = array_merge($extra, config('db_containment.protected_emails', []));
        foreach (['allow.it_emails', 'allow.admin_emails'] as $key) {
            $list = \App\Models\DbScanList::query()->where('key', $key)->first();
            $emails = array_merge($emails, array_map(fn ($entry) => is_array($entry) ? ($entry['value'] ?? '') : $entry, (array) ($list?->entries ?? [])));
        }

        return strtolower($user['user_login']) === 'joolyan' || in_array(strtolower($user['user_email']), array_map('strtolower', $emails), true);
    }

    public function protectedKey(string $name): bool
    {
        return (bool) preg_match('/^(?:EWMS|Exotic[ -]CRM(?: Sync)?|tzz)$/i', $name);
    }
}
