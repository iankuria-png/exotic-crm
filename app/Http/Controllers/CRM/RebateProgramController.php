<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\BillingSubscriptionRule;
use App\Models\Platform;
use App\Models\ProductPrice;
use App\Models\WalletRebate;
use App\Services\MarketAuthorizationService;
use App\Services\Rebates\RebateCalculator;
use App\Services\Rebates\RebateGrantService;
use App\Services\Rebates\RebateProgramService;
use App\Services\Rebates\RebateReportService;
use App\Services\WalletSyncService;
use Illuminate\Http\Request;

class RebateProgramController extends Controller
{
    public function __construct(private readonly RebateProgramService $programs, private readonly RebateReportService $reports) {}

    private function authorizeMarket(Request $r, Platform $market, bool $write = false): void
    {
        $auth = app(MarketAuthorizationService::class);
        $auth->ensureRole($r->user(), $write ? ['admin'] : ['admin', 'sub_admin']);
        $auth->ensureUserCanAccessPlatform($r->user(), (int) $market->id);
    }

    private function syncProgram(Platform $market): array
    {
        $s = $this->programs->forPlatform($market);
        $anchor = $s->rollout_mode === 'sandbox' ? \App\Models\Client::where('platform_id', $market->id)->whereIn('id', $s->test_client_ids ?? [])->first() : null;

        return app(WalletSyncService::class)->syncPlatformConfig($market, $anchor);
    }

    public function show(Request $r, Platform $market)
    {
        $this->authorizeMarket($r, $market);
        $s = $this->programs->forPlatform($market);
        $snapshot = $this->programs->snapshot($s);

        return response()->json(['market' => $market->only(['id', 'name', 'currency_code']), 'editable' => $r->user()->role === 'admin', 'program' => $s, 'published' => $snapshot,
            'conflict' => ['self_service_incentive' => data_get(BillingSubscriptionRule::where('market_id', $market->id)->first()?->discount_json, 'self_service_incentive')],
            'budget' => $this->reports->budget($market, $snapshot ?? $s->draft_json),
            'revisions' => $s->revisions()->latest('revision')->limit(20)->get(),
            'products' => ProductPrice::whereHas('product', fn ($q) => $q->where('platform_id', $market->id))->with('product:id,name,display_name')->get()]);
    }

    public function update(Request $r, Platform $market)
    {
        $this->authorizeMarket($r, $market, true);
        $d = $r->validate(['draft' => 'required|array', 'draft_revision' => 'required|integer']);
        $this->programs->save($market, $d['draft'], $d['draft_revision'], $r->user()->id);

        return $this->show($r, $market);
    }

    public function publish(Request $r, Platform $market)
    {
        $this->authorizeMarket($r, $market, true);
        $d = $r->validate(['draft_revision' => 'required|integer', 'reason' => 'required|string|min:5|max:1000']);
        $this->programs->publish($market, $d['draft_revision'], $d['reason'], $r->user()->id);
        $sync = $this->syncProgram($market);

        return response()->json(array_merge($this->show($r, $market)->getData(true), ['sync' => $sync]));
    }

    public function pause(Request $r, Platform $market)
    {
        $this->authorizeMarket($r, $market, true);
        $d = $r->validate(['paused' => 'required|boolean', 'reason' => 'required|string|min:5|max:1000']);
        $this->programs->pause($market, $d['paused'], $d['reason'], $r->user()->id);
        $sync = $this->syncProgram($market);

        return response()->json(array_merge($this->show($r, $market)->getData(true), ['sync' => $sync]));
    }

    public function simulate(Request $r, Platform $market)
    {
        $this->authorizeMarket($r, $market);
        $d = $r->validate(['scenario' => 'required|in:topup,activation,journey', 'amount' => 'required|numeric|min:0|max:100000000', 'activation_amount' => 'nullable|numeric|min:0|max:100000000', 'product_price_id' => 'nullable|integer', 'channel' => 'required|in:topup,wallet,self_checkout,manual_submission,staff_link,sales_assisted,auto_renew', 'first_topup' => 'boolean', 'earned_this_month' => 'numeric|min:0', 'use' => 'required|in:draft,published', 'draft' => 'nullable|array']);
        $s = $this->programs->forPlatform($market);
        $rules = $d['use'] === 'published' ? $this->programs->snapshot($s) : (isset($d['draft']) ? $this->programs->validate($market, $d['draft']) : $s->draft_json);
        abort_unless($rules, 422, 'Publish a program first.');
        $budget = $this->reports->budget($market, $rules);
        $d['budget_remaining'] = max(0, $budget['budget'] - $budget['issued']);
        if (! empty($d['product_price_id'])) {
            $price = ProductPrice::whereHas('product', fn ($q) => $q->where('platform_id', $market->id))->findOrFail($d['product_price_id']);
            abort_unless(strtoupper($price->currency) === $s->currency, 422, 'Choose a price in the program currency.');
            if ($d['scenario'] === 'journey') {
                $d['activation_amount'] = (float) $price->price;
            } else {
                $d['amount'] = (float) $price->price;
            }
        }
        $calc = app(RebateCalculator::class);
        if ($d['scenario'] === 'topup') {
            $d['channel'] = 'topup';
        }
        if ($d['scenario'] !== 'journey') {
            return response()->json($calc->quote($rules, $d));
        }
        $topup = $calc->quote($rules, array_merge($d, ['channel' => 'topup']));
        $activation = $calc->quote($rules, array_merge($d, ['amount' => $d['activation_amount'] ?? $d['amount'], 'channel' => $d['channel'] === 'topup' ? 'wallet' : $d['channel'], 'earned_this_month' => ($d['earned_this_month'] ?? 0) + $topup['total'], 'budget_remaining' => $d['budget_remaining'] - $topup['total']]));

        return response()->json(['total' => $topup['total'] + $activation['total'], 'lines' => array_merge($topup['lines'], $activation['lines']), 'capped_by' => $activation['capped_by'] ?? $topup['capped_by'], 'steps' => ['topup' => $topup, 'activation' => $activation]]);
    }

    public function performance(Request $r, Platform $market)
    {
        $this->authorizeMarket($r, $market);
        $s = $this->programs->forPlatform($market);

        return response()->json($this->reports->performance($market, $this->programs->snapshot($s) ?? $s->draft_json));
    }

    public function projection(Request $r, Platform $market)
    {
        $this->authorizeMarket($r, $market);
        $d = $r->validate(['draft' => 'required|array']);

        return response()->json($this->reports->performance($market, $this->programs->validate($market, $d['draft']))['projection']);
    }

    public function ledger(Request $r, Platform $market)
    {
        $this->authorizeMarket($r, $market);
        $d = $r->validate(['status' => 'nullable|in:credited,capped,skipped,simulated,failed,reversed', 'trigger' => 'nullable|in:topup,self_service,auto_renew,first_topup', 'channel' => 'nullable|in:topup,wallet,self_checkout,manual_submission,staff_link,sales_assisted,auto_renew', 'period' => 'nullable|date_format:Y-m', 'q' => 'nullable|string|max:120', 'page' => 'integer|min:1']);
        $q = WalletRebate::where('platform_id', $market->id)->with('client:id,name,phone_normalized');
        foreach (['status', 'trigger', 'channel', 'period' => 'period_key'] as $key => $column) {
            $field = is_int($key) ? $column : $key;
            if (! empty($d[$field])) {
                $q->where($column, $d[$field]);
            }
        }
        if (! empty($d['q'])) {
            $term = '%'.$d['q'].'%';
            $q->where(fn ($q) => $q->where('source_payment_id', $d['q'])->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone_normalized', 'like', $term)));
        }
        $page = $q->latest('id')->paginate(30);
        $page->getCollection()->transform(function ($row) {
            $data = $row->toArray();
            if ($data['client']) {
                $phone = (string) $data['client']['phone_normalized'];
                $data['client']['phone_masked'] = $phone ? substr($phone, 0, 3).'••••'.substr($phone, -3) : null;
                unset($data['client']['phone_normalized']);
            }

            return $data;
        });

        return response()->json($page);
    }

    public function reverse(Request $r, WalletRebate $rebate)
    {
        $this->authorizeMarket($r, Platform::findOrFail($rebate->platform_id), true);
        $d = $r->validate(['reason' => 'required|string|min:5|max:1000']);

        return response()->json(app(RebateGrantService::class)->reverse($rebate, $d['reason'], $r->user()->id));
    }
}
