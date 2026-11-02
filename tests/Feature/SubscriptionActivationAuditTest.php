<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\SubscriptionActivationAuditService;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionActivationAuditTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $name = 'Activation Operator'): User
    {
        return User::create(['name' => $name, 'email' => uniqid().'@example.test', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'active']);
    }

    private function payment(): Payment
    {
        $deal = Deal::factory()->create();
        $payment = Payment::factory()->create(['platform_id' => $deal->platform_id, 'client_id' => $deal->client_id, 'deal_id' => $deal->id, 'product_id' => $deal->product_id]);
        $deal->update(['payment_id' => $payment->id]);

        return $payment->fresh(['deal']);
    }

    public function test_diagnostics_exposes_snapshot_and_separate_payment_confirmer(): void
    {
        $payment = $this->payment();
        $actor = $this->actor();
        $confirmer = $this->actor('Payment Reviewer');
        $payment->update(['confirmed_by' => $confirmer->id, 'confirmed_at' => now()]);
        app(SubscriptionActivationAuditService::class)->record($payment->deal, $payment, $actor->id, 'manual', 'crm_activation', 'Bank receipt verified');
        $actor->update(['name' => 'Renamed Operator']);
        Sanctum::actingAs($actor);
        $this->getJson('/api/crm/payments/'.$payment->id.'/diagnostics')->assertOk()
            ->assertJsonPath('activation_responsibility.state', 'staff')
            ->assertJsonPath('activation_responsibility.actor.name', 'Activation Operator')
            ->assertJsonPath('activation_responsibility.method', 'manual')
            ->assertJsonPath('activation_responsibility.reason', 'Bank receipt verified')
            ->assertJsonPath('activation_responsibility.payment_confirmation.actor.name', 'Payment Reviewer');
    }

    public function test_retry_keeps_original_actor_and_time(): void
    {
        $payment = $this->payment();
        $service = app(SubscriptionActivationAuditService::class);
        $first = $this->actor('First Operator');
        $service->record($payment->deal, $payment, $first->id, 'manual', 'crm_activation');
        $this->travel(1)->days();
        $service->record($payment->deal, $payment, $this->actor('Retry Operator')->id, 'manual', 'retry');
        $data = $service->forPayment($payment);
        $this->assertSame($first->id, $data['actor']['id']);
        $this->assertSame('crm_activation', $data['source']);
        $this->assertSame($payment->deal->activated_at->toIso8601String(), $data['activated_at']);
        $this->assertSame(1, TimelineEvent::where('event_type', SubscriptionActivationAuditService::EVENT)->count());
    }

    public function test_automation_never_borrows_payment_confirmer_or_assignee(): void
    {
        $payment = $this->payment();
        $actor = $this->actor();
        $payment->update(['confirmed_by' => $actor->id, 'confirmed_at' => now()]);
        $payment->deal->update(['assigned_to' => $actor->id]);
        $service = app(SubscriptionActivationAuditService::class);
        $service->record($payment->deal, $payment, null, 'manual', 'deferred_activation_retry');
        $data = $service->forPayment($payment);
        $this->assertSame('automatic', $data['state']);
        $this->assertNull($data['actor']);
        $this->assertSame($actor->id, $data['payment_confirmation']['actor']['id']);
    }

    public function test_missing_history_does_not_guess_an_activator(): void
    {
        $payment = $this->payment();
        $actor = $this->actor();
        $payment->deal->update(['assigned_to' => $actor->id, 'activated_by_field_agent' => $actor->id]);
        $payment->update(['confirmed_by' => $actor->id]);
        $data = app(SubscriptionActivationAuditService::class)->forPayment($payment);
        $this->assertSame('not_recorded', $data['state']);
        $this->assertNull($data['actor']);
    }

    public function test_historical_timeline_requires_exact_payment_identity(): void
    {
        $payment = $this->payment();
        $actor = $this->actor();
        $event = TimelineEvent::create(['platform_id' => $payment->platform_id, 'entity_type' => 'deal', 'entity_id' => $payment->deal_id,
            'event_type' => 'deal_activated', 'actor_id' => $actor->id, 'content' => ['payment_id' => $payment->id + 1000, 'payment_method' => 'manual'], 'created_at' => now()]);
        $service = app(SubscriptionActivationAuditService::class);
        $this->assertNull($service->forPayment($payment)['actor']);
        $event->update(['content' => ['payment_id' => $payment->id, 'payment_method' => 'manual']]);
        $this->assertSame($actor->id, $service->forPayment($payment)['actor']['id']);
        $this->assertSame('Historical activation timeline', $service->forPayment($payment)['evidence']);
    }

    public function test_historical_renewal_audit_does_not_treat_payment_initiation_as_activation(): void
    {
        $payment = $this->payment();
        $actor = $this->actor();
        $audit = AuditLog::create(['platform_id' => $payment->platform_id, 'entity_type' => 'deal', 'entity_id' => 99999,
            'actor_id' => $actor->id, 'action' => CrmAuditAction::DEAL_RENEW,
            'after_state' => ['new_deal_id' => $payment->deal_id, 'payment_id' => $payment->id, 'new_status' => 'awaiting_payment'],
            'reason' => 'Renewal', 'created_at' => now()]);
        $service = app(SubscriptionActivationAuditService::class);
        $this->assertNull($service->forPayment($payment)['actor']);
        $audit->update(['after_state' => ['new_deal_id' => $payment->deal_id, 'payment_id' => $payment->id, 'new_status' => 'active', 'payment_method' => 'manual']]);
        $this->assertSame($actor->id, $service->forPayment($payment)['actor']['id']);
    }

    public function test_each_extension_payment_keeps_its_own_actor_and_time(): void
    {
        $payment = $this->payment();
        $service = app(SubscriptionActivationAuditService::class);
        $first = $this->actor('Initial Operator');
        $service->record($payment->deal, $payment, $first->id, 'manual', 'crm_activation');
        $extension = $payment->replicate(['transaction_uuid', 'transaction_reference', 'reference_number']);
        $extension->transaction_uuid = (string) \Illuminate\Support\Str::uuid();
        $extension->save();
        $second = $this->actor('Extension Operator');
        $this->travel(2)->days();
        $at = now()->toIso8601String();
        $service->record($payment->deal, $extension, $second->id, 'manual', 'crm_extension', null, $at);
        $this->assertSame($first->id, $service->forPayment($payment)['actor']['id']);
        $this->assertSame($second->id, $service->forPayment($extension)['actor']['id']);
        $this->assertSame($at, $service->forPayment($extension)['activated_at']);
    }

    public function test_unlinked_payment_reports_no_activation(): void
    {
        $payment = Payment::factory()->create(['deal_id' => null]);
        $this->assertSame('not_activated', app(SubscriptionActivationAuditService::class)->forPayment($payment)['state']);
    }

    public function test_sales_cannot_read_other_market_activation_evidence(): void
    {
        $payment = $this->payment();
        $user = $this->actor();
        $user->update(['role' => 'sales', 'assigned_market_ids' => [$payment->platform_id + 999]]);
        Sanctum::actingAs($user);
        $this->getJson('/api/crm/payments/'.$payment->id.'/diagnostics')->assertForbidden();
    }
}
