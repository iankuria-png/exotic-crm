<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContactUnlockEvent;
use App\Models\ContactUnlockPricingRule;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\VisitorContactUnlock;
use App\Services\ContactUnlockUpgradeQuoteService;
use App\Services\FeatureSettingsService;
use App\Support\ClientLifecycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactUnlockUpsellPulseTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_quote_credits_recent_single_profile_unlocks(): void
    {
        [$platform, $client, $singleRule, $fullRule] = $this->configuredUnlockMarket();
        $sessionProof = str_repeat('session-proof-', 3);
        $publicToken = str_repeat('public-token-', 3);

        $source = $this->paidUnlock($platform, $client, $singleRule, [
            'amount' => 199,
            'session_proof' => $sessionProof,
            'public_token' => $publicToken,
        ]);

        $quote = app(ContactUnlockUpgradeQuoteService::class)->quote(
            $platform,
            (int) $client->wp_post_id,
            (int) $fullRule->id,
            [$publicToken],
            null,
            $sessionProof
        );

        $this->assertSame(999.0, $quote['full_access_amount']);
        $this->assertSame(199.0, $quote['eligible_credit']);
        $this->assertSame(800.0, $quote['amount_due']);
        $this->assertSame((int) $source->id, $quote['credit_sources'][0]['unlock_id']);

        $prepared = app(ContactUnlockUpgradeQuoteService::class)->prepareCheckoutCredit(
            $platform,
            $fullRule,
            $sessionProof,
            null,
            $quote['quote_token']
        );

        $this->assertSame(199.0, $prepared['credit_amount']);
        $this->assertSame(800.0, $prepared['amount_due']);
        $this->assertCount(1, $prepared['sources']);
    }

    public function test_upgrade_quote_accumulates_same_phone_unlocks_and_applies_reserved_credits(): void
    {
        [$platform, $client, $singleRule, $fullRule] = $this->configuredUnlockMarket();
        $sessionProof = str_repeat('another-session-', 3);
        $phone = '0711 111 111';
        $normalizedPhone = '254711111111';

        $first = $this->paidUnlock($platform, $client, $singleRule, [
            'amount' => 199,
            'visitor_phone' => $normalizedPhone,
        ]);
        $second = $this->paidUnlock($platform, $client, $singleRule, [
            'amount' => 199,
            'visitor_phone' => $normalizedPhone,
        ]);

        $quote = app(ContactUnlockUpgradeQuoteService::class)->quote(
            $platform,
            (int) $client->wp_post_id,
            (int) $fullRule->id,
            [],
            $phone,
            $sessionProof
        );

        $prepared = app(ContactUnlockUpgradeQuoteService::class)->prepareCheckoutCredit(
            $platform,
            $fullRule,
            $sessionProof,
            $phone,
            $quote['quote_token']
        );

        $this->assertSame(398.0, $prepared['credit_amount']);
        $this->assertSame(601.0, $prepared['amount_due']);

        $upgradePayment = Payment::factory()->create([
            'platform_id' => (int) $platform->id,
            'client_id' => null,
            'amount' => 601,
            'currency' => 'KES',
            'status' => 'completed',
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
        ]);
        $upgrade = VisitorContactUnlock::query()->create([
            'platform_id' => (int) $platform->id,
            'client_id' => null,
            'wp_post_id' => null,
            'payment_id' => (int) $upgradePayment->id,
            'pricing_rule_id' => (int) $fullRule->id,
            'scope' => VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES,
            'status' => VisitorContactUnlock::STATUS_PENDING_PAYMENT,
            'gross_amount' => 999,
            'credit_amount' => 398,
            'amount_due' => 601,
            'visitor_phone_hash' => $this->hashToken($normalizedPhone),
            'visitor_phone_masked' => '254*****111',
            'idempotency_key_hash' => $this->hashToken('upgrade-idempotency'),
            'session_token_hash' => $this->hashToken($sessionProof),
            'public_token_hash' => $this->hashToken('upgrade-public-token'),
        ]);

        app(ContactUnlockUpgradeQuoteService::class)->reserveCredits($upgrade->fresh(['payment']), $prepared['sources']);
        app(ContactUnlockUpgradeQuoteService::class)->applyReservedCredits($upgrade);

        $this->assertDatabaseHas('contact_unlock_upgrade_credits', [
            'upgrade_unlock_id' => (int) $upgrade->id,
            'source_unlock_id' => (int) $first->id,
            'status' => 'applied',
        ]);
        $this->assertDatabaseHas('contact_unlock_upgrade_credits', [
            'upgrade_unlock_id' => (int) $upgrade->id,
            'source_unlock_id' => (int) $second->id,
            'status' => 'applied',
        ]);
        $this->assertSame((int) $upgrade->id, (int) $first->fresh()->credited_to_upgrade_unlock_id);
        $this->assertSame((int) $upgrade->id, (int) $second->fresh()->credited_to_upgrade_unlock_id);
    }

    public function test_contact_unlock_pulse_reports_funnel_and_paid_mix(): void
    {
        [$platform, $client, $singleRule, $fullRule] = $this->configuredUnlockMarket();
        $sessionProof = str_repeat('pulse-session-', 3);
        $publicToken = str_repeat('pulse-public-', 3);

        $this->paidUnlock($platform, $client, $singleRule, [
            'amount' => 199,
            'session_proof' => $sessionProof,
            'public_token' => $publicToken,
        ]);
        $upgradePayment = Payment::factory()->create([
            'platform_id' => (int) $platform->id,
            'amount' => 800,
            'currency' => 'KES',
            'status' => 'completed',
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
        ]);
        VisitorContactUnlock::query()->create([
            'platform_id' => (int) $platform->id,
            'client_id' => null,
            'wp_post_id' => null,
            'payment_id' => (int) $upgradePayment->id,
            'pricing_rule_id' => (int) $fullRule->id,
            'scope' => VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES,
            'status' => VisitorContactUnlock::STATUS_ACTIVE,
            'gross_amount' => 999,
            'credit_amount' => 199,
            'amount_due' => 800,
            'visitor_phone_hash' => $this->hashToken('254722222222'),
            'visitor_phone_masked' => '254*****222',
            'idempotency_key_hash' => $this->hashToken('pulse-upgrade-idempotency'),
            'session_token_hash' => $this->hashToken($sessionProof),
            'public_token_hash' => $this->hashToken('pulse-upgrade-public-token'),
        ]);

        foreach ([ContactUnlockEvent::TYPE_ELIGIBLE_VIEW, ContactUnlockEvent::TYPE_CTA_CLICK, ContactUnlockEvent::TYPE_CHECKOUT_START] as $index => $type) {
            ContactUnlockEvent::query()->create([
                'platform_id' => (int) $platform->id,
                'client_id' => (int) $client->id,
                'wp_post_id' => (int) $client->wp_post_id,
                'event_type' => $type,
                'session_hash' => $this->hashToken($sessionProof),
                'event_id_hash' => $this->hashToken("pulse-event-{$index}"),
                'traffic_source' => 'google',
                'local_hour' => 14,
                'occurred_at' => now(),
            ]);
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?range=today&platform_id='.$platform->id);

        $response->assertOk()
            ->assertJsonPath('kpis.eligible_profile_views', 1)
            ->assertJsonPath('kpis.unlock_cta_clicks', 1)
            ->assertJsonPath('kpis.checkout_starts', 2)
            ->assertJsonPath('kpis.successful_payments', 2)
            ->assertJsonPath('kpis.single_profile_purchases', 1)
            ->assertJsonPath('kpis.full_access_purchases', 1)
            ->assertJsonPath('kpis.upgrade_rate_percent', 50)
            ->assertJsonPath('top_sources.0.label', 'google')
            ->assertJsonPath('top_hours.0.label', '14:00');
    }

    private function configuredUnlockMarket(): array
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'KES',
            'phone_prefix' => '254',
        ]);

        app(FeatureSettingsService::class)->set('contact_unlock.enabled', true);
        app(FeatureSettingsService::class)->set('contact_unlock.market_ids', [(int) $platform->id]);
        app(FeatureSettingsService::class)->set('contact_unlock.sandbox_only', false);

        $client = Client::factory()->create([
            'platform_id' => (int) $platform->id,
            'name' => 'Mitchell',
            'city' => 'Nairobi',
            'wp_post_id' => 44001,
            'profile_status' => 'publish',
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
            'wp_profile_permalink' => 'https://www.exotickenya.test/escort/mitchell/',
        ]);

        $singleRule = ContactUnlockPricingRule::query()->create([
            'platform_id' => (int) $platform->id,
            'scope' => ContactUnlockPricingRule::SCOPE_SINGLE_PROFILE,
            'label' => 'Unlock this profile',
            'currency' => 'KES',
            'amount' => 199,
            'duration_days' => 1,
            'is_active' => true,
        ]);

        $fullRule = ContactUnlockPricingRule::query()->create([
            'platform_id' => (int) $platform->id,
            'scope' => ContactUnlockPricingRule::SCOPE_MARKET_INACTIVE_PROFILES,
            'label' => 'Full Access',
            'currency' => 'KES',
            'amount' => 999,
            'duration_days' => 7,
            'is_active' => true,
        ]);

        return [$platform, $client, $singleRule, $fullRule];
    }

    private function paidUnlock(Platform $platform, Client $client, ContactUnlockPricingRule $rule, array $overrides = []): VisitorContactUnlock
    {
        $amount = (float) ($overrides['amount'] ?? 199);
        $sessionProof = (string) ($overrides['session_proof'] ?? str_repeat('source-session-', 3));
        $publicToken = (string) ($overrides['public_token'] ?? str_repeat('source-token-', 3).uniqid());
        $visitorPhone = (string) ($overrides['visitor_phone'] ?? '254722000111');

        $payment = Payment::factory()->create([
            'platform_id' => (int) $platform->id,
            'client_id' => (int) $client->id,
            'escort_post_id' => (int) $client->wp_post_id,
            'amount' => $amount,
            'currency' => 'KES',
            'status' => 'completed',
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
            'reference_number' => 'CU-TEST-'.strtoupper(substr(hash('sha1', $publicToken), 0, 8)),
        ]);

        return VisitorContactUnlock::query()->create([
            'platform_id' => (int) $platform->id,
            'client_id' => (int) $client->id,
            'wp_post_id' => (int) $client->wp_post_id,
            'payment_id' => (int) $payment->id,
            'pricing_rule_id' => (int) $rule->id,
            'scope' => VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
            'status' => VisitorContactUnlock::STATUS_ACTIVE,
            'gross_amount' => $amount,
            'credit_amount' => 0,
            'amount_due' => $amount,
            'visitor_phone_hash' => $this->hashToken($visitorPhone),
            'visitor_phone_masked' => '254*****111',
            'idempotency_key_hash' => $this->hashToken('source-idempotency-'.$publicToken),
            'session_token_hash' => $this->hashToken($sessionProof),
            'public_token_hash' => $this->hashToken($publicToken),
        ]);
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
