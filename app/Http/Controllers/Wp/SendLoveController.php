<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\LoveGift;
use App\Services\Monetization\ListingEligibility;
use App\Services\SendLove\CheckoutService;
use App\Services\SendLove\OwnerService;
use App\Services\SendLove\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SendLoveController extends Controller
{
    private function platform(Request $r)
    {
        return $r->attributes->get('wallet_platform');
    }

    private function owner(Request $r)
    {
        return app(ListingEligibility::class)->assertOwner($this->platform($r)->id, (int) $r->input('wp_post_id'), (int) $r->input('wp_user_id'));
    }

    public function config(Request $r)
    {
        $service = app(SettingsService::class);
        $s = $service->forPlatform($this->platform($r));
        $s->update(['heartbeat_at' => now(), 'wp_revision' => (int) $r->input('cached_revision', 0)]);

        return response()->json(['effective' => $service->runtime($s)]);
    }

    public function intent(Request $r)
    {
        return response()->json(app(CheckoutService::class)->intent($this->platform($r), $r->all(), (string) $r->header('X-Idempotency-Key'), $r));
    }

    public function resend(Request $r, string $ref)
    {
        return response()->json(app(CheckoutService::class)->resend($this->platform($r), $ref, (string) $r->input('session_proof'), $r));
    }

    public function status(Request $r, string $ref)
    {
        return response()->json(app(CheckoutService::class)->status($this->platform($r), $ref, (string) $r->input('session_proof')));
    }

    public function summary(Request $r)
    {
        return response()->json(app(OwnerService::class)->summary($this->owner($r)));
    }

    public function seen(Request $r)
    {
        $c = $this->owner($r);
        $d = $r->validate(['gift_ids' => 'present|array|max:50', 'gift_ids.*' => 'uuid']);
        LoveGift::where('client_id', $c->id)->where('status', 'sent')->whereIn('public_id', $d['gift_ids'])->whereNull('seen_at')->update(['seen_at' => now()]);

        return response()->json(['seen' => true]);
    }

    public function hide(Request $r, string $ref)
    {
        $c = $this->owner($r);
        $g = LoveGift::where('client_id', $c->id)->where('public_id', $ref)->where('status', 'sent')->firstOrFail();
        $g->update(['message_state' => $g->message_state === 'removed_by_staff' ? 'removed_by_staff' : 'hidden_by_creator']);

        return response()->json(['hidden' => true]);
    }

    public function report(Request $r, string $ref)
    {
        $c = $this->owner($r);
        $g = LoveGift::where('client_id', $c->id)->where('public_id', $ref)->where('status', 'sent')->firstOrFail();
        $g->update(['message_state' => $g->message_state === 'removed_by_staff' ? 'removed_by_staff' : 'hidden_by_creator', 'metadata_json' => array_merge($g->metadata_json ?? [], ['reported_at' => now()->toIso8601String()])]);
        \App\Models\PremiumContentEvent::create(['platform_id' => $c->platform_id, 'client_id' => $c->id, 'kind' => 'send_love_note_reported', 'metadata_json' => ['gift_id' => $g->id]]);

        return response()->json(['reported' => true]);
    }

    public function visibility(Request $r)
    {
        $c = $this->owner($r);
        $r->validate(['visible' => 'required|boolean']);
        DB::table('send_love_visibility')->updateOrInsert(['client_id' => $c->id], ['visible' => $r->boolean('visible'), 'updated_at' => now()]);

        return response()->json(['visible' => $r->boolean('visible')]);
    }

    public function simulate(Request $r, string $ref)
    {
        abort_unless(app()->environment('local') && config('monetization.local_simulator'), 404);
        app(CheckoutService::class)->status($this->platform($r), $ref, (string) $r->input('session_proof'));
        $g = LoveGift::where('platform_id', $this->platform($r)->id)->where('public_id', $ref)->firstOrFail();
        abort_unless($g->is_sandbox && $g->payment->isSandboxTest(), 403);
        $d = $r->validate(['outcome' => 'required|in:success,failed,review']);
        if ($d['outcome'] === 'failed') {
            abort_unless($g->status === 'pending_payment', 409);
            $g->payment->update(['status' => 'failed', 'failure_reason' => 'cancelled_by_user']);
            $g->update(['status' => 'failed']);
        } else {
            app(\App\Services\PaymentCompletionService::class)->complete($g->payment, $d['outcome'] === 'success' ? ['amount' => $g->amount, 'currency' => $g->currency] : []);
        }

        return response()->json(['status' => $g->fresh()->status]);
    }
}
