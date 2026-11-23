<?php

namespace Tests\Feature\Collections;

use App\Models\BillingProviderProfile;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionProviderFeed;
use App\Services\Collections\Kopokopo\KopokopoFeedService;
use App\Services\Collections\Kopokopo\KopokopoIngestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class KopokopoCollectionTestCase extends CollectionWorkflowTestCase
{
    protected function kenya(): array
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase(true, 1);
        $this->travelTo(now()->setDate(2026, 11, 23));
        $client->platform->update(['currency_code' => 'KES', 'phone_prefix' => '254']);
        $client->update(['phone_normalized' => '254700000001']);
        $price->product->update(['currency' => 'KES']);
        $price->update(['currency' => 'KES', 'price' => '1500.00']);
        $policy = $account->policy;
        $policy['enabled_from'] = now()->subHour()->toIso8601String();
        $policy['accepted_parser_versions'] = ['ke-kopokopo-v1'];
        $account->update(['country' => 'KE', 'provider_key' => KopokopoFeedService::PROVIDER, 'currency' => 'KES', 'account_identifier' => '268491', 'timezone' => 'Africa/Nairobi', 'policy' => $policy]);
        config(['collection_kopokopo.account_id' => $account->id, 'collection_kopokopo.enabled' => true,
            'collection.parser_versions.kopokopo_buygoods_ke' => 'ke-kopokopo-v1', 'collection.workspace_enabled' => true,
            'collection_sales.verified_accounts' => [$account->id], 'collection_sales.verified_platforms' => [1]]);
        DB::table('collection_sim_bindings')->where('account_id', $account->id)->delete();
        $profile = BillingProviderProfile::create(['provider_type_key' => 'kopokopo', 'profile_name' => 'Fixture Kenya', 'market_id' => 1,
            'environment' => 'production', 'active' => true, 'config_json' => ['base_url' => 'https://api.kopokopo.com', 'till_number' => 'K317087'],
            'secrets_json' => ['client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'api_key' => 'fixture-api-signing-key']]);
        $feed = CollectionProviderFeed::create(['id' => (string) Str::uuid(), 'account_id' => $account->id, 'provider_profile_id' => $profile->id,
            'till_number' => '268491', 'enabled' => true, 'cutover_at' => now()->subHour(), 'reconciled_until' => now()->subMinute(),
            'last_poll_completed_at' => now(), 'subscriptions' => ['buygoods_transaction_received' => ['state' => 'registered'], 'buygoods_transaction_reversed' => ['state' => 'registered']], 'version' => 1]);
        $link = CollectionPayerLink::findOrFail($intent->payer_link_id);
        $link->update(['payer_phone' => '', 'payer_fingerprint' => app(KopokopoIngestService::class)->fingerprint($feed, 'hash', str_repeat('a', 64)),
            'reviewed_at' => now()->subDays(3), 'valid_until' => now()->addMonth()]);
        $snapshot = array_replace($intent->purchase_snapshot, ['currency' => 'KES', 'amount_minor' => 150000, 'catalogue_minor' => 150000]);
        $intent->update(['purchase_snapshot' => $snapshot, 'approved_at' => now()->subDays(2), 'valid_from' => now()->subDays(2), 'valid_until' => now()->addMonth()]);

        return [$feed, $client->fresh(), $intent->fresh(), $actor, $price->fresh()];
    }

    protected function providerBody(array $resource = [], string $id = 'fixture-event-1', string $topic = 'buygoods_transaction_received'): string
    {
        return json_encode(['id' => $id, 'topic' => $topic, 'created_at' => now()->toIso8601String(), 'event' => ['type' => 'Buygoods Transaction', 'resource' => array_replace([
            'id' => 'fixture-resource-1', 'reference' => 'UKXFIX0001', 'amount' => '1500.00', 'currency' => 'KES', 'status' => 'Received',
            'till_number' => '268491', 'origination_time' => now()->subMinute()->toIso8601String(), 'hashed_sender_phone' => str_repeat('a', 64),
            'sender_first_name' => 'Fixture', 'sender_last_name' => 'Payer', 'sender_phone_number' => null], $resource)]], JSON_THROW_ON_ERROR);
    }

    protected function receive(CollectionProviderFeed $feed, ?string $body = null): array
    {
        $body ??= $this->providerBody();

        return app(KopokopoIngestService::class)->webhook($feed, $body, hash_hmac('sha256', $body, 'fixture-api-signing-key'));
    }
}
