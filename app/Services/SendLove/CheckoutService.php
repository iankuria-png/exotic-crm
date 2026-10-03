<?php

namespace App\Services\SendLove;

use App\Models\Client;
use App\Models\LoveGift;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\SendLoveSetting;
use App\Services\BillingGatewayService;
use App\Services\ClientSyncService;
use App\Services\Monetization\ListingEligibility;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public const LINES = [1 => ['A little something sweet', 'Coffee’s on me', 'Just because'], 2 => ['Spoil yourself', 'Buy something nice', 'Go on, treat yourself'], 3 => ['You can’t finish my money', 'Spoil yourself properly', 'Buy yourself something lovely'], 4 => ['Chop money, chop life!']];

    public const PLAIN_LINES = [1 => 'A little something sweet', 2 => 'Spoil yourself', 3 => 'Spoil yourself properly', 4 => 'Something special for you'];

    public static function tier(int $amount): int
    {
        return $amount < 500 ? 1 : ($amount < 1000 ? 2 : ($amount < 5000 ? 3 : 4));
    }

    public function device(SendLoveSetting $s, string $proof): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $proof), 422, 'Reload and try again.');

        return hash_hmac('sha256', $proof, $s->device_pepper);
    }

    public function intent(Platform $p, array $in, string $attempt, Request $r): array
    {
        abort_unless($attempt && strlen($attempt) <= 128, 422, 'Reload and try again.');
        $d = Validator::make($in, ['wp_post_id' => 'required|integer|min:1', 'session_proof' => 'required|string|size:64', 'amount' => 'required|integer', 'card_line' => 'required|string|max:60', 'visitor_phone' => 'required|string|max:24', 'provider_key' => 'required|in:kopokopo,pawapay', 'message' => 'nullable|string|max:140', 'sender_name' => 'nullable|string|max:24', 'share_contact' => 'sometimes|boolean', 'test_device' => 'sometimes|boolean'])->validate();
        $s = app(SettingsService::class)->forPlatform($p);
        $device = $this->device($s, $d['session_proof']);
        $hash = hash('sha256', $p->id.':'.$device.':'.$attempt);
        $client = Client::where('platform_id', $p->id)->where('wp_post_id', $d['wp_post_id'])->first();
        if (! $client) {
            $client = (new ClientSyncService($p))->syncOne($d['wp_post_id']);
        }
        abort_unless($client, 409, 'This profile is still syncing. Try again in a moment.');
        $created = false;
        $gift = DB::transaction(function () use ($p, $s, $d, $device, $hash, $client, &$created) {
            // Market lock serializes daily cap checks and idempotency across concurrent attempts.
            $s = SendLoveSetting::whereKey($s->id)->lockForUpdate()->firstOrFail();
            if ($old = LoveGift::where('idempotency_key_hash', $hash)->first()) {
                abort_unless($old->wp_post_id == $d['wp_post_id'], 409, 'This attempt belongs to another creator.');

                return $old;
            }
            app(SettingsService::class)->assertCommerce($s);
            $facts = app(ListingEligibility::class)->facts($client);
            abort_unless($facts['listing_active'] && ! $facts['held'] && DB::table('send_love_visibility')->where('client_id', $client->id)->where('visible', false)->doesntExist(), 409, 'This creator is not receiving love right now.');
            $sandbox = $s->rollout_mode === 'sandbox';
            abort_if($sandbox && ! in_array($client->id, $s->test_client_ids ?? [], true) && empty($d['test_device']), 409, 'Send love is not available on this profile yet.');
            if ($d['amount'] < $s->custom_min || $d['amount'] > $s->custom_max) {
                throw ValidationException::withMessages(['amount' => ['Choose an amount between '.$s->custom_min.' and '.$s->custom_max.'.']]);
            }
            $tier = self::tier($d['amount']);
            $lines = data_get($s->copy_policy_json, 'playful_copy', true) ? self::LINES[$tier] : [self::PLAIN_LINES[$tier]];
            abort_unless(in_array($d['card_line'], $lines, true), 422, 'Choose a card line for this amount.');
            foreach (['message', 'sender_name'] as $field) {
                $d[$field] = trim($d[$field] ?? '');
                if (preg_match('/(?:https?:|www\.|@|[\p{L}\d-]+\.[\p{L}]{2,63}\b|(?:\+?\d[\s().-]*){7,})/iu', $d[$field])) {
                    throw ValidationException::withMessages([$field => ["Notes can't include numbers or links"]]);
                }
                if ($d[$field] !== strip_tags($d[$field])) {
                    throw ValidationException::withMessages([$field => ['Use plain text in your note.']]);
                }
            }
            abort_if(! data_get($s->message_policy_json, 'enabled', true) && $d['message'], 422, 'Notes are unavailable right now.');
            abort_if(mb_strlen($d['message']) > data_get($s->message_policy_json, 'max_len', 140), 422, 'Your note is too long.');
            abort_if(! data_get($s->message_policy_json, 'sender_name', true) && $d['sender_name'], 422, 'Sign-offs are unavailable right now.');
            abort_unless(in_array($d['provider_key'], $s->allowed_providers_json ?? [], true), 422, 'Choose an available payment provider.');
            $phone = PhoneNormalizer::normalize($d['visitor_phone'], (string) $p->phone_prefix);
            abort_unless($phone && preg_match('/^\d{9,15}$/D', $phone), 422, 'Enter the mobile money number you will pay with.');
            $phoneHash = hash_hmac('sha256', $phone, $s->device_pepper);
            foreach (['device:'.$device, 'phone:'.$phoneHash] as $subject) {
                $key = 'send-love:'.$p->id.':'.$subject;
                abort_if(RateLimiter::tooManyAttempts($key, (int) data_get($s->limits_json, 'attempts_per_10min', 5)), 429, 'Too many payment requests. Please wait before trying again.');
                RateLimiter::hit($key, 600);
            }
            $used = LoveGift::where('platform_id', $p->id)->where('visitor_phone_hash', $phoneHash)->where('created_at', '>=', now()->startOfDay())->whereIn('status', ['sent', 'pending_payment', 'review'])->sum('amount');
            abort_if($used + $d['amount'] > data_get($s->limits_json, 'per_phone_daily_amount', 50000), 429, 'You have reached today’s gifting limit.');
            $reference = 'LV-'.now()->format('ymd').'-'.strtoupper(Str::random(8));
            $payment = Payment::create(['platform_id' => $p->id, 'client_id' => null, 'user_id' => null, 'escort_post_id' => null, 'phone' => $phone, 'amount' => $d['amount'], 'currency' => $s->currency, 'transaction_uuid' => Str::uuid(), 'transaction_reference' => $reference, 'reference_number' => $reference, 'status' => 'initiated', 'purpose' => Payment::PURPOSE_SEND_LOVE, 'source' => 'website_send_love', 'provider_key' => $d['provider_key'], 'provider_environment' => $sandbox ? 'sandbox' : 'production', 'payment_data' => ['billing_surface' => 'send_love', 'test_mode' => $sandbox]]);
            $creditMinor = intdiv($d['amount'] * 100 * $s->creator_share_bps + 5000, 10000);
            $shared = ! empty($d['share_contact']);
            $created = true;

            return LoveGift::create(['public_id' => Str::uuid(), 'platform_id' => $p->id, 'client_id' => $client->id, 'wp_post_id' => $client->wp_post_id, 'payment_id' => $payment->id, 'amount' => $d['amount'], 'currency' => $s->currency, 'tier' => $tier, 'card_line' => $d['card_line'], 'creator_credit_amount' => $creditMinor / 100, 'platform_share_amount' => $d['amount'] - $creditMinor / 100, 'message' => $d['message'] ?: null, 'sender_name' => $d['sender_name'] ?: null, 'visitor_phone_hash' => $phoneHash, 'visitor_phone_masked' => str_repeat('*', strlen($phone) - 4).substr($phone, -4), 'device_hash' => $device, 'idempotency_key_hash' => $hash, 'is_sandbox' => $sandbox, 'contact_shared' => $shared, 'contact_consent_at' => $shared ? now() : null, 'contact_phone_encrypted' => $shared ? $phone : null]);
        }, 3);
        $action = data_get($gift->metadata_json, 'action');
        if ($created) {
            if (app()->environment('local') && config('monetization.local_simulator') && $gift->is_sandbox) {
                $action = ['type' => 'local_sandbox'];
            } else {
                try {
                    $result = app(BillingGatewayService::class)->initiateSendLove($gift->payment, $d['provider_key'], ['environment' => $gift->is_sandbox ? 'sandbox' : 'production', 'description' => 'Exotic gift'], $r);
                    $action = $result['action'] ?? $result;
                    unset($action['provider_payload']);
                } catch (\Throwable $e) {
                    report($e);
                    $payment = $gift->payment->fresh();
                    // Transport errors are uncertain: only provider callbacks can say no charge.
                    if ($payment->status === 'failed' && ! $payment->completed_at) {
                        $payment->update(['status' => 'pending', 'failure_reason' => null]);
                    }
                    $action = ['type' => 'pending'];
                }
            }
            $gift->update(['metadata_json' => ['action' => $action]]);
        }

        return ['gift_reference' => (string) $gift->public_id, 'status' => $gift->status, 'payment' => ['reference' => $gift->payment->reference_number, 'amount' => $gift->amount, 'currency' => $gift->currency, 'statement_payee' => 'Exotic'], 'action' => $action, 'replayed' => ! $created];
    }

    /** Reserve a retry before network I/O; reuse the payment and its provider identity. */
    public function resend(Platform $p, string $ref, string $proof, Request $r): array
    {
        $this->status($p, $ref, $proof);
        $s = app(SettingsService::class)->forPlatform($p);
        app(SettingsService::class)->assertCommerce($s);
        $gift = DB::transaction(function () use ($p, $ref) {
            $g = LoveGift::where('platform_id', $p->id)->where('public_id', $ref)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($g->payment_id)->lockForUpdate()->firstOrFail();
            abort_unless($g->status === 'pending_payment' && in_array($payment->status, ['initiated', 'pending'], true), 409, 'This payment is already being resolved.');
            $meta = $g->metadata_json ?? [];
            $last = isset($meta['resend_at']) ? \Illuminate\Support\Carbon::parse($meta['resend_at']) : $g->created_at;
            abort_if($last->gt(now()->subSeconds(30)), 429, 'Please wait before requesting another prompt.');
            abort_if((int) ($meta['resend_count'] ?? 0) >= 4 || $g->created_at->lt(now()->subMinutes(30)), 429, 'The prompt retry window has ended.');
            $g->update(['metadata_json' => array_merge($meta, ['resend_at' => now()->toIso8601String(), 'resend_count' => (int) ($meta['resend_count'] ?? 0) + 1])]);

            return $g;
        });
        $action = data_get($gift->metadata_json, 'action', []);
        if (! ($gift->is_sandbox && app()->environment('local') && config('monetization.local_simulator'))) {
            // Hosted pawaPay resumes the same URL/deposit; never creates another deposit.
            if ($gift->payment->provider_key === 'kopokopo') {
                try {
                    $result = app(BillingGatewayService::class)->initiateSendLove($gift->payment, 'kopokopo', ['environment' => $gift->is_sandbox ? 'sandbox' : 'production', 'retry' => true], $r);
                } catch (\Throwable $e) {
                    report($e);
                    $payment = $gift->payment->fresh();
                    if ($payment->status === 'failed' && ! $payment->completed_at) {
                        $payment->update(['status' => 'pending', 'failure_reason' => null]);
                    }
                    $result = ['action' => ['type' => 'pending']];
                }
                $action = $result['action'] ?? $result;
                unset($action['provider_payload']);
                $gift->refresh()->update(['metadata_json' => array_merge($gift->metadata_json ?? [], ['action' => $action])]);
            }
        }

        return ['gift_reference' => (string) $gift->public_id, 'status' => $gift->fresh()->status, 'action' => $action];
    }

    public function status(Platform $p, string $ref, string $proof): array
    {
        $s = app(SettingsService::class)->forPlatform($p);
        $device = $this->device($s, $proof);
        $gift = LoveGift::where('platform_id', $p->id)->where('public_id', $ref)->where('device_hash', $device)->firstOrFail();
        // Provider callbacks own success. A failed provider state can safely finish a pending gift.
        if ($gift->status === 'pending_payment' && $gift->payment->status === 'failed') {
            $gift->update(['status' => 'failed']);
        }

        return ['status' => $gift->status, 'failure' => $gift->status === 'failed' ? ($gift->payment->failure_reason ?: 'timeout') : null];
    }
}
