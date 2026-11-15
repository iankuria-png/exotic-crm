<?php

namespace App\Services\FlexibleAmounts;

use App\Models\FlexiblePaymentReceipt;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\TemplateService;

class FlexibleReceiptService
{
    public function template(Platform $market, ?string $locale = null): string
    {
        return match ($locale ?: (strtolower($market->country) === 'senegal' ? 'fr' : 'en')) {
            'sw' => '{{market_name}}: Tumepokea {{currency}} {{amount}}. {{plan}} · siku {{days}} · hadi {{live_until}}. {{profile_url}}',
            'fr' => '{{market_name}} : {{currency}} {{amount}} reçus. {{plan}} · {{days}} jours · en ligne jusqu’au {{live_until}}. {{profile_url}}',
            default => '{{market_name}}: {{currency}} {{amount}} received. {{plan}} · {{days}} days · live until {{live_until}}. {{profile_url}}',
        };
    }

    public function record(Payment $payment): void
    {
        if ($payment->provider_environment === 'sandbox' || $payment->isClassifiedTest() || data_get($payment->payment_data, 'test_mode')
            || data_get($payment->payment_data, 'provisioning_status') !== 'completed' || ! $payment->deal?->expires_at) {
            return;
        }
        $phone = \App\Support\PhoneNormalizer::normalize($payment->client?->phone_normalized, $payment->platform->phone_prefix);
        $q = data_get($payment->payment_data, 'flexible_quote', []);
        $body = app(TemplateService::class)->renderString($this->template($payment->platform, data_get($payment->payment_data, 'flexible_quote.locale')), [
            'market_name' => $payment->platform->name, 'currency' => $payment->currency, 'amount' => $payment->amount, 'plan' => $q['plan'] ?? '',
            'days' => $q['days'] ?? '', 'live_until' => $payment->deal->expires_at->copy()->timezone($payment->platform->timezone ?: 'Africa/Nairobi')->format('j M Y'),
            'profile_url' => $payment->client?->wp_profile_url ?? ''])['content'];
        FlexiblePaymentReceipt::firstOrCreate(['payment_id' => $payment->id], ['phone' => $phone, 'body' => $body, 'state' => $phone ? 'pending' : 'needs_review', 'next_attempt_at' => now()]);
    }
}
