<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\{Client, ClientPayLink, PayLinkCharge};
use App\Services\{MarketAuthorizationService};
use App\Services\PayLink\{LinkService, SettingsService};
use Illuminate\Http\Request;

class ClientPayLinkController extends Controller
{
    private function authorizeClient(Request $r, Client $c): void
    {
        $auth = app(MarketAuthorizationService::class);
        $auth->ensureRole($r->user(), ['admin','sub_admin','sales','field_sales']);
        $auth->ensureUserCanAccessPlatform($r->user(), $c->platform_id);
        if ($r->user()->role === 'field_sales') abort_unless((int) $c->assigned_to === (int) $r->user()->id || (int) $c->created_by === (int) $r->user()->id, 403);
    }

    private function body(Client $c): array
    {
        $link = ClientPayLink::where('active_client_id', $c->id)->first();
        $stats = PayLinkCharge::where('client_id', $c->id)->selectRaw('COUNT(*) AS starts, SUM(payment_state = ?) AS paid, SUM(activation_state = ?) AS activated', ['completed','applied'])->first();
        return ['link' => $link ? ['url' => $link->url(), 'status' => $link->status, 'open_count' => $link->open_count, 'last_opened_at' => $link->last_opened_at] : null,
            'mode' => app(SettingsService::class)->forPlatform($c->platform)->rollout_mode, 'flexible_rules'=>app(\App\Services\FlexibleAmounts\FlexibleRulesService::class)->rules($c->platform), 'stats' => $stats ? ['starts' => (int)$stats->starts,'paid' => (int)$stats->paid,'activated' => (int)$stats->activated] : [],
            'unresolved' => PayLinkCharge::where('blocking_client_id',$c->id)->get(['public_ref','payment_id','payment_state','activation_state','review_reason','created_at'])];
    }

    public function show(Request $r, Client $client) { $this->authorizeClient($r,$client);return response()->json($this->body($client)); }
    public function mint(Request $r, Client $client)
    {
        $this->authorizeClient($r,$client);$d=$r->validate(['reason'=>'required|string|min:5|max:1000']);
        app(LinkService::class)->mint($client,$r->user()->id,$d['reason']);return response()->json($this->body($client));
    }
    public function suggestion(Request $r, Client $client)
    {
        $this->authorizeClient($r,$client);
        $data=$r->validate(['suggested_amount'=>'nullable|numeric|min:1|max:999999999']);
        $rules=app(\App\Services\FlexibleAmounts\FlexibleRulesService::class)->rules($client->platform);
        abort_unless($rules['mode']!=='off'&&$rules['surfaces']['crm_link'],409,'Flexible CRM links are off in this market.');
        $link=ClientPayLink::where('active_client_id',$client->id)->firstOrFail();
        $token=app(\App\Services\FlexibleAmounts\FlexibleLinkOfferService::class)->issue($link,isset($data['suggested_amount'])?(string)$data['suggested_amount']:null);
        return response()->json(['url'=>$link->url().'#offer='.rawurlencode($token),'suggested_amount'=>$data['suggested_amount']??null]);
    }

    /** Staff preview of what a suggested amount buys; the customer still confirms a fresh server quote. */
    public function quote(Request $r, Client $client)
    {
        $this->authorizeClient($r, $client);
        $data = $r->validate(['amount' => 'nullable|numeric|min:0|max:999999999']);
        $rules = app(\App\Services\FlexibleAmounts\FlexibleRulesService::class)->rules($client->platform);
        if ($rules['mode'] === 'off' || ! ($rules['surfaces']['crm_link'] ?? false)) {
            return response()->json(['available' => false, 'plans' => [], 'results' => []]);
        }
        $service = app(\App\Services\FlexibleAmounts\FlexibleQuoteService::class);
        $state = $service->state($client);
        $catalogue = $service->catalogue($client->platform, $client, 'crm_link', $state);
        $results = [];
        if (isset($data['amount']) && (float) $data['amount'] > 0) {
            foreach ($catalogue['plans'] as $plan) {
                try {
                    $q = $service->quote($client->platform, $client, ['product_id' => $plan['product_id'], 'amount' => (string) $data['amount']], 'crm_link', $state);
                    $results[] = ['product_id' => $plan['product_id'], 'plan' => $plan['plan'], 'tier' => $plan['tier'], 'valid' => (bool) $q['valid'], 'days' => $q['days'] ?? 0, 'charge' => $q['charge'] ?? null, 'ends_at' => $q['ends_at'] ?? null, 'package' => (bool) ($q['package'] ?? false), 'nudge' => $q['nudge'] ?? null];
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $results[] = ['product_id' => $plan['product_id'], 'plan' => $plan['plan'], 'tier' => $plan['tier'], 'valid' => false, 'message' => $e->getMessage()];
                }
            }
        }

        return response()->json(['available' => true, 'live' => $catalogue['live'] ?? false, 'current_tier' => $catalogue['current_tier'] ?? null, 'currency' => $catalogue['currency'] ?? $client->platform->currency_code,
            'min_days' => $catalogue['min_days'] ?? null, 'plans' => array_map(fn ($p) => ['product_id' => $p['product_id'], 'plan' => $p['plan'], 'tier' => $p['tier'], 'minimum_amount' => $p['minimum_amount'], 'quick_picks' => $p['quick_picks']], $catalogue['plans']), 'results' => $results]);
    }

    public function rotate(Request $r, Client $client) { return $this->change($r,$client,true); }
    public function revoke(Request $r, Client $client) { return $this->change($r,$client,false); }
    private function change(Request $r, Client $c, bool $rotate)
    {
        $this->authorizeClient($r,$c);$d=$r->validate(['reason'=>'required|string|min:5|max:1000']);
        app(LinkService::class)->revoke($c,$r->user()->id,$d['reason'],$rotate);return response()->json($this->body($c));
    }
}
