<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\LoveGift;
use App\Models\Platform;
use App\Models\PremiumContentEvent;
use App\Models\SendLoveSetting;
use App\Models\WalletTransaction;
use App\Services\MarketAuthorizationService;
use App\Services\SendLove\SettingsService;
use App\Services\WalletSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SendLoveController extends Controller
{
    private function authorizeLove(Request $r, ?int $id = null, bool $write = false): void
    {
        $m = app(MarketAuthorizationService::class);
        $m->ensureRole($r->user(), $write ? ['admin', 'sub_admin'] : ['admin', 'sub_admin', 'sales']);
        if ($id) {
            $m->ensureUserCanAccessPlatform($r->user(), $id);
        }
    }

    public function settings(Request $r, Platform $platform)
    {
        $this->authorizeLove($r, $platform->id, true);
        $s = app(SettingsService::class)->forPlatform($platform);

        return response()->json(['market' => $s, 'effective' => app(SettingsService::class)->runtime($s), 'can_edit_system' => $r->user()->role === 'admin', 'system' => app(\App\Services\MonetizationSettingsService::class)->system()]);
    }

    public function save(Request $r, Platform $platform)
    {
        $this->authorizeLove($r, $platform->id, true);
        $s = app(SettingsService::class)->save($platform, $r->all(), $r->user()->id);
        $sync = $this->push($s);

        return response()->json(['market' => $s->fresh(), 'effective' => app(SettingsService::class)->runtime($s), 'sync' => $sync]);
    }

    public function sync(Request $r, Platform $platform)
    {
        $this->authorizeLove($r, $platform->id, true);

        return response()->json(['sync' => $this->push(app(SettingsService::class)->forPlatform($platform))]);
    }

    public function saveSystem(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $d = $r->validate(['send_love_kill_switch' => 'required|boolean', 'config_revision' => 'required|integer', 'reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($r, $d) {
            $s = app(\App\Services\MonetizationSettingsService::class)->system()->newQuery()->lockForUpdate()->findOrFail(1);
            abort_unless($s->config_revision == $d['config_revision'], 409, 'Settings changed. Reload.');
            $s->update(['send_love_kill_switch' => $d['send_love_kill_switch'], 'config_revision' => $s->config_revision + 1]);
            foreach (SendLoveSetting::orderBy('id')->lockForUpdate()->get() as $market) {
                // Global runtime changes must reject an older in-flight market push.
                $market->increment('config_revision');
                PremiumContentEvent::create(['platform_id' => $market->platform_id, 'actor_id' => $r->user()->id, 'kind' => 'send_love_global_pause', 'reason' => $d['reason']]);
            }
        });
        $sync = [];
        foreach (SendLoveSetting::all() as $s) {
            $sync[$s->platform_id] = $this->push($s);
        }

        return response()->json(['saved' => true, 'sync' => $sync]);
    }

    public function push(SendLoveSetting $s): array
    {
        $base = rtrim((string) $s->platform->wp_api_url, '/');
        if (! $base) {
            return ['status' => 'failed', 'message' => 'WordPress API URL is not configured.'];
        }if (! str_ends_with($base, '/exotic-crm-sync/v1')) {
            $base .= '/exotic-crm-sync/v1';
        }
        $payload = ['config_revision' => $s->config_revision, 'effective' => app(SettingsService::class)->runtime($s)];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $time = (string) now()->timestamp;
        $key = (string) Str::uuid();
        // Use the site's installed access HMAC context; gifting rollout is independent of private content.
        $secret = app(WalletSettingsService::class)->wpToCrmHmacSecret($s->platform, app(\App\Services\MonetizationSettingsService::class)->forPlatform($s->platform)->premium_access_environment);
        if (! $secret) {
            return ['status' => 'failed', 'message' => 'Per-market HMAC secret is missing.'];
        }
        $signature = hash_hmac('sha256', implode("\n", [$time, 'POST', '/wp-json/exotic-crm-sync/v1/send-love/sync', (string) $s->platform_id, $key, hash('sha256', $body)]), $secret);
        try {
            $res = Http::timeout(15)->withHeaders(['X-Exotic-Platform-Id' => $s->platform_id, 'X-Exotic-Timestamp' => $time, 'X-Idempotency-Key' => $key, 'X-Exotic-Signature' => $signature])->withBody($body, 'application/json')->post($base.'/send-love/sync');
            if ($res->successful() && (int) $res->json('revision') === (int) $s->config_revision) {
                $s->update(['wp_revision' => $s->config_revision]);

                return ['status' => 'synced'];
            }

            return ['status' => 'failed', 'message' => 'WordPress did not acknowledge revision '.$s->config_revision.'.'];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'WordPress could not be reached.'];
        }
    }

    private function query(Request $r)
    {
        $this->authorizeLove($r, $r->integer('platform_id') ?: null);
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'status' => 'nullable|in:sent,pending_payment,failed,review,refunded', 'search' => 'nullable|string|max:120', 'provider' => 'nullable|in:kopokopo,pawapay', 'environment' => 'nullable|in:production,sandbox', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = app(MarketAuthorizationService::class)->applyPlatformScope(LoveGift::query(), $r->user(), 'love_gifts.platform_id');
        foreach (['platform_id', 'client_id', 'status'] as $f) {
            if ($r->filled($f)) {
                $q->where('love_gifts.'.$f, $r->input($f));
            }
        }
        if (! $r->boolean('include_tests')) {
            $q->where('love_gifts.is_sandbox', false);
        }
        foreach (['from' => '>=', 'to' => '<='] as $f => $op) {
            if ($r->filled($f)) {
                $q->whereDate('love_gifts.created_at', $op, $r->input($f));
            }
        }
        foreach (['provider' => 'provider_key', 'environment' => 'provider_environment'] as $f => $column) {
            if ($r->filled($f)) {
                $q->whereHas('payment', fn ($p) => $p->where($column, $r->input($f)));
            }
        }
        if ($r->filled('search')) {
            $term = '%'.$r->input('search').'%';
            $q->where(fn ($q) => $q->where('public_id', 'like', $term)->orWhere('visitor_phone_masked', 'like', $term)->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term))->orWhereHas('payment', fn ($p) => $p->where('reference_number', 'like', $term)));
        }

        return $q;
    }

    public function index(Request $r)
    {
        $q = $this->query($r);
        $total = (clone $q)->count();
        $sent = (clone $q)->where('status', 'sent');
        $completed = (clone $sent)->count();

        return response()->json(['gifts' => (clone $q)->with(['client:id,name', 'payment:id,reference_number,status,provider_key,provider_environment,failure_reason'])->latest('id')->paginate($r->integer('per_page') ?: 30),
            'totals' => (clone $sent)->selectRaw('currency, SUM(amount) as gross, AVG(amount) as average, SUM(creator_credit_amount) as creator_credit, COUNT(*) as gifts')->groupBy('currency')->get(),
            'summary' => ['attempts' => $total, 'sent' => $completed, 'completion_rate' => $total ? round(100 * $completed / $total, 1) : 0, 'senders' => (clone $sent)->distinct()->count('visitor_phone_hash')],
            'top_creators' => (clone $sent)->selectRaw('client_id,currency,SUM(amount) as gross,COUNT(*) as gifts')->groupBy('client_id', 'currency')->with('client:id,name')->orderByDesc('gross')->limit(10)->get(),
            'failures' => (clone $q)->where('love_gifts.status', 'failed')->join('payments', 'payments.id', '=', 'love_gifts.payment_id')->selectRaw('payments.failure_reason,COUNT(*) as count')->groupBy('payments.failure_reason')->get(),
            'can_manage' => app(MarketAuthorizationService::class)->isManager($r->user())]);
    }

    /**
     * Send Love workspace overview. Native currencies stay separate; gift counts are the
     * currency-neutral measure used for the daily trend.
     */
    public function overview(Request $r)
    {
        $q = $this->query($r);
        $sent = (clone $q)->where('love_gifts.status', 'sent');
        $attempts = (clone $q)->count();
        $sentCount = (clone $sent)->count();
        $trend = (clone $sent);
        if (! $r->filled('from')) {
            $trend->where('love_gifts.created_at', '>=', now()->subDays(29)->startOfDay());
        }

        return response()->json([
            'totals' => (clone $sent)->selectRaw('currency, SUM(amount) as gross, AVG(amount) as average, SUM(creator_credit_amount) as creator_credit, COUNT(*) as gifts')->groupBy('currency')->get(),
            'summary' => [
                'attempts' => $attempts,
                'sent' => $sentCount,
                'completion_rate' => $attempts ? round(100 * $sentCount / $attempts, 1) : 0,
                'statuses' => (clone $q)->selectRaw('love_gifts.status as status, COUNT(*) as count')->groupBy('love_gifts.status')->pluck('count', 'status'),
                'senders' => (clone $sent)->distinct()->count('visitor_phone_hash'),
                'creators' => (clone $sent)->distinct()->count('client_id'),
                'notes' => (clone $sent)->whereNotNull('message')->count(),
                'contact_shared' => (clone $sent)->where('contact_shared', true)->count(),
                'reported' => (clone $q)->whereNotNull('metadata_json->reported_at')->count(),
            ],
            'trend_window' => $r->filled('from') ? 'filtered' : 'last_30_days',
            'trend' => $trend->selectRaw('DATE(love_gifts.created_at) as day, currency, COUNT(*) as gifts, SUM(amount) as gross')->groupBy('day', 'currency')->orderBy('day')->get(),
            'amounts' => (clone $sent)->selectRaw('currency, amount, COUNT(*) as gifts')->groupBy('currency', 'amount')->orderByDesc('gifts')->limit(8)->get(),
            'top_creators' => (clone $sent)->selectRaw('client_id, currency, SUM(amount) as gross, COUNT(*) as gifts')->groupBy('client_id', 'currency')->with('client:id,name')->orderByDesc('gross')->limit(8)->get(),
            'failures' => (clone $q)->where('love_gifts.status', 'failed')->join('payments', 'payments.id', '=', 'love_gifts.payment_id')->selectRaw('payments.failure_reason as failure_reason, COUNT(*) as count')->groupBy('payments.failure_reason')->orderByDesc('count')->get(),
            'markets' => $this->marketsFor($r)->map(fn (Platform $p) => ['id' => $p->id, 'name' => $p->name, 'currency' => $p->currency_code])->values(),
        ]);
    }

    /** Private notes are moderation data: managers only, and never the sender's number. */
    public function notes(Request $r)
    {
        app(MarketAuthorizationService::class)->ensureManager($r->user());
        $r->validate(['state' => 'nullable|in:visible,reported,hidden_by_creator,removed_by_staff']);
        $base = $this->query($r)->whereIn('love_gifts.status', ['sent', 'review', 'refunded'])
            ->where(fn ($w) => $w->whereNotNull('message')->orWhere('message_state', '!=', 'visible')->orWhere('contact_shared', true));
        $counts = [
            'all' => (clone $base)->count(),
            'visible' => (clone $base)->where('message_state', 'visible')->count(),
            'reported' => (clone $base)->whereNotNull('metadata_json->reported_at')->count(),
            'hidden_by_creator' => (clone $base)->where('message_state', 'hidden_by_creator')->count(),
            'removed_by_staff' => (clone $base)->where('message_state', 'removed_by_staff')->count(),
        ];
        $q = clone $base;
        match ($r->input('state')) {
            'reported' => $q->whereNotNull('metadata_json->reported_at'),
            null => null,
            default => $q->where('message_state', $r->input('state')),
        };
        $page = $q->with(['client:id,name', 'payment:id,reference_number'])->latest('love_gifts.id')
            ->paginate($r->integer('per_page') ?: 20, ['love_gifts.id', 'love_gifts.public_id', 'love_gifts.platform_id', 'love_gifts.client_id', 'love_gifts.payment_id', 'love_gifts.status', 'love_gifts.amount', 'love_gifts.currency', 'love_gifts.message', 'love_gifts.sender_name', 'love_gifts.message_state', 'love_gifts.contact_shared', 'love_gifts.is_sandbox', 'love_gifts.sent_at', 'love_gifts.created_at', 'love_gifts.metadata_json']);
        $page->getCollection()->transform(function (LoveGift $g) {
            $row = $g->only(['id', 'public_id', 'platform_id', 'status', 'amount', 'currency', 'message', 'sender_name', 'message_state', 'contact_shared', 'is_sandbox', 'sent_at', 'created_at']);

            return $row + ['reported_at' => data_get($g->metadata_json, 'reported_at'), 'client' => $g->client?->only(['id', 'name']), 'payment_reference' => $g->payment?->reference_number];
        });

        return response()->json(['notes' => $page, 'counts' => $counts]);
    }

    /** Per-market setup and readiness for the workspace Setup tab. */
    public function markets(Request $r)
    {
        $this->authorizeLove($r);
        $settings = app(SettingsService::class);
        $system = app(\App\Services\MonetizationSettingsService::class)->system();

        return response()->json([
            'global_paused' => (bool) $system->send_love_kill_switch,
            'can_manage' => app(MarketAuthorizationService::class)->isManager($r->user()),
            'markets' => $this->marketsFor($r)->map(function (Platform $p) use ($settings) {
                $s = $settings->forPlatform($p);
                $environment = $s->rollout_mode === 'live' ? 'production' : 'sandbox';
                $providers = collect($s->allowed_providers_json ?? [])->map(function ($key) use ($p, $environment) {
                    try {
                        app(\App\Services\BillingModeService::class)->providerContext($p, $key, true, $environment, 'send_love');

                        return ['key' => $key, 'ready' => true, 'message' => null];
                    } catch (\Throwable $e) {
                        return ['key' => $key, 'ready' => false, 'message' => $e->getMessage()];
                    }
                })->values();
                $week = LoveGift::where('platform_id', $p->id)->where('status', 'sent')->where('is_sandbox', false)->where('sent_at', '>=', now()->subDays(7));

                return [
                    'platform' => ['id' => $p->id, 'name' => $p->name, 'currency' => $p->currency_code],
                    'enabled' => (bool) $s->enabled, 'rollout_mode' => $s->rollout_mode, 'kill_switch' => (bool) $s->kill_switch, 'currency' => $s->currency,
                    'config_revision' => (int) $s->config_revision, 'wp_revision' => (int) $s->wp_revision, 'in_sync' => (int) $s->wp_revision === (int) $s->config_revision,
                    'presets' => $s->presets_json ?? [], 'default_preset' => (int) $s->default_preset, 'creator_share_bps' => (int) $s->creator_share_bps,
                    'providers' => $providers, 'test_creators' => count($s->test_client_ids ?? []),
                    'gifts_7d' => (clone $week)->count(), 'gross_7d' => (float) (clone $week)->sum('amount'),
                ];
            })->values(),
        ]);
    }

    private function marketsFor(Request $r)
    {
        return app(MarketAuthorizationService::class)->applyPlatformScope(Platform::query(), $r->user(), 'platforms.id')
            ->whereIn('platforms.id', SendLoveSetting::query()->select('platform_id'))->orderBy('name')->get();
    }

    public function export(Request $r)
    {
        $q = $this->query($r);

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Creator', 'Status', 'Currency', 'Amount', 'Phone (masked)', 'Created']);
            foreach ($q->with(['client', 'payment'])->orderBy('id')->cursor() as $g) {
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+@-]/', (string) $v) ? "'".$v : $v, [$g->payment->reference_number, $g->client->name, $g->status, $g->currency, $g->amount, $g->visitor_phone_masked, $g->created_at]));
            }fclose($out);
        }, 'send-love.csv', ['Content-Type' => 'text/csv']);
    }

    public function action(Request $r, LoveGift $gift, string $action)
    {
        $this->authorizeLove($r, $gift->platform_id, true);
        $d = $r->validate(['reason' => 'required|string|min:5|max:1000', 'provider_reference' => 'nullable|string|max:120', 'wallet_adjustment_id' => 'nullable|integer']);

        return DB::transaction(function () use ($r, $gift, $action, $d) {
            $g = LoveGift::whereKey($gift->id)->lockForUpdate()->firstOrFail();
            if ($action === 'clear-contact') {
                $g->update(['contact_shared' => false, 'contact_phone_encrypted' => null]);
            } elseif ($action === 'remove-note') {
                $g->update(['message_state' => 'removed_by_staff', 'message' => null, 'sender_name' => null, 'contact_shared' => false, 'contact_phone_encrypted' => null]);
            } elseif ($action === 'refund') {
                $r->validate(['provider_reference' => 'required|string|max:120']);
                abort_unless(in_array($g->status, ['sent', 'review', 'refunded'], true), 409, 'Only settled gifts can be marked refunded.');
                if ($g->status === 'refunded') {
                    return response()->json(['saved' => true, 'replayed' => true]);
                }
                if ($g->wallet_transaction_id && ! $g->is_sandbox && (float) $g->creator_credit_amount > 0) {
                    $adjustment = WalletTransaction::whereKey($d['wallet_adjustment_id'] ?? 0)->where('client_id', $g->client_id)->where('type', 'debit')->where('currency_code', $g->currency)->where('reference_type', 'admin_adjustment')->where('created_at', '>=', $g->sent_at ?? $g->created_at)->lockForUpdate()->first();
                    abort_unless($adjustment && (float) $adjustment->amount === (float) $g->creator_credit_amount && ! LoveGift::where('metadata_json->refund.wallet_adjustment_id', $adjustment->id)->exists() && ! \App\Models\VisitorContentPurchase::where('metadata_json->refund.wallet_adjustment_id', $adjustment->id)->exists(), 422, 'Provide the matching PIN-approved wallet reversal before recording the refund.');
                }
                $g->update(['status' => 'refunded', 'contact_shared' => false, 'contact_phone_encrypted' => null, 'metadata_json' => array_merge($g->metadata_json ?? [], ['refund' => ['provider_reference' => $r->input('provider_reference'), 'wallet_adjustment_id' => $d['wallet_adjustment_id'] ?? null, 'actor_id' => $r->user()->id, 'at' => now()->toIso8601String()]])]);
            } else {
                abort(404);
            }
            PremiumContentEvent::create(['platform_id' => $g->platform_id, 'client_id' => $g->client_id, 'actor_id' => $r->user()->id, 'kind' => 'send_love_'.$action, 'reason' => $d['reason'], 'metadata_json' => ['gift_id' => $g->id]]);

            return response()->json(['saved' => true]);
        });
    }
}
