<?php

namespace Tests\Feature;

use App\Models\ContactUnlockEvent;
use App\Models\ContactUnlockPricingRule;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\VisitorContactUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactUnlockAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_reads_are_limited_to_assigned_markets_and_setup_payload_is_omitted(): void
    {
        [$allowed, $blocked] = [Platform::factory()->create(['name' => 'Kenya']), Platform::factory()->create(['name' => 'Uganda'])];
        $this->unlock($allowed, 'ALLOWED-REF');
        $this->unlock($blocked, 'BLOCKED-REF');
        ContactUnlockPricingRule::query()->create([
            'platform_id' => $allowed->id,
            'scope' => VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
            'label' => 'Unlock',
            'currency' => 'KES',
            'amount' => 299,
            'duration_days' => 1,
            'is_active' => true,
        ]);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$allowed->id]]);

        Sanctum::actingAs($sales);

        $index = $this->getJson('/api/crm/settings/billing/contact-unlock');
        $index->assertOk()
            ->assertJsonPath('permissions.can_manage', false)
            ->assertJsonMissingPath('settings')
            ->assertJsonMissingPath('pricing_rules')
            ->assertJsonPath('markets.0.id', $allowed->id)
            ->assertJsonCount(1, 'markets')
            ->assertJsonCount(1, 'recent_unlocks')
            ->assertJsonPath('recent_unlocks.0.payment.reference', 'ALLOWED-REF');

        $this->getJson('/api/crm/settings/billing/contact-unlock?platform_id='.$blocked->id)->assertForbidden();
    }

    public function test_sales_pulse_and_export_are_market_scoped_and_writes_are_forbidden(): void
    {
        [$allowed, $blocked] = [Platform::factory()->create(), Platform::factory()->create()];
        $this->unlock($allowed, 'ALLOWED-REF');
        $this->unlock($blocked, 'BLOCKED-REF');
        ContactUnlockEvent::query()->create([
            'platform_id' => $allowed->id,
            'event_type' => ContactUnlockEvent::TYPE_CTA_CLICK,
            'session_hash' => hash('sha256', 'allowed'),
            'event_id_hash' => hash('sha256', 'event'),
            'occurred_at' => now(),
        ]);
        ContactUnlockEvent::query()->create([
            'platform_id' => $blocked->id,
            'event_type' => ContactUnlockEvent::TYPE_CTA_CLICK,
            'session_hash' => hash('sha256', 'blocked'),
            'event_id_hash' => hash('sha256', 'event2'),
            'occurred_at' => now(),
        ]);
        $rule = ContactUnlockPricingRule::query()->create([
            'platform_id' => $allowed->id,
            'scope' => VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
            'label' => 'Unlock',
            'currency' => 'KES',
            'amount' => 299,
            'duration_days' => 1,
            'is_active' => true,
        ]);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$allowed->id]]);

        Sanctum::actingAs($sales);

        $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?range=today')
            ->assertOk()
            ->assertJsonPath('kpis.unlock_cta_clicks', 1);

        $export = $this->postJson('/api/crm/settings/billing/contact-unlock/export');
        $export->assertOk()
            ->assertHeader('X-Export-Row-Total', '1')
            ->assertHeader('X-Export-Truncated', 'false');

        $this->putJson('/api/crm/settings/billing/contact-unlock', ['enabled' => true, 'sandbox_only' => false])->assertForbidden();
        $this->postJson('/api/crm/settings/billing/contact-unlock/readiness', ['platform_id' => $allowed->id])->assertForbidden();
        $this->deleteJson('/api/crm/settings/billing/contact-unlock/rules/'.$rule->id)->assertForbidden();
    }

    public function test_admin_sees_all_markets(): void
    {
        [$one, $two] = [Platform::factory()->create(), Platform::factory()->create()];
        $this->unlock($one, 'ONE');
        $this->unlock($two, 'TWO');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->getJson('/api/crm/settings/billing/contact-unlock');

        $response->assertOk()->assertJsonCount(2, 'markets')->assertJsonPath('recent_unlocks_meta.total', 2);
    }

    private function unlock(Platform $platform, string $reference): VisitorContactUnlock
    {
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
            'status' => 'completed',
            'reference_number' => $reference,
            'created_at' => now(),
            'updated_at' => now(),
            'completed_at' => now(),
        ]);

        return VisitorContactUnlock::query()->create([
            'platform_id' => $platform->id,
            'payment_id' => $payment->id,
            'scope' => VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
            'status' => VisitorContactUnlock::STATUS_ACTIVE,
            'gross_amount' => 299,
            'credit_amount' => 0,
            'amount_due' => 299,
            'visitor_phone_hash' => hash('sha256', Str::random(16)),
            'visitor_phone_masked' => '254*****111',
            'idempotency_key_hash' => hash('sha256', Str::random(16)),
            'session_token_hash' => hash('sha256', Str::random(16)),
            'public_token_hash' => hash('sha256', Str::random(16)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
