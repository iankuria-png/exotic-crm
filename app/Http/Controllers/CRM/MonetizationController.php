<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMonetizationPass;
use App\Models\ContentMonetizationSetting;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\PremiumContentAsset;
use App\Models\PremiumContentEvent;
use App\Models\PremiumContentOffer;
use App\Models\VisitorContentPurchase;
use App\Models\WalletTransaction;
use App\Services\MarketAuthorizationService;
use App\Services\Monetization\AccessService;
use App\Services\Monetization\FulfillmentService;
use App\Services\Monetization\StatsService;
use App\Services\Monetization\SyncService;
use App\Services\MonetizationSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonetizationController extends Controller
{
    public function __construct(private MarketAuthorizationService $markets, private MonetizationSettingsService $settings) {}

    private function authorizeMarket(Request $r, ?int $platform = null, bool $write = false): void
    {
        $this->markets->ensureRole($r->user(), $write ? ['admin', 'sub_admin'] : ['admin', 'sub_admin', 'sales']);
        if ($platform) {
            $this->markets->ensureUserCanAccessPlatform($r->user(), $platform);
        }
    }

    public function settings(Request $r)
    {
        $this->authorizeMarket($r, null, true);
        $platforms = $this->markets->applyPlatformScope(Platform::query(), $r->user(), 'id')->orderBy('name')->get(['id', 'name', 'currency_code']);
        $selected = $r->integer('platform_id') ?: $platforms->first()?->id;
        $this->authorizeMarket($r, $selected, true);
        $s = $selected ? $this->settings->forPlatform(Platform::findOrFail($selected)) : null;

        return response()->json(['supported_currencies' => $s ? (app(\App\Services\WalletSettingsService::class)->runtimePlatformConfig($s->platform)['supported_currencies'] ?? [$s->currency]) : [], 'platforms' => $platforms, 'system' => $this->settings->system(), 'market' => $s, 'effective' => $s ? $this->settings->runtime($s) : null, 'audit' => $selected ? PremiumContentEvent::where('platform_id', $selected)->where('kind', 'settings_changed')->latest()->limit(20)->get() : [], 'can_edit_system' => $r->user()->role === 'admin']);
    }

    public function saveSettings(Request $r, Platform $platform)
    {
        $this->authorizeMarket($r, $platform->id, true);
        $s = $this->settings->save($platform, $r->all(), $r->user()->id);
        $provision = app(SyncService::class)->provision($s);
        $sync = app(SyncService::class)->push($s);

        return response()->json(['market' => $s, 'effective' => $this->settings->runtime($s), 'sync' => $sync, 'credentials' => $provision]);
    }

    public function saveSystem(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $data = $r->validate(['enabled' => 'required|boolean', 'activation_kill_switch' => 'required|boolean', 'checkout_kill_switch' => 'required|boolean', 'config_revision' => 'required|integer', 'reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($data, $r) {
            $system = $this->settings->system()->newQuery()->lockForUpdate()->findOrFail(1);
            abort_unless((int) $system->config_revision === (int) $data['config_revision'], 409, 'Settings changed. Reload.');
            $before = $system->toArray();
            $system->update(collect($data)->only(['enabled', 'activation_kill_switch', 'checkout_kill_switch'])->all() + ['config_revision' => $system->config_revision + 1]);
            foreach (ContentMonetizationSetting::all() as $s) {
                PremiumContentEvent::create(['platform_id' => $s->platform_id, 'actor_id' => $r->user()->id, 'kind' => 'settings_changed', 'reason' => $data['reason'], 'metadata_json' => ['system_before' => $before, 'system_after' => $system->toArray()]]);
            }
        });
        $sync = [];
        foreach (ContentMonetizationSetting::all() as $s) {
            $sync[$s->platform_id] = app(SyncService::class)->push($s);
        }

        return response()->json(['system' => $this->settings->system(), 'sync' => $sync]);
    }

    public function sync(Request $r, Platform $platform)
    {
        $this->authorizeMarket($r, $platform->id, true);
        $s = $this->settings->forPlatform($platform);

        return response()->json(['credentials' => app(SyncService::class)->provision($s), 'sync' => app(SyncService::class)->push($s), 'readiness' => app(\App\Services\Monetization\ReadinessService::class)->check($s)]);
    }

    public function index(Request $r)
    {
        $platformId = $r->integer('platform_id') ?: null;
        $this->authorizeMarket($r, $platformId);
        $r->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|string|max:30',
            'payment_status' => 'nullable|string|max:30',
            'kind' => 'nullable|in:single,bundle',
            'provider' => 'nullable|string|max:40',
            'environment' => 'nullable|in:production,sandbox',
            'search' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|in:15,30,50',
        ]);
        $scope = function ($q) use ($r, $platformId) {
            $this->markets->applyPlatformScope($q, $r->user());
            if ($platformId) {
                $q->where('platform_id', $platformId);
            }
            if ($r->integer('client_id')) {
                $q->where('client_id', $r->integer('client_id'));
            }

            return $q;
        };
        $salesBase = $scope(VisitorContentPurchase::query())->when(! $r->boolean('include_tests'), fn ($q) => $q->where('is_sandbox', false));
        if ($r->filled('from')) {
            $salesBase->whereDate('created_at', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $salesBase->whereDate('created_at', '<=', $r->input('to'));
        }
        $statusCounts = (clone $salesBase)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $attempts = (clone $salesBase)->count();
        $successfulPayments = (clone $salesBase)->whereHas('payment', fn ($q) => $q->whereIn('status', Payment::SUCCESSFUL_STATUSES))->count();
        $summary = [
            'attempts' => $attempts,
            'successful_payments' => $successfulPayments,
            'completion_rate' => $attempts > 0 ? round(($successfulPayments / $attempts) * 100, 1) : 0,
            'active' => (int) ($statusCounts['active'] ?? 0),
            'failed' => (int) ($statusCounts['failed'] ?? 0),
            'pending' => (int) ($statusCounts['pending_payment'] ?? 0),
            'review' => (int) ($statusCounts['review'] ?? 0),
            'refunded' => (int) ($statusCounts['refunded'] ?? 0),
        ];
        $sales = clone $salesBase;
        if ($r->filled('status')) {
            $sales->where('status', $r->input('status'));
        }
        if ($r->filled('kind')) {
            $sales->where('offer_kind', $r->input('kind'));
        }
        if ($r->filled('payment_status')) {
            $sales->whereHas('payment', fn ($q) => $q->where('status', $r->input('payment_status')));
        }
        if ($r->filled('provider')) {
            $sales->whereHas('payment', fn ($q) => $q->where('provider_key', $r->input('provider')));
        }
        if ($r->filled('environment')) {
            $sales->whereHas('payment', fn ($q) => $q->where('provider_environment', $r->input('environment')));
        }
        if ($r->filled('search')) {
            $term = trim((string) $r->input('search'));
            $sales->where(function ($q) use ($term) {
                $q->where('public_id', 'like', "%{$term}%")
                    ->orWhere('visitor_phone_masked', 'like', "%{$term}%")
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('payment', fn ($payment) => $payment
                        ->where('reference_number', 'like', "%{$term}%")
                        ->orWhere('transaction_reference', 'like', "%{$term}%"));
            });
        }
        $totals = (clone $salesBase)->where('status', 'active')->selectRaw('currency, SUM(gross_amount) as gross_sales, SUM(provider_fee) as provider_fees, SUM(creator_credit_amount) as credited, COUNT(*) as sales_count, AVG(gross_amount) as average_sale')->groupBy('currency')->get();
        $passes = $scope(ClientMonetizationPass::query())->when(! $r->boolean('include_tests'), fn ($q) => $q->where('is_sandbox', false));
        if ($r->filled('from')) {
            $passes->whereDate('created_at', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $passes->whereDate('created_at', '<=', $r->input('to'));
        }
        $activation = (clone $passes)->selectRaw('currency, SUM(list_amount) as list_value, SUM(subsidy_amount) as subsidy, SUM(paid_amount) as revenue')->groupBy('currency')->get();
        $currentPasses = $scope(ClientMonetizationPass::query())->when(! $r->boolean('include_tests'), fn ($q) => $q->where('is_sandbox', false))->where('status', 'active')->where('expires_at', '>', now());
        $summary['active_sellers'] = (clone $currentPasses)->distinct()->count('client_id');
        $summary['expiring_sellers'] = (clone $currentPasses)->where('expires_at', '<=', now()->addDays(7))->distinct()->count('client_id');
        $providers = Payment::query()->whereIn('id', (clone $salesBase)->whereNotNull('payment_id')->select('payment_id'))->whereNotNull('provider_key')->distinct()->orderBy('provider_key')->pluck('provider_key')->values();
        $clientQuery = $this->markets->applyPlatformScope(Client::query(), $r->user())->where(function ($q) use ($passes, $r) {
            $q->whereIn('id', (clone $passes)->select('client_id'));
            if ($r->integer('client_id')) {
                $q->orWhere('id', $r->integer('client_id'));
            }
        });
        $creators = $clientQuery->limit(100)->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'platform_id' => $c->platform_id, 'stats' => app(StatsService::class)->owner($c), 'pass' => app(\App\Services\Monetization\PassService::class)->current($c)]);

        return response()->json(['summary' => $summary, 'providers' => $providers, 'wallet_attribution' => app(StatsService::class)->ledger($scope(WalletTransaction::query())), 'totals' => $totals, 'activation' => $activation, 'sales' => (clone $sales)->with(['client:id,name', 'payment:id,status,provider_key,provider_environment,reference_number,transaction_reference,failure_reason,completed_at'])->latest('id')->paginate($r->integer('per_page') ?: 30), 'creators' => $creators, 'content' => $scope(PremiumContentOffer::with(['assets', 'client:id,name']))->latest('id')->limit(100)->get(), 'safety' => $scope(PremiumContentEvent::query())->where('kind', '!=', 'settings_changed')->latest()->limit(100)->get(), 'setup' => $this->markets->applyPlatformScope(ContentMonetizationSetting::with('prices'), $r->user())->when($platformId, fn ($q) => $q->where('platform_id', $platformId))->get(), 'platforms' => $this->markets->applyPlatformScope(Platform::query(), $r->user(), 'id')->orderBy('name')->get(['id', 'name']), 'can_manage' => $this->markets->isManager($r->user())]);
    }

    public function export(Request $r)
    {
        $platform = $r->integer('platform_id') ?: null;
        $this->authorizeMarket($r, $platform);
        $r->validate([
            'surface' => 'required|in:sales,content',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'payment_status' => 'nullable|string|max:30',
            'provider' => 'nullable|string|max:40',
            'environment' => 'nullable|in:production,sandbox',
            'search' => 'nullable|string|max:120',
        ]);
        $sales = $r->input('surface') === 'sales';
        $q = $sales ? VisitorContentPurchase::query() : PremiumContentOffer::query();
        $this->markets->applyPlatformScope($q, $r->user());
        if ($platform) {
            $q->where('platform_id', $platform);
        }
        if ($r->integer('client_id')) {
            $q->where('client_id', $r->integer('client_id'));
        }
        if (! $r->boolean('include_tests')) {
            $q->where('is_sandbox', false);
        }
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }
        if ($r->filled('kind')) {
            $q->where($sales ? 'offer_kind' : 'kind', $r->input('kind'));
        }
        if ($sales && $r->filled('payment_status')) {
            $q->whereHas('payment', fn ($payment) => $payment->where('status', $r->input('payment_status')));
        }
        if ($sales && $r->filled('provider')) {
            $q->whereHas('payment', fn ($payment) => $payment->where('provider_key', $r->input('provider')));
        }
        if ($sales && $r->filled('environment')) {
            $q->whereHas('payment', fn ($payment) => $payment->where('provider_environment', $r->input('environment')));
        }
        if ($sales && $r->filled('search')) {
            $term = trim((string) $r->input('search'));
            $q->where(function ($purchase) use ($term) {
                $purchase->where('public_id', 'like', "%{$term}%")
                    ->orWhere('visitor_phone_masked', 'like', "%{$term}%")
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('payment', fn ($payment) => $payment
                        ->where('reference_number', 'like', "%{$term}%")
                        ->orWhere('transaction_reference', 'like', "%{$term}%"));
            });
        }
        if ($r->filled('from')) {
            $q->whereDate('created_at', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate('created_at', '<=', $r->input('to'));
        }

        return response()->streamDownload(function () use ($q, $sales) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Creator ID', 'Kind', 'Status', 'Currency', 'Amount', 'Created']);
            foreach ($q->orderBy('id')->cursor() as $row) {
                $fields = [$row->public_id, $row->client_id, $sales ? $row->offer_kind : $row->kind, $row->status, $row->currency, $sales ? $row->gross_amount : $row->amount, $row->created_at];
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+@-]/', (string) $v) ? "'".$v : (string) $v, $fields));
            }fclose($out);
        }, 'monetize-'.$r->input('surface').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function purchaseAction(Request $r, VisitorContentPurchase $purchase, string $action)
    {
        $this->authorizeMarket($r, $purchase->platform_id, true);
        $data = $r->validate(['reason' => 'required|string|min:5|max:1000', 'provider_reference' => 'required|string|max:120', 'wallet_adjustment_id' => 'nullable|integer', 'settled_amount' => 'nullable|numeric|min:0', 'settled_currency' => 'nullable|string|size:3']);
        DB::transaction(function () use ($r, $purchase, $action, $data) {
            $p = VisitorContentPurchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($action === 'refund') {
                abort_unless(in_array($p->status, ['active', 'refunded'], true), 409, 'Only settled purchases may be refunded.');
                if (! empty($data['wallet_adjustment_id'])) {
                    abort_if($p->is_sandbox, 422, 'Sandbox refunds cannot link a real wallet debit.');
                    abort_if(VisitorContentPurchase::where('id', '!=', $p->id)->where('metadata_json->refund->wallet_adjustment_id', (int) $data['wallet_adjustment_id'])->exists(), 422, 'This wallet adjustment is already linked to another refund.');
                    $t = WalletTransaction::lockForUpdate()->findOrFail($data['wallet_adjustment_id']);
                    abort_unless($t->client_id === $p->client_id && $t->type === 'debit' && $t->currency_code === $p->currency && $t->performed_by && $t->reference_type === 'admin_adjustment', 422, 'Link an existing staff wallet debit for this creator and currency.');
                }
                $p->update(['status' => 'refunded', 'metadata_json' => array_merge($p->metadata_json ?? [], ['refund' => $data])]);
            } else {
                abort_unless($p->status === 'review', 409, 'This purchase is not awaiting review.');
                abort_unless(isset($data['settled_amount'], $data['settled_currency']), 422, 'Enter the verified provider settlement amount and currency.');
                abort_unless((float) $data['settled_amount'] >= (float) $p->gross_amount && strtoupper($data['settled_currency']) === $p->currency, 422, 'The settlement must cover the full listed price in the purchase currency.');
                app(FulfillmentService::class)->complete($p->payment, [], ['settled_amount' => $data['settled_amount'], 'settled_currency' => $data['settled_currency']]);
            }
            PremiumContentEvent::create(['platform_id' => $p->platform_id, 'client_id' => $p->client_id, 'purchase_id' => $p->id, 'actor_id' => $r->user()->id, 'kind' => $action, 'reason' => $data['reason'], 'metadata_json' => $data]);
        });
        $purchase->refresh();
        $sync = $purchase->status === 'refunded' ? app(SyncService::class)->push($this->settings->forPlatform(Platform::findOrFail($purchase->platform_id)), ['revoked_purchases' => [$purchase->public_id]]) : null;

        return response()->json(['purchase' => $purchase, 'sync' => $sync]);
    }

    public function hold(Request $r, PremiumContentOffer $offer)
    {
        $this->authorizeMarket($r, $offer->platform_id, true);
        $data = $r->validate(['reason' => 'required|string|min:5|max:1000', 'held' => 'required|boolean']);
        $offer->update(['status' => $data['held'] ? 'held' : 'paused', 'paused_by' => $data['held'] ? 'staff' : 'owner']);
        PremiumContentEvent::create(['platform_id' => $offer->platform_id, 'client_id' => $offer->client_id, 'actor_id' => $r->user()->id, 'kind' => 'offer_hold', 'reason' => $data['reason'], 'metadata_json' => ['offer_id' => $offer->id, 'held' => $data['held']]]);
        if ($offer->client) {
            app(SyncService::class)->profile($offer->client);
        }

        return response()->json(['saved' => true]);
    }

    public function staffPass(Request $r, Client $client)
    {
        $this->authorizeMarket($r, $client->platform_id, true);
        $data = $r->validate(['action' => 'required|in:comp,debit,hold,release', 'duration_key' => 'required_if:action,comp,debit|in:2_weeks,1_month', 'reason' => 'required|string|min:5|max:1000', 'attempt' => 'required|uuid']);
        $pass = DB::transaction(function () use ($r, $client, $data) {
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', 'staff:'.$client->id.':'.$data['attempt']);
            if ($old = ClientMonetizationPass::where('idempotency_key_hash', $hash)->first()) {
                return $old;
            }
            $service = app(\App\Services\Monetization\PassService::class);
            $current = $service->current($client);
            if (in_array($data['action'], ['hold', 'release'], true)) {
                $passes = ClientMonetizationPass::where('client_id', $client->id)->where('expires_at', '>', now())->whereIn('status', $data['action'] === 'hold' ? ['active', 'queued'] : ['held'])->get();
                abort_if($passes->isEmpty(), 409, 'There is no current pass to change.');
                foreach ($passes as $p) {
                    $p->update(['status' => $data['action'] === 'hold' ? 'held' : ($p->starts_at->isFuture() ? 'queued' : 'active'), 'active_marker' => $data['action'] === 'release' && ! $p->starts_at->isFuture() ? 1 : null, 'hold_reason' => $data['action'] === 'hold' ? $data['reason'] : null]);
                }
                $pass = $passes->last();
            } else {
                $s = $this->settings->forPlatform($client->platform);
                $q = $service->quote($client, $data['duration_key']);
                if ($data['action'] === 'debit') {
                    $pass = $service->activate($client, ['duration_key' => $data['duration_key'], 'intent' => $current ? 'renew' : 'activate', 'expected_current_pass_id' => $current?->id, 'expected_expires_at' => $current?->expires_at?->toIso8601String(), 'expected_amount' => $q['payable_amount'], 'config_revision' => $q['config_revision']], $data['attempt']);
                } else {
                    abort_if(ClientMonetizationPass::where('client_id', $client->id)->where('status', 'held')->where('expires_at', '>', now())->exists(), 409, 'Release the creator hold first.');
                    $start = $current ? $current->expires_at->copy() : now();
                    ClientMonetizationPass::where('client_id', $client->id)->where('expires_at', '<=', now())->update(['status' => 'expired', 'active_marker' => null]);
                    $pass = ClientMonetizationPass::create(['client_id' => $client->id, 'platform_id' => $client->platform_id, 'price_id' => $q['price_id'], 'status' => $current ? 'queued' : 'active', 'active_marker' => $current ? null : 1, 'starts_at' => $start, 'expires_at' => $start->copy()->addDays($q['duration_days']), 'duration_key' => $q['duration_key'], 'duration_days' => $q['duration_days'], 'currency' => $q['currency'], 'list_amount' => $q['list_amount'], 'subsidy_amount' => $q['list_amount'], 'paid_amount' => 0, 'eligibility_snapshot_json' => $q + ['staff_comp' => true, 'actor_id' => $r->user()->id], 'is_sandbox' => $s->rollout_mode !== 'live', 'idempotency_key_hash' => $hash]);
                }
            }
            PremiumContentEvent::create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'actor_id' => $r->user()->id, 'kind' => 'staff_pass_'.$data['action'], 'reason' => $data['reason'], 'metadata_json' => ['pass_id' => $pass->id, 'attempt' => $data['attempt']]]);

            return $pass;
        }, 3);
        app(SyncService::class)->profile($client);

        return response()->json(['pass' => $pass]);
    }

    public function assetHold(Request $r, PremiumContentAsset $asset)
    {
        $this->authorizeMarket($r, $asset->platform_id, true);
        $data = $r->validate(['reason' => 'required|string|min:5|max:1000', 'held' => 'required|boolean']);
        DB::transaction(function () use ($asset, $data, $r) {
            $asset = PremiumContentAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            abort_if($asset->status === 'deleted', 409, 'This file has been deleted.');
            $asset->update(['status' => $data['held'] ? 'held' : 'ready', 'held_at' => $data['held'] ? now() : null]);
            PremiumContentEvent::create(['platform_id' => $asset->platform_id, 'client_id' => $asset->client_id, 'actor_id' => $r->user()->id, 'kind' => 'asset_hold', 'reason' => $data['reason'], 'metadata_json' => ['asset_id' => $asset->id, 'held' => $data['held']]]);
        });
        $s = $this->settings->forPlatform(Platform::findOrFail($asset->platform_id));
        $sync = app(SyncService::class)->push($s, ['assets' => [['public_id' => $asset->public_id, 'status' => $data['held'] ? 'held' : 'ready']]]);
        if ($client = Client::find($asset->client_id)) {
            app(SyncService::class)->profile($client);
        }

        return response()->json(['saved' => true, 'sync' => $sync]);
    }

    public function purgeAsset(Request $r, PremiumContentAsset $asset)
    {
        $this->authorizeMarket($r, $asset->platform_id, true);
        $r->validate(['reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($asset, $r) {
            if ($asset->client_id) {
                Client::whereKey($asset->client_id)->lockForUpdate()->firstOrFail();
            }
            $offers = PremiumContentOffer::whereHas('assets', fn ($q) => $q->where('premium_content_assets.id', $asset->id))->orderBy('id')->lockForUpdate()->get();
            $affected = VisitorContentPurchase::where('platform_id', $asset->platform_id)->whereIn('status', ['active', 'review', 'pending_payment'])->get()->contains(fn ($p) => collect($p->entitlement_snapshot_json)->contains('public_id', $asset->public_id));
            abort_if($affected, 409, 'Resolve all active entitlements and pending settlements before purging this item.');
            foreach ($offers as $offer) {
                $offer->update(['status' => 'retired', 'paused_by' => 'staff']);
            }
            $asset->update(['status' => 'deleted', 'deleted_at' => now()]);
            PremiumContentEvent::create(['platform_id' => $asset->platform_id, 'client_id' => $asset->client_id, 'actor_id' => $r->user()->id, 'kind' => 'asset_purge', 'reason' => $r->input('reason'), 'metadata_json' => ['asset_id' => $asset->id]]);
        });
        $sync = app(SyncService::class)->push($this->settings->forPlatform(Platform::findOrFail($asset->platform_id)), ['assets' => [['public_id' => $asset->public_id, 'status' => 'deleted', 'purge' => true]]]);

        return response()->json(['saved' => true, 'sync' => $sync]);
    }

    public function staffGrant(Request $r, PremiumContentAsset $asset)
    {
        $this->authorizeMarket($r, $asset->platform_id, true);
        $r->validate(['reason' => 'required|string|min:5|max:1000']);
        PremiumContentEvent::create(['platform_id' => $asset->platform_id, 'client_id' => $asset->client_id, 'actor_id' => $r->user()->id, 'kind' => 'staff_view', 'reason' => $r->input('reason'), 'metadata_json' => ['asset_id' => $asset->id]]);

        return response()->json(app(AccessService::class)->grant($this->settings->forPlatform(Platform::findOrFail($asset->platform_id)), $asset, null, null, $r->user()->id));
    }
}
