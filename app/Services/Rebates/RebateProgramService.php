<?php

namespace App\Services\Rebates;

use App\Models\Client;
use App\Models\Platform;
use App\Models\RebateProgram;
use App\Models\RebateProgramRevision;
use App\Services\WalletSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RebateProgramService
{
    public function defaults(): array
    {
        return ['rollout_mode' => 'off', 'starts_at' => null, 'ends_at' => null, 'test_client_ids' => [],
            'topup' => ['on' => true, 'tiers' => [['min' => 500, 'pct' => 3], ['min' => 2000, 'pct' => 5], ['min' => 5000, 'pct' => 8]], 'cap' => 1000],
            'self' => ['on' => true, 'cap' => 1000, 'channels' => ['wallet' => ['on' => true, 'pct' => 5], 'self_checkout' => ['on' => true, 'pct' => 5], 'manual_submission' => ['on' => false, 'pct' => 5], 'staff_link' => ['on' => true, 'pct' => 2], 'sales_assisted' => ['on' => true, 'pct' => 2]]],
            'first' => ['on' => true, 'amount' => 200, 'min' => 1000], 'renew' => ['on' => true, 'pct' => 2],
            'guard' => ['monthly_cap' => 3000, 'budget' => 150000, 'stack' => true],
            'audience' => ['verified_only' => false, 'include_agency' => false], 'copy' => ['label' => 'Wallet rebates']];
    }

    public function forPlatform(Platform $p): RebateProgram
    {
        return RebateProgram::firstOrCreate(['platform_id' => $p->id], ['currency' => app(WalletSettingsService::class)->runtimeWalletCurrencyCode($p), 'draft_json' => $this->defaults()])->fresh();
    }

    public function snapshot(RebateProgram $p): ?array
    {
        return $p->published_revision ? $p->revisions()->where('revision', $p->published_revision)->value('snapshot_json') : null;
    }

    public function active(RebateProgram $p): bool
    {
        return $p->published_revision && ! $p->kill_switch && in_array($p->rollout_mode, ['live', 'sandbox'], true)
            && (! $p->starts_at || now()->gte($p->starts_at)) && (! $p->ends_at || now()->lt($p->ends_at));
    }

    public function replacesDiscount(int $market): bool
    {
        if (! Schema::hasTable('rebate_programs')) {
            return false;
        }
        $p = RebateProgram::where('platform_id', $market)->first();

        return $p && $this->active($p);
    }

    public function validate(Platform $p, array $draft): array
    {
        $rules = ['rollout_mode' => 'required|in:off,sandbox,live', 'starts_at' => 'nullable|date', 'ends_at' => filled($draft['starts_at'] ?? null) ? 'nullable|date|after:starts_at' : 'nullable|date', 'test_client_ids' => 'present|array|max:100', 'test_client_ids.*' => 'integer|distinct',
            'topup.on' => 'required|boolean', 'topup.tiers' => 'required|array|min:1|max:12', 'topup.tiers.*.min' => 'required|numeric|min:1|max:100000000', 'topup.tiers.*.pct' => 'required|numeric|min:0|max:50',
            'self.on' => 'required|boolean', 'first.on' => 'required|boolean', 'renew.on' => 'required|boolean', 'renew.pct' => 'required|numeric|min:0|max:50', 'guard.stack' => 'required|boolean',
            'audience.verified_only' => 'required|boolean', 'audience.include_agency' => 'required|boolean', 'copy.label' => 'required|string|max:80'];
        foreach (['topup.cap', 'self.cap', 'first.amount', 'first.min', 'guard.monthly_cap', 'guard.budget'] as $key) {
            $rules[$key] = 'required|numeric|min:0|max:100000000';
        }
        foreach (array_keys($this->defaults()['self']['channels']) as $channel) {
            $rules["self.channels.{$channel}.on"] = 'required|boolean';
            $rules["self.channels.{$channel}.pct"] = 'required|numeric|min:0|max:50';
        }
        $d = Validator::make($draft, $rules)->validate();
        $previous = 0;
        foreach ($d['topup']['tiers'] as $tier) {
            if ((float) $tier['min'] <= $previous) {
                throw ValidationException::withMessages(['topup.tiers' => 'Tier thresholds must be strictly ascending.']);
            }
            $previous = (float) $tier['min'];
        }
        foreach ($d['test_client_ids'] as $id) {
            abort_unless(Client::where('platform_id', $p->id)->whereKey($id)->exists(), 422, 'Test companion belongs to another market.');
        }
        if ($d['rollout_mode'] !== 'off') {
            abort_unless((bool) data_get(app(WalletSettingsService::class)->runtimePlatformConfig($p), 'enabled'), 422, 'Enable the market wallet before publishing rebates.');
            abort_if($d['rollout_mode'] === 'sandbox' && ! $d['test_client_ids'], 422, 'Choose at least one sandbox test companion.');
        }
        // Discard unknown nested keys; settlement accepts only the fixed channel allow-list.
        $clean = $this->defaults();
        foreach (\Illuminate\Support\Arr::dot($clean) as $key => $_) {
            if (! str_starts_with($key, 'topup.tiers.') && ! str_starts_with($key, 'test_client_ids.')) {
                data_set($clean, $key, data_get($d, $key));
            }
        }
        $clean['topup']['tiers'] = array_map(fn ($t) => ['min' => (float) $t['min'], 'pct' => (float) $t['pct']], $d['topup']['tiers']);
        $clean['test_client_ids'] = array_map('intval', $d['test_client_ids']);

        return $clean;
    }

    public function save(Platform $p, array $draft, int $revision, int $actor): RebateProgram
    {
        $d = $this->validate($p, $draft);
        $this->forPlatform($p);

        return DB::transaction(function () use ($p, $d, $revision, $actor) {
            $s = RebateProgram::where('platform_id', $p->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->draft_revision === $revision, 409, 'Program changed. Reload before saving.');
            $s->update(['draft_json' => $d, 'draft_revision' => $revision + 1, 'updated_by' => $actor]);

            return $s->fresh();
        });
    }

    public function publish(Platform $p, int $revision, string $reason, int $actor): RebateProgram
    {
        $this->reason($reason);

        return DB::transaction(function () use ($p, $revision, $reason, $actor) {
            $s = RebateProgram::where('platform_id', $p->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->draft_revision === $revision, 409, 'Program changed. Reload before publishing.');
            $d = $this->validate($p, $s->draft_json);
            $currency = app(WalletSettingsService::class)->runtimeWalletCurrencyCode($p);
            abort_if($s->currency !== $currency && $s->published_revision, 422, 'Program currency is fixed after first publish.');
            $before = $this->snapshot($s) ?? [];
            $snapshot = array_merge($d, ['currency' => $currency, 'kill_switch' => $s->kill_switch]);
            $this->revision($s, $snapshot, $before, 'publish', $reason, $actor);
            $s->update(['rollout_mode' => $d['rollout_mode'], 'starts_at' => $d['starts_at'], 'ends_at' => $d['ends_at'], 'test_client_ids' => $d['test_client_ids'], 'currency' => $currency, 'updated_by' => $actor]);
            // Sandbox/zero decisions must not pin the live launch budget.
            // Grants acquire this same program lock before the budget lock.
            \App\Models\RebateBudgetPeriod::where('platform_id', $p->id)
                ->where('period_key', now()->timezone($p->timezone ?: 'UTC')->format('Y-m'))
                ->where('issued_amount', 0)->update(['budget_amount' => data_get($d, 'guard.budget')]);

            return $s->fresh();
        });
    }

    public function pause(Platform $p, bool $paused, string $reason, int $actor): RebateProgram
    {
        $this->reason($reason);

        return DB::transaction(function () use ($p, $paused, $reason, $actor) {
            $s = RebateProgram::where('platform_id', $p->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($s);
            abort_unless($before, 422, 'Publish a program first.');
            $this->revision($s, array_merge($before, ['kill_switch' => $paused]), $before, $paused ? 'pause' : 'resume', $reason, $actor);
            $s->update(['kill_switch' => $paused, 'updated_by' => $actor, 'draft_revision' => $s->draft_revision + 1]);

            return $s->fresh();
        });
    }

    private function revision(RebateProgram $s, array $snapshot, array $before, string $action, string $reason, int $actor): void
    {
        $rev = ($s->published_revision ?? 0) + 1;
        $diff = [];
        foreach (\Illuminate\Support\Arr::dot($snapshot) as $key => $value) {
            if (data_get($before, $key) !== $value) {
                $diff[$key] = ['before' => data_get($before, $key), 'after' => $value];
            }
        }
        RebateProgramRevision::create(['rebate_program_id' => $s->id, 'revision' => $rev, 'action' => $action, 'snapshot_json' => $snapshot, 'diff_json' => $diff, 'reason' => trim($reason), 'actor_id' => $actor, 'created_at' => now()]);
        $s->update(['published_revision' => $rev]);
    }

    public function reason(string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|min:5|max:1000'])->validate();
    }

    public function runtime(Platform $p, string $environment): ?array
    {
        if (! Schema::hasTable('rebate_programs')) {
            return null;
        }
        $s = RebateProgram::where('platform_id', $p->id)->first();
        if (! $s || ! $this->active($s) || ($s->rollout_mode === 'live' && $environment === 'sandbox') || ($s->rollout_mode === 'sandbox' && $environment !== 'sandbox')) {
            return null;
        }

        return array_merge($this->snapshot($s), ['revision' => $s->published_revision, 'test_post_ids' => Client::where('platform_id', $p->id)->whereIn('id', $s->test_client_ids ?? [])->pluck('wp_post_id')->all()]);
    }

    public function firstTopupEligible(Client $c, ?\App\Models\Payment $payment = null): bool
    {
        $first = \App\Models\Payment::where('client_id', $c->id)->where('purpose', 'wallet_topup')->where('status', 'completed')->where('payment_data->initiator', 'companion')
            ->where(fn ($q) => $q->whereNull('provider_environment')->orWhere('provider_environment', '!=', 'sandbox'))
            ->where(fn ($q) => $q->whereNull('payment_data->test_mode')->orWhere('payment_data->test_mode', false))
            ->where(fn ($q) => $q->whereNull('record_classification')->orWhere('record_classification', '!=', \App\Models\Payment::RECORD_CLASSIFICATION_TEST))
            ->orderByRaw('COALESCE(completed_at, created_at)')->orderBy('id')->first(['id']);

        return ! $first || ($payment && (int) $first->id === (int) $payment->id);
    }

    public function eligibility(RebateProgram $s, Client $c, array $snapshot): ?string
    {
        if (! $this->active($s)) {
            return $s->kill_switch ? 'paused' : 'not_active';
        }
        if ($s->rollout_mode === 'sandbox' && ! in_array((int) $c->id, $s->test_client_ids ?? [], true)) {
            return 'not_test_companion';
        }
        if (data_get($snapshot, 'audience.verified_only') && ! $c->verified) {
            return 'unverified';
        }
        if (! data_get($snapshot, 'audience.include_agency') && in_array($c->client_type, ['agency', 'agency_escort'], true)) {
            return 'agency_excluded';
        }

        return null;
    }
}
