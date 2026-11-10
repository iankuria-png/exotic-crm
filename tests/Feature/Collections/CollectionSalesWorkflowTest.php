<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionEvent;
use App\Models\Collections\CollectionSalesSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class CollectionSalesWorkflowTest extends CollectionWorkflowTestCase
{
    public static function publishedNoExpiryCases(): array
    {
        return [
            'expired and payment required' => ['needs_payment', '1', true],
            'inactive published profile' => ['notactive', '1', true],
            'active indefinite coverage' => ['needs_payment', '', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publishedNoExpiryCases')]
    public function test_published_no_expiry_uses_actual_payment_and_activity_state(string $flag, string $value, bool $allowed): void
    {
        [$account, $device, $client, $intent, $actor, $price, $receipt] = $this->fixture();
        $before = $this->snapshot(77, 55);
        $before['post_status'] = 'publish';
        $before['meta'][$flag] = $value;
        $before['meta']['crm_lifecycle_state'] = 'expired';
        unset($before['fingerprint']);
        $before['fingerprint'] = hash('sha256', json_encode($before));
        $this->remote[77] = $before;
        $this->fakeWordPress();
        $input = $this->accepted($this->input($receipt, $client, $price), $receipt, $actor);
        $service = app(\App\Services\Collections\CollectionSalesFulfillmentService::class);
        if (! $allowed) {
            try {
                $service->prepare($receipt, $actor, $input);
                $this->fail('Active indefinite coverage must remain protected.');
            } catch (\App\Services\Collections\CollectionException $e) {
                $this->assertSame('indefinite_active_coverage_requires_review', $e->reason);
            }
            $this->assertSame(0, $this->posts);
            $this->assertSame(0, DB::table('payments')->count());
            $this->assertSame(0, DB::table('collection_sales_profile_holds')->count());

            return;
        }
        $prepared = $service->prepare($receipt, $actor, $input);
        $item = \App\Models\Collections\CollectionSalesItem::firstOrFail();
        $this->assertSame(now()->addDays(8)->timestamp, $item->expected_site['target']['target_expiry']);
        $this->assertSame(0, $this->posts);
        $this->assertSame('confirmed', $service->resume($item->id)->state);
        $service->resume($item->id);
        $this->assertSame(1, $this->posts);
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame($prepared['session']['id'], $service->prepare($receipt, $actor, $input)['session']['id']);
    }

    private function fixture(): array
    {
        $fixture = $this->purchase(false);
        [$account, $device, $client, $intent, $actor] = $fixture;
        $account->update(['mode' => 'shadow']);
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($actor);

        return [...$fixture, $this->received($device)];
    }

    private function counts(): array
    {
        return ['deals' => DB::table('deals')->count(), 'payments' => DB::table('payments')->count()];
    }

    private function input($receipt, $client, $price): array
    {
        return ['receipt_version' => $receipt->version, 'session_version' => 0, 'request_key' => (string) Str::uuid(),
            'items' => [['client_id' => $client->id, 'product_price_id' => $price->id]]];
    }

    public function test_message_copy_returns_only_parsed_original_receiving_text_without_writes(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $original = $this->credit();
        $event = CollectionEvent::where('receipt_id', $r->id)->first();
        $copy = $event->replicate();
        $copy->external_event_id = 'duplicate-body';
        $copy->raw_sha256 = str_repeat('1', 64);
        $copy->save();
        $personal = $event->replicate();
        $personal->external_event_id = 'personal';
        $personal->raw_sha256 = str_repeat('2', 64);
        $personal->normalized = ['sender' => 'PERSONAL', 'message' => 'Your OTP is 123456'];
        $personal->save();
        $before = DB::table('collection_audit')->count();
        $this->getJson('/api/crm/collections/receipts/'.$r->id.'/messages')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.message', $original)->assertJsonPath('messages.0.copies', 2)
            ->assertJsonPath('complete', false)->assertJsonMissingPath('raw_body');
        $this->assertSame($before, DB::table('collection_audit')->count());
    }

    public function test_draft_preview_and_save_never_create_cash_or_entitlements_and_replay_original_version(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $input = $this->input($r, $client, $price);
        $path = '/api/crm/collections/receipts/'.$r->id.'/sales';
        $counts = $this->counts();
        $this->getJson($path)->assertOk()->assertJsonPath('session', null);
        $this->postJson($path.'/preview', $input)->assertOk()->assertJsonPath('can_activate', false)
            ->assertJsonPath('blocked_reasons.0', 'manual_activation_connection_required')->assertJsonPath('allocated_minor', 3500000);
        $this->assertSame(0, CollectionSalesSession::count());
        $first = $this->postJson($path.'/save', $input)->assertOk()->assertJsonPath('session.version', 1)->assertJsonPath('replayed', false);
        $this->postJson($path.'/save', $input)->assertOk()->assertJsonPath('session.id', $first->json('session.id'))->assertJsonPath('replayed', true);
        $second = array_replace($input, ['session_version' => 1, 'request_key' => (string) Str::uuid()]);
        $this->postJson($path.'/save', $second)->assertOk()->assertJsonPath('session.version', 2);
        $this->postJson($path.'/save', $input)->assertOk()->assertJsonPath('session.version', 1);
        $this->postJson($path.'/save', array_replace($input, ['remainder_reason' => 'Changed payload']))->assertConflict();
        $this->assertSame($counts['deals'], $this->counts()['deals']);
        $this->assertSame($counts['payments'], $this->counts()['payments']);
        $this->assertSame(0, DB::table('collection_sales_profile_holds')->count());
    }

    public function test_shared_allocation_balances_unique_clients_and_finance_remainder(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $r->update(['principal_minor' => 7000000]);
        $other = Client::factory()->create(['platform_id' => $client->platform_id, 'wp_post_id' => 78, 'wp_user_id' => 56, 'is_high_risk' => false]);
        $input = $this->input($r, $client, $price);
        $path = '/api/crm/collections/receipts/'.$r->id.'/sales/preview';
        $this->postJson($path, $input)->assertUnprocessable();
        $this->postJson($path, $input + ['leave_remainder' => true, 'remainder_reason' => 'Finance will allocate later'])->assertOk()->assertJsonPath('remainder_minor', 3500000);
        $input['items'][] = $input['items'][0];
        $this->postJson($path, $input)->assertUnprocessable();
        $input['items'][1]['client_id'] = $other->id;
        $this->postJson($path, $input)->assertOk()->assertJsonPath('remainder_minor', 0)->assertJsonCount(2, 'items');
        $r->update(['principal_minor' => 3500000]);
        $this->postJson($path, $input)->assertUnprocessable();
    }

    public function test_inaccessible_receipt_messages_and_sale_are_not_exposed(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $outsider = User::factory()->create(['role' => 'sales']);
        Sanctum::actingAs($outsider);
        foreach (['messages', 'sales'] as $suffix) {
            $this->getJson('/api/crm/collections/receipts/'.$r->id.'/'.$suffix)->assertNotFound();
        }
    }

    public function test_saved_purchase_is_visible_in_the_queue_before_a_sale_or_subscription_exists(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        app(\App\Services\Collections\CollectionPurchaseService::class)->saveChoice($r, $actor,
            ['client_id' => $client->id, 'product_price_id' => $price->id, 'request_key' => (string) Str::uuid(), 'expected_version' => $r->version]);
        $this->getJson('/api/crm/collections/receipts?state=purchase_saved')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.decision_state', 'purchase_saved')->assertJsonPath('data.0.clients.0.name', $client->name);
        $this->assertSame(0, DB::table('deals')->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    private function accepted(array $input, $receipt, $actor): array
    {
        config(['collection_sales.verified_accounts' => [$receipt->account_id], 'collection_sales.verified_platforms' => [101]]);
        $preview = app(\App\Services\Collections\CollectionSalesService::class)->preview($receipt, $actor, $input);

        return $input + ['preview_token' => $preview['preview_token']];
    }

    public function test_activation_preparation_is_durable_and_worker_confirms_the_exact_operation_once(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $this->fakeWordPress();
        $input = $this->accepted($this->input($r, $client, $price), $r, $actor);
        $service = app(\App\Services\Collections\CollectionSalesFulfillmentService::class);
        $result = $service->prepare($r, $actor, $input);
        $this->assertSame(0, $this->posts);
        $this->assertSame('prepared', $result['session']['state']);
        $this->assertSame(1, DB::table('collection_sales_profile_holds')->count());
        $this->assertSame(1, DB::table('manual_payment_bundles')->count());
        $item = \App\Models\Collections\CollectionSalesItem::firstOrFail();
        $this->assertFalse(\App\Models\Payment::businessVisible()->whereKey($item->payment_id)->exists());
        $confirmed = $service->resume($item->id);
        $this->assertSame('confirmed', $confirmed->state);
        $this->assertSame(1, $this->posts);
        $service->resume($item->id);
        $this->assertSame(1, $this->posts);
        $this->assertSame(0, DB::table('collection_sales_profile_holds')->count());
        $this->assertTrue(\App\Models\Payment::businessVisible()->whereKey($item->payment_id)->exists());
        $this->assertSame('receipt_completed', DB::table('manual_payment_bundles')->value('status'));
        $this->assertSame($result['session']['id'], $service->prepare($r, $actor, $input)['session']['id']);
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_activation_is_not_blocked_by_status_clock_or_background_sync_between_preview_and_confirm(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $this->fakeWordPress();
        $input = $this->accepted($this->input($r, $client, $price), $r, $actor);
        $sales = app(\App\Services\Collections\CollectionSalesService::class);
        $first = $sales->preview($r, $actor, $input);
        $this->travel(7)->seconds();
        $client->forceFill(['last_synced_at' => now()])->save();
        $second = $sales->preview($r->fresh(), $actor, $input);
        unset($first['preview_token'], $second['preview_token']);
        $this->assertSame($sales->previewDigest($first), $sales->previewDigest($second));
        $result = app(\App\Services\Collections\CollectionSalesFulfillmentService::class)->prepare($r->fresh(), $actor, $input);
        $this->assertSame('prepared', $result['session']['state']);
    }

    public function test_queue_names_the_staff_member_who_activated_a_receipt_sale(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $this->fakeWordPress();
        $this->getJson('/api/crm/collections/receipts')->assertOk()->assertJsonPath('data.0.activation', null);
        $input = $this->accepted($this->input($r, $client, $price), $r, $actor);
        $service = app(\App\Services\Collections\CollectionSalesFulfillmentService::class);
        $service->prepare($r, $actor, $input);
        $this->getJson('/api/crm/collections/receipts')->assertOk()->assertJsonPath('data.0.activation.kind', 'staff')
            ->assertJsonPath('data.0.activation.pending', true)->assertJsonPath('data.0.activation.actor.name', $actor->name);
        $service->resume(\App\Models\Collections\CollectionSalesItem::firstOrFail()->id);
        $this->getJson('/api/crm/collections/receipts')->assertOk()->assertJsonPath('data.0.activation.kind', 'staff')
            ->assertJsonPath('data.0.activation.pending', false)->assertJsonPath('data.0.activation.actor.id', $actor->id);
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()->assertJsonPath('activation.actor.name', $actor->name);
    }

    public function test_shared_unknown_stops_siblings_and_readback_keeps_confirmed_children(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $r->update(['principal_minor' => 7000000]);
        $other = Client::factory()->create(['platform_id' => $client->platform_id, 'wp_post_id' => 78, 'wp_user_id' => 56, 'is_high_risk' => false]);
        $this->remote[78] = $this->snapshot(78, 56);
        $this->fakeWordPress();
        $input = $this->input($r, $client, $price);
        $input['items'][] = ['client_id' => $other->id, 'product_price_id' => $price->id];
        $input = $this->accepted($input, $r, $actor);
        $service = app(\App\Services\Collections\CollectionSalesFulfillmentService::class);
        $service->prepare($r, $actor, $input);
        [$first, $second] = \App\Models\Collections\CollectionSalesItem::orderBy('position')->get()->all();
        $this->loseResponse = true;
        $this->assertSame('unknown', $service->resume($first->id)->state);
        $this->assertSame(1, $this->posts);
        try {
            $service->resume($second->id);
            $this->fail('Sibling dispatch must wait for an authoritative predecessor outcome.');
        } catch (\App\Services\Collections\CollectionException $e) {
            $this->assertSame('sale_predecessor_not_confirmed', $e->reason);
        }
        $this->assertSame('confirmed', $service->resume($first->id, true)->state);
        $this->assertSame(1, $this->posts);
        $this->assertSame('receipt_partial', DB::table('manual_payment_bundles')->value('status'));
        $this->assertTrue(\App\Models\Payment::businessVisible()->whereKey($first->payment_id)->exists());
        $this->assertFalse(\App\Models\Payment::businessVisible()->whereKey($second->payment_id)->exists());
        $this->assertSame('confirmed', $service->resume($second->id)->state);
        $this->assertSame(2, $this->posts);
        $this->assertSame(0, DB::table('collection_sales_profile_holds')->count());
    }

    public function test_missing_operation_after_a_dispatch_marker_never_authorizes_another_post(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $this->fakeWordPress();
        $input = $this->accepted($this->input($r, $client, $price), $r, $actor);
        $service = app(\App\Services\Collections\CollectionSalesFulfillmentService::class);
        $service->prepare($r, $actor, $input);
        $item = \App\Models\Collections\CollectionSalesItem::firstOrFail();
        $this->unavailablePost = 77;
        $this->assertSame('unknown', $service->resume($item->id)->state);
        $this->unavailablePost = null;
        $this->assertSame('unknown', $service->resume($item->id)->state);
        $this->assertSame(0, $this->posts);
        $this->assertSame(1, DB::table('collection_sales_profile_holds')->count());
    }

    public function test_preparing_a_sale_consumes_its_one_time_purchase_authority_atomically(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $saved = app(\App\Services\Collections\CollectionPurchaseService::class)->saveChoice($r, $actor,
            ['client_id' => $client->id, 'product_price_id' => $price->id, 'request_key' => (string) Str::uuid(), 'expected_version' => $r->version]);
        $r->refresh();
        $this->fakeWordPress();
        $input = $this->input($r, $client, $price);
        $input['items'][0]['saved_intent_id'] = $saved['intent']->id;
        $input = $this->accepted($input, $r, $actor);
        $result = app(\App\Services\Collections\CollectionSalesFulfillmentService::class)->prepare($r, $actor, $input);
        $authority = $saved['intent']->fresh();
        $this->assertNotNull($authority->revoked_at);
        $this->assertSame(1, $authority->used_count);
        $this->assertSame('prepared', $result['session']['state']);
        $this->assertSame(0, $this->posts);
    }

    private function trialFixture(): array
    {
        $fixture = $this->fixture();
        [$account, $device, $client, $intent, $actor, $price, $r] = $fixture;
        $client->update(['phone_normalized' => '255700000001', 'email' => 'advertiser@customer.test']);
        $target = \App\Models\Platform::factory()->create(['id' => 202, 'phone_prefix' => '255', 'currency_code' => 'TZS']);
        $product = \App\Models\Product::factory()->create(['platform_id' => $target->id, 'tier' => 'vip', 'is_active' => true, 'is_archived' => false]);
        $target->update(['product_id' => $product->id]);
        \App\Models\BillingSubscriptionRule::create(['market_id' => $target->id, 'free_trial_json' => ['enabled' => true, 'duration_days' => 9]]);
        $other = Client::factory()->create(['platform_id' => $target->id, 'phone_normalized' => $client->phone_normalized, 'email' => $client->email,
            'profile_status' => 'private', 'wp_post_id' => 88, 'wp_user_id' => 66, 'profile_reverted_at' => null, 'profile_removal_requested_at' => null]);
        config(['subsidiary_trials.pairs' => [101 => 202]]);

        return [...$fixture, $target, $other];
    }

    public function test_subsidiary_preview_has_no_deal_or_approval_write_and_attaches_to_the_actual_sale(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r, $target, $other] = $this->trialFixture();
        $path = '/api/crm/collections/receipts/'.$r->id.'/sales';
        $query = '?source_client_id='.$client->id.'&product_price_id='.$price->id;
        $this->getJson($path.'/subsidiary-trial-targets'.$query)->assertOk()->assertJsonPath('recommended_platform_id', 202);
        $preview = $this->getJson($path.'/subsidiary-trial-preview'.$query.'&platform_id=202&duration_days=9')->assertOk()
            ->assertJsonPath('resolution.kind', 'existing')->assertJsonMissingPath('resolution.identity')->json();
        $this->assertSame(0, DB::table('deals')->count());
        $this->assertSame(0, DB::table('free_trial_requests')->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $input = $this->input($r, $client, $price);
        $input['items'][0]['subsidiary_trial'] = ['enabled' => true, 'platform_id' => 202, 'client_id' => $other->id,
            'duration_days' => 9, 'create_confirmed' => false, 'preview_token' => $preview['preview_token'], 'request_key' => (string) Str::uuid(), 'pin' => '000000'];
        $this->postJson($path.'/save', $input)->assertOk()->assertJsonPath('session.items.0.subsidiary_trial.platform_id', 202);
        $this->assertSame(0, DB::table('free_trial_requests')->count());
        $input['session_version'] = 1;
        $input['request_key'] = (string) Str::uuid();
        $this->fakeWordPress();
        $accepted = $this->accepted($input, $r, $actor);
        $sale = app(\App\Services\Collections\CollectionSalesFulfillmentService::class)->prepare($r, $actor, $accepted);
        $item = \App\Models\Collections\CollectionSalesItem::findOrFail($sale['session']['items'][0]['id']);
        $trial = \App\Models\FreeTrialRequest::firstOrFail();
        $this->assertSame('approved', $trial->status);
        $this->assertSame($item->deal_id, $trial->terms_json['source_deal_id']);
        $this->assertSame($trial->id, \App\Models\Deal::find($item->deal_id)->pending_subsidiary_trial['trial_request_id']);
        $this->assertArrayNotHasKey('pin', $item->subsidiary);
        $this->assertArrayNotHasKey('preview_token', $item->subsidiary);
        foreach (DB::table('collection_audit')->get() as $audit) {
            $this->assertStringNotContainsString('"pin"', $audit->context);
            $this->assertStringNotContainsString('"preview_token"', $audit->context);
        }
        $this->assertSame('confirmed', app(\App\Services\Collections\CollectionSalesFulfillmentService::class)->resume($item->id)->state);
        $this->assertSame('approved', $trial->fresh()->status); // Primary confirmation does not invent a trial result.
    }

    public function test_changed_subsidiary_identity_rejects_the_receipt_preview_without_cash(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r, $target, $other] = $this->trialFixture();
        $adapter = app(\App\Services\Collections\CollectionSalesSubsidiaryAdapter::class);
        $preview = $adapter->preview($r, $actor, ['source_client_id' => $client->id, 'product_price_id' => $price->id, 'platform_id' => 202, 'duration_days' => 9]);
        $input = $this->input($r, $client, $price);
        $input['items'][0]['subsidiary_trial'] = ['enabled' => true, 'platform_id' => 202, 'client_id' => $other->id,
            'duration_days' => 9, 'create_confirmed' => false, 'preview_token' => $preview['preview_token'], 'request_key' => (string) Str::uuid()];
        $other->update(['phone_normalized' => '255700000999']);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/sales/preview', $input)->assertConflict();
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('free_trial_requests')->count());
    }

    public function test_profile_hold_legacy_cash_alias_and_automatic_allocation_guards_share_the_sale_owner(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $this->fakeWordPress();
        $input = $this->accepted($this->input($r, $client, $price), $r, $actor);
        app(\App\Services\Collections\CollectionSalesFulfillmentService::class)->prepare($r, $actor, $input);
        $guard = app(\App\Services\Collections\CollectionWriterGuard::class);
        foreach ([fn () => $guard->assertClient($client->id), fn () => $guard->assertReceipt($r->id)] as $call) {
            try {
                $call();
                $this->fail('Existing receipt sale must own this mutation.');
            } catch (\App\Services\Collections\CollectionException $e) {
                $this->assertNotEmpty($e->reason);
            }
        }
        DB::table('collection_receipt_aliases')->insert(['account_id' => $account->id, 'receipt_id' => $r->id, 'reference' => 'ALIAS000001', 'evidence_reference' => 'Finance fixture', 'reviewed_by' => $actor->id, 'created_at' => now()]);
        $other = Client::factory()->create(['platform_id' => 101, 'is_high_risk' => false]);
        try {
            \App\Models\Payment::factory()->create(['platform_id' => 101, 'client_id' => $other->id, 'transaction_reference' => 'ALIAS000001', 'purpose' => 'subscription']);
            $this->fail('An alias cannot create a second cash owner.');
        } catch (\App\Services\Collections\CollectionException $e) {
            $this->assertSame('receipt_principal_owned_by_manual_sale', $e->reason);
        }
        $payment = \App\Models\Payment::first();
        try {
            $payment->update(['amount' => '1.00']);
            $this->fail('Legacy payment editing must be refused.');
        } catch (\App\Services\Collections\CollectionException $e) {
            $this->assertSame('payment_owned_by_receipt_sale', $e->reason);
        }
        $payment->refresh();
        $deactivation = new \App\Support\DeactivationRequest(reasonCode: \App\Support\DealDeactivationReason::PAYMENT_REVERSED, reasonNotes: 'Fixture refusal before any site mutation');
        foreach ([
            fn () => app(\App\Services\SubscriptionDeactivationService::class)->deactivateDeal(\App\Models\Deal::findOrFail($payment->deal_id), $deactivation, $actor->id),
            fn () => app(\App\Services\PaymentMatchingService::class)->matchPayment($payment),
            fn () => app(\App\Services\PaymentMatchingService::class)->confirmMatch($payment, $other->id, $actor->id),
            fn () => app(\App\Services\PaymentCompletionService::class)->completeSubscriptionPayment($payment),
        ] as $legacyAction) {
            try {
                $legacyAction();
                $this->fail('Legacy actions cannot mutate receipt-owned cash or service.');
            } catch (\App\Services\Collections\CollectionException $e) {
                $this->assertSame('payment_owned_by_receipt_sale', $e->reason);
            }
        }
        $this->assertSame(0, $this->posts);
        $this->assertSame(1, \App\Models\Payment::count());
    }

    public function test_disabled_dispatch_keeps_durable_work_and_allows_later_verified_readback(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $this->fakeWordPress();
        $service = app(\App\Services\Collections\CollectionSalesFulfillmentService::class);
        $service->prepare($r, $actor, $this->accepted($this->input($r, $client, $price), $r, $actor));
        $item = \App\Models\Collections\CollectionSalesItem::first();
        config(['collection_sales.verified_accounts' => []]);
        try {
            $service->resume($item->id);
            $this->fail('Disabled account must prevent new dispatch.');
        } catch (\App\Services\Collections\CollectionException $e) {
            $this->assertSame('manual_activation_connection_required', $e->reason);
        }
        $this->assertNull($item->fresh()->dispatch_started_at);
        config(['collection_sales.verified_accounts' => [$account->id]]);
        $this->loseResponse = true;
        $this->assertSame('unknown', $service->resume($item->id)->state);
        config(['collection_sales.verified_accounts' => []]);
        $this->assertSame('confirmed', $service->resume($item->id, true)->state);
        $this->assertSame(1, $this->posts);
    }

    public function test_explicit_retry_only_queues_a_proven_never_dispatched_item(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $this->fakeWordPress();
        $service = app(\App\Services\Collections\CollectionSalesFulfillmentService::class);
        $service->prepare($r, $actor, $this->accepted($this->input($r, $client, $price), $r, $actor));
        $item = \App\Models\Collections\CollectionSalesItem::first();
        $input = ['session_version' => $item->session->version, 'item_version' => $item->version, 'request_key' => (string) Str::uuid(), 'reason' => 'The market connection is now available.'];
        $response = $service->retryUndispatched($item, $actor, $input);
        $this->assertSame(2, $response['session']['items'][0]['version']);
        $this->assertSame(0, $this->posts);
        $this->assertEquals($response, $service->retryUndispatched($item->fresh(), $actor, $input));
        $item->refresh()->update(['dispatch_started_at' => now(), 'state' => 'unknown']);
        try {
            $service->retryUndispatched($item->fresh(), $actor, array_replace($input, ['request_key' => (string) Str::uuid()]));
            $this->fail('Unknown cannot be retried.');
        } catch (\App\Services\Collections\CollectionException $e) {
            $this->assertSame('only_never_dispatched_sale_can_retry', $e->reason);
        }
    }

    public function test_partial_receipt_history_counts_the_cash_root_once_and_remainder_remains_visible(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $r->update(['principal_minor' => 7000000]);
        $other = Client::factory()->create(['platform_id' => 101, 'wp_post_id' => 78, 'wp_user_id' => 56, 'is_high_risk' => false]);
        $this->remote[78] = $this->snapshot(78, 56);
        $this->fakeWordPress();
        $input = $this->input($r, $client, $price);
        $input['items'][] = ['client_id' => $other->id, 'product_price_id' => $price->id];
        $service = app(\App\Services\Collections\CollectionSalesFulfillmentService::class);
        $service->prepare($r, $actor, $this->accepted($input, $r, $actor));
        $first = \App\Models\Collections\CollectionSalesItem::orderBy('position')->first();
        $service->resume($first->id);
        $next = $r->replicate();
        $next->reference_raw = 'NEXTREF0001';
        $next->reference_norm = 'NEXTREF0001';
        $next->provider_at = $r->provider_at->copy()->addDay();
        $next->save();
        $history = app(\App\Services\Collections\CollectionPaymentHistoryService::class)->forReceipt($next, $actor);
        $this->assertSame(1, $history['prior_count']);
        $this->assertSame(7000000, $history['items'][0]['amount_minor']);
        $this->assertTrue($history['identity_conflict']);
        $this->getJson('/api/crm/collections/clients/'.$other->id.'/sales')->assertOk()->assertJsonPath('items.0.receipt_id', $r->id);
        $this->getJson('/api/crm/collections/receipts?client_id='.$other->id)->assertOk()->assertJsonPath('data.0.id', $r->id);
    }
}
