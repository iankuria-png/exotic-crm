<?php

namespace Tests\Feature\DbContainment;

use App\Models\DbContainmentBackup;
use App\Models\DbContainmentMarket;
use App\Models\DbContainmentOperation;
use App\Models\DbScanFinding;
use App\Models\Platform;
use App\Services\DbContainment\ActionCatalog;
use App\Services\DbContainment\BackupVault;
use App\Services\DbContainment\CacheVerificationAdapter;
use App\Services\DbContainment\CampaignService;
use App\Services\DbContainment\ContainmentCrypto;
use App\Services\DbContainment\ContainmentException;
use App\Services\DbContainment\ContainmentExecutor;
use App\Services\DbContainment\ContainmentService;
use App\Services\DbContainment\MarketDbWriter;
use App\Services\DbScanner\KeyObservationBinder;
use App\Services\MarketOperationCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

class MemoryWriter extends MarketDbWriter
{
    public array $state = [];

    public bool $unknownCommit = false;

    public int $mutations = 0;

    public function connect(Platform $platform, DbContainmentMarket $market): void
    {
        $this->identity = ['host' => $platform->domain, 'schema' => 'fixture_wp', 'prefix' => 'wp_', 'urls' => []];
    }

    public function begin(array $selector): array
    {
        return $this->snapshot($selector);
    }

    public function snapshot(array $selector, bool $lock = false): array
    {
        $state = $this->state;
        if (isset($selector['user_columns'])) {
            $state['users'] = array_map(fn ($r) => array_intersect_key($r, array_flip($selector['user_columns'])), $state['users']);
        }
        if (isset($selector['meta_keys'])) {
            $state['usermeta'] = array_values(array_filter($state['usermeta'], fn ($r) => in_array($r['meta_key'], $selector['meta_keys'], true)));
        }
        if (empty($selector['option_names'])) {
            unset($state['options']);
        }

        return $state;
    }

    public function mutate(array $before, array $after): void
    {
        $this->mutations++;
        foreach ($after['users'] ?? [] as $i => $row) {
            $this->state['users'][$i] = array_merge($this->state['users'][$i], $row);
        }
        if (isset($after['usermeta'])) {
            $keys = array_unique([...array_column($before['usermeta'], 'meta_key'), ...array_column($after['usermeta'], 'meta_key')]);
            $this->state['usermeta'] = array_merge(array_values(array_filter($this->state['usermeta'], fn ($r) => ! in_array($r['meta_key'], $keys, true))), $after['usermeta']);
            usort($this->state['usermeta'], fn ($a, $b) => $a['umeta_id'] <=> $b['umeta_id']);
        }
        if (isset($after['options'])) {
            $this->state['options'] = $after['options'];
        }
    }

    public function rollback(): void {}

    public function commit(): void
    {
        if ($this->unknownCommit) {
            $this->unknownCommit = false;
            throw new \RuntimeException('lost response');
        }
    }

    public function close(): void {}
}

class ContainmentTest extends TestCase
{
    use BuildsWordPressFixture, RefreshDatabase;

    private MemoryWriter $writer;

    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->bootScanner();
        config(['db_scanner.enabled' => false, 'db_containment.enabled' => true, 'db_containment.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->vault = sys_get_temp_dir().'/containment-'.Str::uuid();
        config(['db_containment.vault' => $this->vault]);
        $this->writer = new MemoryWriter;
        $this->app->instance(MarketDbWriter::class, $this->writer);
        $cache = \Mockery::mock(CacheVerificationAdapter::class);
        $cache->shouldReceive('call')->andReturn(['manager' => 'WP_User_Meta_Session_Tokens', 'verified' => true, 'key_version' => '1']);
        $this->app->instance(CacheVerificationAdapter::class, $cache);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->vault.'/*') ?: [] as $file) {
            unlink($file);
        }if (is_dir($this->vault)) {
            rmdir($this->vault);
        }parent::tearDown();
    }

    private function fixture(string $domain = 'market.test'): array
    {
        $platform = $this->marketPlatform('Synthetic', $domain);
        $platform->update(['db_prefix' => 'wp_']);
        $market = DbContainmentMarket::query()->create(['platform_id' => $platform->id, 'enabled' => true, 'configuration' => []]);
        $keys = [['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'name' => 'auto-bootstrap', 'password' => 'synthetic-secret-one'], ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'name' => 'auto-bootstrap', 'password' => 'synthetic-secret-two'], ['uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'retained-key', 'password' => 'retained-secret']];
        $bound = (new KeyObservationBinder)->group($keys, [$domain, 'fixture_wp', 'wp_'], 42, 10, [])[0];
        $finding = DbScanFinding::query()->create(['platform_id' => $platform->id, 'fingerprint' => hash('sha256', Str::uuid()), 'subject_hash' => str_repeat('d', 64), 'rule_key' => 'access.application_passwords', 'rule_version_hash' => str_repeat('a', 64), 'pack' => 'core', 'pack_version' => '1', 'category' => 'access', 'severity' => 'critical', 'confidence' => 'strong', 'title' => 'Synthetic key', 'subject' => ['table' => 'usermeta', 'row_id' => 10, 'object_id' => 42], 'evidence' => ['details' => ['user_id' => 42] + $bound], 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(), 'occurrences' => 1]);
        $this->writer->state = ['users' => [['ID' => '42', 'user_login' => 'member', 'user_email' => 'member@example.test', 'user_registered' => '2020-01-01 00:00:00', 'user_pass' => 'original-hash', 'user_activation_key' => 'reset']], 'usermeta' => [['umeta_id' => '10', 'user_id' => '42', 'meta_key' => '_application_passwords', 'meta_value' => serialize($keys)], ['umeta_id' => '11', 'user_id' => '42', 'meta_key' => 'session_tokens', 'meta_value' => serialize(['synthetic' => ['expiration' => 2000000000]])], ['umeta_id' => '12', 'user_id' => '42', 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['subscriber' => true])]], 'options' => [['option_id' => '1', 'option_name' => 'wp_user_roles', 'option_value' => serialize(['subscriber' => ['capabilities' => ['read' => true]]])]]];

        return [$platform, $market, $finding, $this->adminUser()];
    }

    private function approved(array $actions = ['revoke_app_password', 'lock_account']): DbContainmentOperation
    {
        [,, $finding,$actor] = $this->fixture();
        $service = app(ContainmentService::class);
        $op = $service->preview($actor, $finding, $actions, (string) Str::uuid());

        return $service->confirm($actor, $op, $op->preview['confirmation'], null, $op->preview_digest);
    }

    public function test_full_flow_preserves_unselected_key_and_restores_exact_bytes(): void
    {
        $op = $this->approved();
        $original = $this->writer->state;
        app(ContainmentExecutor::class)->execute($op->id);
        $op->refresh();
        $this->assertSame('verified', $op->status);
        $this->assertSame('contained', DbScanFinding::find($op->finding_id)->status);
        $this->assertStringStartsWith('$2y$', $this->writer->state['users'][0]['user_pass']);
        $this->assertSame('', $this->writer->state['users'][0]['user_activation_key']);
        $this->assertCount(2, $this->writer->state['usermeta']);
        $keys = unserialize($this->writer->state['usermeta'][0]['meta_value']);
        $this->assertSame(['retained-key'], array_column($keys, 'name'));
        $backup = DbContainmentBackup::findOrFail($op->backup_id);
        $cipher = file_get_contents($this->vault.'/'.$backup->storage_key);
        $this->assertStringNotContainsString('original-hash', $cipher);
        $this->assertStringNotContainsString('synthetic-secret-one', $cipher);
        $this->assertStringNotContainsString('sealed_intent', $op->toJson());
        $actor = \App\Models\User::find($op->actor_id);
        $restore = app(ContainmentService::class)->restorePreview($actor, $op, (string) Str::uuid());
        app(ContainmentService::class)->confirm($actor, $restore, 'market.test', null, $restore->preview_digest);
        app(ContainmentExecutor::class)->execute($restore->id);
        $this->assertSame(MarketDbWriter::encode($original), MarketDbWriter::encode($this->writer->state));
        $this->assertSame('verified', $restore->fresh()->status);
        $this->assertSame('verified', $op->fresh()->status);
        $this->assertSame('acknowledged', DbScanFinding::find($op->finding_id)->status);
    }

    public function test_unknown_commit_recovers_without_replaying_write_and_holds_only_market(): void
    {
        $op = $this->approved();
        $this->writer->unknownCommit = true;
        app(ContainmentExecutor::class)->execute($op->id);
        $this->assertSame('outcome_unknown', $op->fresh()->status);
        $this->assertNull(DB::table('db_market_operation_leases')->where('platform_id', 0)->value('operation_id'));
        $this->assertTrue(MarketOperationCoordinator::blocksNewScan((int) $op->platform_id));
        app(ContainmentExecutor::class)->execute($op->id);
        $this->assertSame('verified', $op->fresh()->status);
        $this->assertSame(1, $this->writer->mutations);
        $this->assertFalse(MarketOperationCoordinator::blocksNewScan((int) $op->platform_id));
    }

    public function test_stale_rows_refuse_without_backup_or_write(): void
    {
        $op = $this->approved();
        $this->writer->state['users'][0]['user_email'] = 'changed@example.test';
        app(ContainmentExecutor::class)->execute($op->id);
        $this->assertSame('conflict', $op->fresh()->status);
        $this->assertSame(0, $this->writer->mutations);
        $this->assertSame(0, DbContainmentBackup::count());
    }

    public function test_expired_approval_and_cancelled_jobs_never_write(): void
    {
        $op = $this->approved();
        $this->travel(6)->minutes();
        app(ContainmentExecutor::class)->execute($op->id);
        $this->assertSame('expired', $op->fresh()->status);
        $this->assertSame(0, $this->writer->mutations);
    }

    public function test_cancel_before_claim_and_duplicate_delivery_are_idempotent(): void
    {
        $op = $this->approved();
        app(ContainmentService::class)->cancel(\App\Models\User::find($op->actor_id), $op);
        app(ContainmentExecutor::class)->execute($op->id);
        app(ContainmentExecutor::class)->execute($op->id);
        $this->assertSame('cancelled', $op->fresh()->status);
        $this->assertSame(0, $this->writer->mutations);
    }

    public function test_removed_or_replaced_duplicate_provenance_refuses(): void
    {
        [,, $finding] = $this->fixture();
        $state = $this->writer->state;
        $keys = unserialize($state['usermeta'][0]['meta_value']);
        $keys[1]['uuid'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $state['usermeta'][0]['meta_value'] = serialize($keys);
        $this->expectExceptionMessage('key_identity_changed_since_detection');
        app(ActionCatalog::class)->plan($finding, ['revoke_app_password'], ['host' => 'market.test', 'schema' => 'fixture_wp', 'prefix' => 'wp_'], $state, []);
    }

    public function test_group_provenance_reordering_preserves_identity_and_all_ips(): void
    {
        $keys = [['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'name' => 'same', 'last_ip' => '198.38.92.95'], ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'name' => 'same', 'last_ip' => '192.0.2.9']];
        $binder = new KeyObservationBinder;
        $a = $binder->group($keys, ['h', 'db', 'wp_'], 1, 2, []);
        $b = $binder->group(array_reverse($keys), ['h', 'db', 'wp_'], 1, 2, []);
        $this->assertCount(1, $a);
        $this->assertSame($a[0]['key_binding'], $b[0]['key_binding']);
        $this->assertSame(['198.38.92.95', '192.0.2.9'], $a[0]['observed_ips']);
        $this->assertStringNotContainsString('uuid', json_encode($a));
    }

    public function test_protected_integration_and_staff_refuse_but_explicit_logout_is_allowed(): void
    {
        [,, $finding] = $this->fixture();
        $state = $this->writer->state;
        $state['users'][0]['user_email'] = 'joolyan@gmail.com';
        $this->expectExceptionMessage('protected_staff_identity');
        app(ActionCatalog::class)->plan($finding, ['lock_account'], ['host' => 'market.test', 'schema' => 'fixture_wp', 'prefix' => 'wp_'], $state, []);
    }

    public function test_restore_conflict_preserves_legitimate_changes(): void
    {
        $op = $this->approved();
        app(ContainmentExecutor::class)->execute($op->id);
        $this->writer->state['users'][0]['user_pass'] = 'new-legitimate-password';
        $this->expectExceptionMessage('restore_conflict_current_rows_changed');
        app(ContainmentService::class)->restorePreview(\App\Models\User::find($op->actor_id), $op->fresh(), (string) Str::uuid());
    }

    public function test_restore_allows_unrelated_metadata_edits(): void
    {
        $op = $this->approved();
        app(ContainmentExecutor::class)->execute($op->id);
        $this->writer->state['usermeta'][] = ['umeta_id' => '13', 'user_id' => '42', 'meta_key' => 'description', 'meta_value' => 'legitimate edit'];
        $restore = app(ContainmentService::class)->restorePreview(\App\Models\User::find($op->actor_id), $op->fresh(), (string) Str::uuid());
        $this->assertSame('preview', $restore->status);
        $this->assertStringNotContainsString('legitimate edit', json_encode(app(ContainmentCrypto::class)->open($restore->sealed_intent)));
    }

    public function test_tampered_vault_and_recovery_retention_are_fail_closed(): void
    {
        $op = $this->approved();
        $this->writer->unknownCommit = true;
        app(ContainmentExecutor::class)->execute($op->id);
        $backup = DbContainmentBackup::find($op->fresh()->backup_id);
        try {
            app(BackupVault::class)->purge($op->fresh());
            $this->fail('Purged recovery');
        } catch (ContainmentException $e) {
            $this->assertSame('backup_pinned_for_recovery', $e->reason);
        }
        file_put_contents($this->vault.'/'.$backup->storage_key, 'tamper');
        $this->expectExceptionMessage('backup_tampered');
        app(BackupVault::class)->read($backup, true);
    }

    public function test_old_worker_cannot_release_successor_lease(): void
    {
        $op = $this->approved();
        DB::table('db_market_operation_leases')->where('platform_id', $op->platform_id)->update(['owner_token' => 'successor', 'expires_at' => now()->addMinutes(2)]);
        app(ContainmentService::class)->release($op, true, 'stale');
        $this->assertSame('successor', DB::table('db_market_operation_leases')->where('platform_id', $op->platform_id)->value('owner_token'));
    }

    public function test_admin_only_api_and_off_switch_are_enforced(): void
    {
        [,, $finding,$actor] = $this->fixture();
        Sanctum::actingAs($actor);
        config(['db_containment.enabled' => false]);
        $this->getJson('/api/crm/db-observatory/findings/'.$finding->id.'/actions')->assertOk()->assertJsonPath('enabled', false);
        $this->postJson('/api/crm/db-observatory/findings/'.$finding->id.'/actions/preview', ['actions' => ['lock_account'], 'request_key' => (string) Str::uuid()])->assertStatus(409);
        $actor->update(['role' => 'sub-admin']);
        $this->getJson('/api/crm/db-observatory/actions')->assertForbidden();
    }

    public function test_whole_campaign_approval_refuses_changed_child_and_standalone_bypass(): void
    {
        [,, $finding,$actor] = $this->fixture();
        $campaign = app(CampaignService::class)->preview($actor, [['finding_id' => $finding->id, 'actions' => ['revoke_app_password']]], (string) Str::uuid());
        $op = DbContainmentOperation::find($campaign->members[0]['id']);
        try {
            app(ContainmentService::class)->confirm($actor, $op, 'market.test', null, $op->preview_digest);
            $this->fail('Bypassed campaign');
        } catch (ContainmentException $e) {
            $this->assertSame('campaign_confirmation_required', $e->reason);
        }
        $op->update(['preview_digest' => str_repeat('b', 64)]);
        $this->expectExceptionMessage('campaign_child_changed');
        app(CampaignService::class)->confirm($actor, $campaign, 'CONTAIN 1 MARKETS', $campaign->preview_digest, []);
    }

    public function test_staff_logout_has_privilege_confirmation_and_no_password_change(): void
    {
        [,, $finding,$actor] = $this->fixture();
        $this->writer->state['users'][0]['user_email'] = 'joolyan@gmail.com';
        $op = app(ContainmentService::class)->preview($actor, $finding, ['end_sessions'], (string) Str::uuid());
        $this->assertSame('PRIVILEGED market.test 1', $op->preview['privilege_confirmation']);
        $manifest = app(ContainmentCrypto::class)->open($op->sealed_intent);
        $state = MarketDbWriter::decode($manifest['after']);
        $this->assertSame('original-hash', $state['users'][0]['user_pass']);
        $this->expectExceptionMessage('confirmation_does_not_match_preview');
        app(ContainmentService::class)->confirm($actor, $op, 'market.test', null, $op->preview_digest);
    }

    public function test_eleven_market_campaign_keeps_exact_count_and_partial_results(): void
    {
        $targets = [];
        $actor = null;
        for ($i = 0; $i < 11; $i++) {
            [,, $finding,$createdActor] = $this->fixture('market'.$i.'.test');
            $actor ??= $createdActor;
            $targets[] = ['finding_id' => $finding->id, 'actions' => ['revoke_app_password']];
        }
        $campaign = app(CampaignService::class)->preview($actor, $targets, (string) Str::uuid());
        $this->assertCount(11, $campaign->members);
        app(CampaignService::class)->confirm($actor, $campaign, 'CONTAIN 11 MARKETS', $campaign->preview_digest, []);
        foreach ($campaign->members as $member) {
            app(ContainmentExecutor::class)->execute($member['id']);
        }
        $present = app(CampaignService::class)->present($campaign->fresh());
        $this->assertCount(11, $present['operations']);
        $this->assertSame(1, $present['counts']['verified']);
        $this->assertSame(10, $present['counts']['conflict']);
        app(CampaignService::class)->cancel($actor, $campaign);
        $this->assertSame('verified', DbContainmentOperation::find($campaign->members[0]['id'])->status);
    }

    public function test_pending_scan_waits_without_stealing_paused_claim(): void
    {
        [$platform] = $this->fixture();
        config(['db_scanner.enabled' => true]);
        $pass = app(\App\Services\DbScanner\Engine\PassController::class)->start([$platform->id], 'quick', 'manual');
        $run = \App\Models\DbScanMarketRun::query()->where('pass_id', $pass->id)->first();
        $run->update(['status' => 'paused']);
        $finding = DbScanFinding::first();
        $actor = $this->adminUser();
        $op = app(ContainmentService::class)->preview($actor, $finding, ['revoke_app_password'], (string) Str::uuid());
        app(ContainmentService::class)->confirm($actor, $op, 'market.test', null, $op->preview_digest);
        app(ContainmentExecutor::class)->execute($op->id);
        $this->assertSame('waiting_for_paused_scan', $op->fresh()->status);
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame(0, $this->writer->mutations);
        $this->assertFalse(MarketOperationCoordinator::blocksExecution((int) $platform->id));
    }

    public function test_revoked_admin_cannot_execute_prior_approval(): void
    {
        $op = $this->approved();
        \App\Models\User::query()->whereKey($op->actor_id)->update(['status' => 'inactive']);
        app(ContainmentExecutor::class)->execute($op->id);
        $this->assertSame('active_admin_required', $op->fresh()->result_code);
        $this->assertSame(0, $this->writer->mutations);
    }

    public function test_restore_unknown_commit_recovers_without_replay(): void
    {
        $op = $this->approved();
        $original = MarketDbWriter::encode($this->writer->state);
        app(ContainmentExecutor::class)->execute($op->id);
        $actor = \App\Models\User::find($op->actor_id);
        $restore = app(ContainmentService::class)->restorePreview($actor, $op->fresh(), (string) Str::uuid());
        app(ContainmentService::class)->confirm($actor, $restore, 'market.test', null, $restore->preview_digest);
        $this->writer->unknownCommit = true;
        app(ContainmentExecutor::class)->execute($restore->id);
        $this->assertSame('outcome_unknown', $restore->fresh()->status);
        app(ContainmentExecutor::class)->execute($restore->id);
        $this->assertSame('verified', $restore->fresh()->status);
        $this->assertSame(2, $this->writer->mutations);
        $this->assertSame($original, MarketDbWriter::encode($this->writer->state));
    }

    public function test_protected_integration_key_cannot_be_revoked(): void
    {
        [,, $finding] = $this->fixture();
        $keys = unserialize($this->writer->state['usermeta'][0]['meta_value']);
        $keys[0]['name'] = 'EWMS';
        $keys[1]['name'] = 'EWMS';
        $this->writer->state['usermeta'][0]['meta_value'] = serialize($keys);
        $bound = (new KeyObservationBinder)->group($keys, ['market.test', 'fixture_wp', 'wp_'], 42, 10, [])[0];
        $finding->update(['evidence' => ['details' => ['user_id' => 42] + $bound]]);
        $this->expectExceptionMessage('protected_integration_key');
        app(ActionCatalog::class)->plan($finding, ['revoke_app_password'], ['host' => 'market.test', 'schema' => 'fixture_wp', 'prefix' => 'wp_'], $this->writer->state, []);
    }
}
