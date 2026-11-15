<?php

namespace App\Services\FlexibleAmounts;

use App\Models\AuditLog;
use App\Models\BillingSubscriptionRule;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FlexibleRulesService
{
    public function defaults(Platform $market): array
    {
        return ['mode' => 'off', 'uplift_percent' => 10, 'min_days' => 3, 'max_days' => 90,
            'rounding_step' => match (strtoupper($market->currency_code)) {
                'CFA','XOF' => 25,'UGX' => 500,default => 10
            },
            'surfaces' => ['dashboard' => false, 'pay_link' => false, 'crm_link' => false], 'rescue_enabled' => false,
            'rules_version' => 0, 'updated_by' => null, 'updated_at' => null];
    }

    public function rules(Platform $market): array
    {
        $saved = BillingSubscriptionRule::where('market_id', $market->id)->value('flexible_amounts');
        if (is_string($saved)) {
            $saved = json_decode($saved, true);
        }

        return array_replace_recursive($this->defaults($market), is_array($saved) ? $saved : []);
    }

    public function validate(Platform $market, array $input): array
    {
        $v = Validator::make($input, [
            'mode' => 'required|in:off,both,flex', 'uplift_percent' => 'required|numeric|min:0|max:1000|decimal:0,2',
            'min_days' => 'required|integer|min:3|max:365', 'max_days' => 'required|integer|min:3|max:365|gte:min_days',
            'rounding_step' => 'required|numeric|min:1|max:1000000|decimal:0,2', 'surfaces' => 'required|array:dashboard,pay_link,crm_link',
            'surfaces.dashboard' => 'required|boolean', 'surfaces.pay_link' => 'required|boolean', 'surfaces.crm_link' => 'required|boolean',
            'rescue_enabled' => 'required|boolean', 'rules_version' => 'required|integer|min:0',
        ])->validate();

        return array_replace($this->defaults($market), $v);
    }

    /** Fixed pilot scope is explicit; catalogue currency aliases remain native for reporting. */
    public function catalogue(Platform $market, bool $lock = false): array
    {
        $products = Product::where('platform_id', $market->id)->orderBy('id')->limit(101);
        if ($lock) {
            $products->lockForUpdate();
        }
        $out = [];
        $productRows = $products->get();
        abort_if($productRows->count() > 100, 422, 'This catalogue needs a team check before flexible pricing can be configured.');
        foreach ($productRows as $product) {
            if (! $product->is_active || ! $product->is_public || $product->is_archived) {
                continue;
            }
            $tier = app(\App\Services\DealPaymentService::class)->derivePlanTypeFromProduct($product);
            $named = strtolower(trim($product->name));
            $issue = in_array($named, ['basic', 'premium', 'vip', 'vvip'], true) && $named !== $tier ? 'Plan name and activation tier disagree; review the catalogue.' : null;
            $prices = ProductPrice::where('product_id', $product->id)->orderBy('id')->limit(101);
            if ($lock) {
                $prices->lockForUpdate();
            }
            $priceRows = $prices->get();
            abort_if($priceRows->count() > 100, 422, 'This plan has too many prices for flexible checkout.');
            $all = $priceRows->map(fn ($p) => ['product_price_id' => $p->id, 'days' => (int) $p->duration_days, 'price' => (string) $p->price, 'currency' => strtoupper($p->currency), 'active' => (bool) $p->is_active, 'duration_key' => $p->duration_key])->all();
            $active = array_values(array_filter($all, fn ($p) => $p['active'] && FlexiblePricingEngine::minor($p['price']) > 0 && $p['currency'] === strtoupper($market->currency_code)));
            $monthly = collect($active)->where('days', 30)->sortBy(fn ($p) => FlexiblePricingEngine::minor($p['price']))->first();
            if (! $monthly) {
                $issue = 'No active 1-month price in the market currency.';
            }
            $packages = array_values(array_filter($active, fn ($p) => in_array($p['days'], [7, 14, 30], true)));
            $out[] = ['product_id' => $product->id, 'plan' => match ($tier) {
                'vip' => 'VIP', 'vvip' => 'VVIP', 'premium' => 'Premium', 'basic' => 'Basic', default => $product->display_name ?: $product->name
            }, 'tier' => $tier, 'currency' => strtoupper($market->currency_code),
                'monthly_price' => $monthly['price'] ?? null, 'base_product_price_id' => $monthly['product_price_id'] ?? null, 'packages' => $packages,
                'custom_prices' => array_values(array_filter($all, fn ($p) => $p['active'] && ! in_array($p['days'], [7, 14, 30], true))),
                'eligible' => $issue === null, 'issue' => $issue, 'all_prices' => $all];
        }

        return $out;
    }

    public function checks(Platform $market, array $rules, bool $lock = false): array
    {
        $rows = [];
        $minimum = 0;
        $passes = true;
        foreach ($this->catalogue($market, $lock) as $plan) {
            $check = $plan;
            unset($check['all_prices']);
            if ($plan['eligible']) {
                $engine = new FlexiblePricingEngine($plan, $rules);
                $test = $engine->checks();
                $minimum = max($minimum, $test['minimum_uplift_percent']);
                $passes = $passes && $test['passes'];
                $check += ['checks' => $test, 'minimum_purchase' => $engine->exact((int) $rules['min_days'])['charge'],
                    'flex_7' => FlexiblePricingEngine::money($engine->flexPrice(7)),
                    'example' => $engine->quote(match (strtoupper($market->currency_code)) {
                        'CFA','XOF' => 5000,'UGX' => 50000,default => 1000
                    })];
            }
            $rows[] = $check;
        }

        return ['passes' => $passes, 'minimum_uplift_percent' => $minimum, 'plans' => $rows];
    }

    public function save(Platform $market, array $input, int $actor): array
    {
        $rules = $this->validate($market, $input);

        return DB::transaction(function () use ($market, $rules, $actor) {
            // Lock the market even when its subscription-rule row does not exist yet.
            Platform::whereKey($market->id)->lockForUpdate()->firstOrFail();
            $row = BillingSubscriptionRule::firstOrCreate(['market_id' => $market->id]);
            $row = BillingSubscriptionRule::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $before = $row->flexible_amounts ?: $this->defaults($market);
            abort_unless((int) ($before['rules_version'] ?? 0) === (int) $rules['rules_version'], 409, 'Settings changed. Reload before saving.');
            $checks = $this->checks($market, $rules, true);
            if (! $checks['passes']) {
                throw ValidationException::withMessages(['flexible_amounts.uplift_percent' => 'Packages would be undercut. Minimum passing uplift: '.$checks['minimum_uplift_percent'].'%.']);
            }
            $after = array_replace($rules, ['rules_version' => $rules['rules_version'] + 1, 'updated_by' => $actor, 'updated_at' => now()->toIso8601String()]);
            $row->update(['flexible_amounts' => $after]);
            abort_unless(app(AuditService::class)->record(['platform_id' => $market->id, 'actor_id' => $actor, 'entity_type' => 'flexible_amounts', 'entity_id' => $row->id, 'action' => 'updated', 'reason' => 'Updated market flexible amount rules', 'before_state' => $before, 'after_state' => $after]), 503, 'Could not record the settings audit.');

            return $after;
        });
    }

    public function summary(Platform $market, ?array $draft = null): array
    {
        $rules = $draft ? $this->validate($market, $draft) : $this->rules($market);
        $q = Payment::where('platform_id', $market->id)->where('created_at', '>=', now()->subDays(30))->reportableSuccessful()->where('purpose', 'subscription');
        $total = (clone $q)->whereHas('deal', fn ($deal) => $deal->where('status', 'active'))->count();
        $flex = (clone $q)->where('payment_data->pricing_mode', 'flexible');
        $metrics = (clone $flex)->selectRaw('currency,COUNT(*) AS payment_count,SUM(amount) AS paid_amount')->groupBy('currency')->get()->toArray();
        $count = (clone $flex)->count();

        return ['rules' => $rules, 'live_check' => $this->checks($market, $rules), 'market' => ['id' => $market->id, 'name' => $market->name, 'currency' => $market->currency_code],
            'last_changed_by' => ! empty($rules['updated_by']) ? \App\Models\User::whereKey($rules['updated_by'])->value('name') : null,
            'last_30_days' => ['payments' => $count, 'activation_share' => $total ? round((clone $flex)->whereHas('deal', fn ($deal) => $deal->where('status', 'active'))->count() / $total * 100, 2) : 0, 'amounts' => $metrics, 'rescue_conversions' => (clone $flex)->whereNotNull('payment_data->flexible_quote->rescue_payment_id')->count()],
            'history' => AuditLog::where('platform_id', $market->id)->where('entity_type', 'flexible_amounts')->orderByDesc('id')->limit(20)->get(['id', 'actor_id', 'created_at', 'after_state'])];
    }
}
