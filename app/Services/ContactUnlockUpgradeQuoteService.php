<?php

namespace App\Services;

use App\Models\ContactUnlockPricingRule;
use App\Models\ContactUnlockUpgradeQuote;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\VisitorContactUnlock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ContactUnlockUpgradeQuoteService
{
    public const CREDIT_WINDOW_DAYS = 7;
    private const QUOTE_TTL_MINUTES = 10;

    public function __construct(
        private readonly ContactUnlockPricingService $pricingService
    ) {}

    public function quote(
        Platform $platform,
        int $wpPostId,
        int $pricingRuleId,
        array $publicTokens,
        ?string $visitorPhone,
        string $sessionProof
    ): array {
        if (! $this->pricingService->enabledForPlatform($platform)) {
            throw ValidationException::withMessages(['platform' => 'Contact unlock is disabled for this market.']);
        }

        $client = Client::query()
            ->where('platform_id', (int) $platform->id)
            ->where('wp_post_id', $wpPostId)
            ->first();
        if (! $client || ! $client->isPubliclyRestricted()) {
            throw ValidationException::withMessages(['wp_post_id' => 'Upgrade credit is only available from an inactive profile.']);
        }

        $rule = $this->pricingService->findActiveRule($platform, $pricingRuleId);
        if ((string) $rule->scope !== VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES) {
            throw ValidationException::withMessages(['pricing_rule_id' => 'Upgrade credit is only available for all-access unlocks.']);
        }

        $sessionHash = $this->hashToken($sessionProof);
        $phoneHash = $this->phoneHash($visitorPhone, (string) ($platform->phone_prefix ?? ''));
        $tokenHashes = collect($publicTokens)
            ->filter(fn ($token) => is_scalar($token) && trim((string) $token) !== '')
            ->map(fn ($token) => $this->hashToken(trim((string) $token)))
            ->unique()
            ->values()
            ->all();

        $sources = $this->eligibleSources($platform, $rule, $sessionHash, $tokenHashes, $phoneHash, false)->get();
        $amounts = $this->calculateAmounts($rule, $sources);
        $quoteToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        ContactUnlockUpgradeQuote::query()->create([
            'platform_id' => (int) $platform->id,
            'pricing_rule_id' => (int) $rule->id,
            'quote_token_hash' => $this->hashToken($quoteToken),
            'session_token_hash' => $sessionHash,
            'visitor_phone_hash' => $phoneHash,
            'currency' => strtoupper((string) $rule->currency),
            'full_access_amount' => $amounts['full_access_amount'],
            'eligible_credit' => $amounts['eligible_credit'],
            'amount_due' => $amounts['amount_due'],
            'credit_window_days' => self::CREDIT_WINDOW_DAYS,
            'credit_sources_json' => $this->serializeSources($sources),
            'expires_at' => now()->addMinutes(self::QUOTE_TTL_MINUTES),
        ]);

        return [
            'eligible' => true,
            'quote_token' => $quoteToken,
            'currency' => strtoupper((string) $rule->currency),
            'full_access_amount' => $amounts['full_access_amount'],
            'eligible_credit' => $amounts['eligible_credit'],
            'amount_due' => $amounts['amount_due'],
            'credit_window_days' => self::CREDIT_WINDOW_DAYS,
            'credit_sources' => $this->serializeSources($sources),
            'copy' => $this->quoteCopy($rule, $amounts),
        ];
    }

    public function prepareCheckoutCredit(
        Platform $platform,
        ContactUnlockPricingRule $rule,
        string $sessionProof,
        ?string $visitorPhone,
        ?string $quoteToken
    ): array {
        $fullAmount = (float) $rule->amount;
        if ((string) $rule->scope !== VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES || trim((string) $quoteToken) === '') {
            return [
                'quote' => null,
                'sources' => collect(),
                'gross_amount' => $fullAmount,
                'credit_amount' => 0.0,
                'amount_due' => $fullAmount,
            ];
        }

        $sessionHash = $this->hashToken($sessionProof);
        $quote = ContactUnlockUpgradeQuote::query()
            ->where('quote_token_hash', $this->hashToken((string) $quoteToken))
            ->where('platform_id', (int) $platform->id)
            ->where('pricing_rule_id', (int) $rule->id)
            ->where('session_token_hash', $sessionHash)
            ->where('expires_at', '>', now())
            ->first();

        if (! $quote) {
            throw ValidationException::withMessages(['upgrade_quote_token' => 'This upgrade offer expired. Refresh the offer and try again.']);
        }

        $phoneHash = $this->phoneHash($visitorPhone, (string) ($platform->phone_prefix ?? ''));
        if ($quote->visitor_phone_hash && $phoneHash !== $quote->visitor_phone_hash) {
            throw ValidationException::withMessages(['visitor_phone' => 'Use the same phone number shown in the upgrade offer.']);
        }

        $quoteSourceIds = collect($quote->credit_sources_json ?: [])
            ->pluck('unlock_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (! $quoteSourceIds) {
            return [
                'quote' => $quote,
                'sources' => collect(),
                'gross_amount' => $fullAmount,
                'credit_amount' => 0.0,
                'amount_due' => $fullAmount,
            ];
        }

        $sources = $this->eligibleSourceBase($platform, $rule)
            ->whereIn('visitor_contact_unlocks.id', $quoteSourceIds)
            ->lockForUpdate()
            ->get();

        $amounts = $this->calculateAmounts($rule, $sources);
        if (abs($amounts['amount_due'] - (float) $quote->amount_due) > 0.009) {
            throw ValidationException::withMessages(['upgrade_quote_token' => 'This upgrade offer changed. Refresh the offer and try again.']);
        }

        return [
            'quote' => $quote,
            'sources' => $sources,
            'gross_amount' => $amounts['full_access_amount'],
            'credit_amount' => $amounts['eligible_credit'],
            'amount_due' => $amounts['amount_due'],
        ];
    }

    public function reserveCredits(VisitorContactUnlock $upgrade, Collection $sources): void
    {
        if ($sources->isEmpty()) {
            return;
        }

        $reservationExpires = now()->addMinutes(30);
        foreach ($sources as $source) {
            $source->forceFill([
                'credit_reserved_for_unlock_id' => (int) $upgrade->id,
                'credit_reserved_until' => $reservationExpires,
            ])->save();

            $upgrade->upgradeCredits()->create([
                'source_unlock_id' => (int) $source->id,
                'source_payment_id' => (int) $source->payment_id,
                'platform_id' => (int) $upgrade->platform_id,
                'currency' => strtoupper((string) ($source->payment?->currency ?: $upgrade->payment?->currency ?: '')),
                'credited_amount' => $this->sourceCreditAmount($source),
                'status' => \App\Models\ContactUnlockUpgradeCredit::STATUS_RESERVED,
                'metadata_json' => [
                    'source_reference' => (string) ($source->payment?->reference_number ?? ''),
                ],
            ]);
        }
    }

    public function applyReservedCredits(VisitorContactUnlock $upgrade): void
    {
        $credits = $upgrade->upgradeCredits()->where('status', \App\Models\ContactUnlockUpgradeCredit::STATUS_RESERVED)->get();
        foreach ($credits as $credit) {
            $credit->forceFill([
                'status' => \App\Models\ContactUnlockUpgradeCredit::STATUS_APPLIED,
                'applied_at' => now(),
            ])->save();

            VisitorContactUnlock::query()
                ->whereKey((int) $credit->source_unlock_id)
                ->update([
                    'credited_to_upgrade_unlock_id' => (int) $upgrade->id,
                    'credit_applied_at' => now(),
                    'credit_reserved_for_unlock_id' => null,
                    'credit_reserved_until' => null,
                ]);
        }
    }

    public function releaseReservedCredits(VisitorContactUnlock $upgrade): void
    {
        $upgrade->upgradeCredits()
            ->where('status', \App\Models\ContactUnlockUpgradeCredit::STATUS_RESERVED)
            ->update([
                'status' => \App\Models\ContactUnlockUpgradeCredit::STATUS_RELEASED,
                'updated_at' => now(),
            ]);

        VisitorContactUnlock::query()
            ->where('credit_reserved_for_unlock_id', (int) $upgrade->id)
            ->update([
                'credit_reserved_for_unlock_id' => null,
                'credit_reserved_until' => null,
            ]);
    }

    private function eligibleSources(
        Platform $platform,
        ContactUnlockPricingRule $rule,
        string $sessionHash,
        array $tokenHashes,
        ?string $phoneHash,
        bool $lock
    ): Builder {
        if (empty($tokenHashes) && ! $phoneHash) {
            return VisitorContactUnlock::query()->whereRaw('1 = 0');
        }

        $query = $this->eligibleSourceBase($platform, $rule)
            ->where(function (Builder $builder) use ($sessionHash, $tokenHashes, $phoneHash): void {
                if ($tokenHashes) {
                    $builder->orWhere(function (Builder $tokenQuery) use ($sessionHash, $tokenHashes): void {
                        $tokenQuery
                            ->where('visitor_contact_unlocks.session_token_hash', $sessionHash)
                            ->whereIn('visitor_contact_unlocks.public_token_hash', $tokenHashes);
                    });
                }

                if ($phoneHash) {
                    $builder->orWhere('visitor_contact_unlocks.visitor_phone_hash', $phoneHash);
                }
            })
            ->orderBy('visitor_contact_unlocks.created_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query;
    }

    private function eligibleSourceBase(Platform $platform, ContactUnlockPricingRule $rule): Builder
    {
        return VisitorContactUnlock::query()
            ->select('visitor_contact_unlocks.*')
            ->with(['payment:id,status,amount,currency,reference_number', 'client:id,name,wp_post_id'])
            ->join('payments', 'payments.id', '=', 'visitor_contact_unlocks.payment_id')
            ->where('visitor_contact_unlocks.platform_id', (int) $platform->id)
            ->where('visitor_contact_unlocks.scope', VisitorContactUnlock::SCOPE_SINGLE_PROFILE)
            ->where('visitor_contact_unlocks.created_at', '>=', now()->subDays(self::CREDIT_WINDOW_DAYS))
            ->whereNull('visitor_contact_unlocks.credited_to_upgrade_unlock_id')
            ->where(function (Builder $builder): void {
                $builder->whereNull('visitor_contact_unlocks.credit_reserved_for_unlock_id')
                    ->orWhere('visitor_contact_unlocks.credit_reserved_until', '<=', now());
            })
            ->where('payments.purpose', Payment::PURPOSE_VISITOR_CONTACT_UNLOCK)
            ->where('payments.currency', strtoupper((string) $rule->currency))
            ->whereIn('payments.status', Payment::SUCCESSFUL_STATUSES);
    }

    private function calculateAmounts(ContactUnlockPricingRule $rule, Collection $sources): array
    {
        $fullAmount = (float) $rule->amount;
        $credit = min($fullAmount, (float) $sources->sum(fn (VisitorContactUnlock $unlock) => $this->sourceCreditAmount($unlock)));

        return [
            'full_access_amount' => round($fullAmount, 2),
            'eligible_credit' => round($credit, 2),
            'amount_due' => round(max(0, $fullAmount - $credit), 2),
        ];
    }

    private function sourceCreditAmount(VisitorContactUnlock $unlock): float
    {
        return (float) ($unlock->amount_due ?: $unlock->payment?->amount ?: 0);
    }

    private function serializeSources(Collection $sources): array
    {
        return $sources->map(fn (VisitorContactUnlock $unlock) => [
            'unlock_id' => (int) $unlock->id,
            'unlock_reference' => (int) $unlock->id,
            'payment_reference' => (string) ($unlock->payment?->reference_number ?? ''),
            'amount' => $this->sourceCreditAmount($unlock),
            'profile' => (string) ($unlock->client?->name ?? ''),
            'wp_post_id' => (int) ($unlock->wp_post_id ?: $unlock->client?->wp_post_id),
        ])->values()->all();
    }

    private function quoteCopy(ContactUnlockPricingRule $rule, array $amounts): array
    {
        $currency = strtoupper((string) $rule->currency);
        $credit = $this->formatMoney($amounts['eligible_credit'], $currency);
        $due = $this->formatMoney($amounts['amount_due'], $currency);

        return [
            'headline' => 'Upgrade to 7-day access',
            'body' => $amounts['eligible_credit'] > 0
                ? "{$credit} credited from your profile unlocks. Pay {$due} more."
                : "Unlock all inactive contacts for {$due}.",
            'button' => $amounts['amount_due'] > 0 ? "Upgrade for {$due}" : 'Upgrade now',
        ];
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return trim($currency.' '.number_format($amount, $amount === floor($amount) ? 0 : 2));
    }

    private function phoneHash(?string $phone, string $prefix): ?string
    {
        $normalized = $this->normalizePhone((string) $phone, $prefix);
        return $normalized !== '' ? $this->hashToken($normalized) : null;
    }

    private function normalizePhone(string $phone, string $prefix): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $prefix = preg_replace('/\D+/', '', $prefix);

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0') && $prefix !== '') {
            $digits = $prefix.substr($digits, 1);
        }

        return strlen($digits) >= 8 ? $digits : '';
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
