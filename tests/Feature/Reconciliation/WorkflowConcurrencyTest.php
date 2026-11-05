<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\Reconciliation\DailyPaymentReviewService;
use App\Services\Trials\TrialApprovalService;
use App\Services\WalletSettingsService;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires a disposable MySQL database and independent processes.');
        }
        $this->assertTrue(app()->environment('testing') && str_starts_with((string) config('database.connections.mysql.database'), 'crm_daily_review_test_'));
        \Tests\Support\WorkflowTestDatabase::reset();
    }

    private function fixture(): array
    {
        $m = Platform::factory()->create(['wp_api_url' => 'https://race.example.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture']);
        $a = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $b = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $c = Client::factory()->create(['platform_id' => $m->id, 'wp_post_id' => 77, 'wp_user_id' => 55, 'profile_status' => 'private', 'profile_removal_requested_at' => null, 'profile_reverted_at' => null]);

        return [$m, $a, $b, $c];
    }

    private function race(array $left, array $right): array
    {
        $barrier = sys_get_temp_dir().'/crm-review-race-'.Str::uuid();
        $jobs = [];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_SOCKET' => config('database.connections.mysql.unix_socket'),
            'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_HOST' => 'localhost', 'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        foreach ([$left, $right] as $input) {
            $input['barrier'] = $barrier;
            $process = proc_open(['/usr/local/opt/php@8.2/bin/php', base_path('tests/Support/workflow-race-worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            fwrite($pipes[0], json_encode($input));
            fclose($pipes[0]);
            $this->assertSame("READY\n", fgets($pipes[1]));
            $jobs[] = [$process, $pipes];
        }
        touch($barrier);
        $results = [];
        foreach ($jobs as [$process,$pipes]) {
            $stdout = trim(stream_get_contents($pipes[1]));
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr);
            $this->assertSame('', trim($stderr));
            $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        unlink($barrier);

        return $results;
    }

    public function test_two_reviewers_cannot_both_commit_the_same_source_version(): void
    {
        [$m,$a,$b,$c] = $this->fixture();
        $recorder = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$m->id]]);
        $p = Payment::create(['platform_id' => $m->id, 'client_id' => $c->id, 'confirmed_by' => $recorder->id, 'amount' => 35000, 'currency' => 'TZS', 'status' => 'completed', 'purpose' => 'subscription', 'source' => 'manual_confirmation', 'transaction_reference' => 'RACE-REVIEW', 'completed_at' => now()]);
        $s = app(DailyPaymentReviewService::class);
        $row = $s->unit($a, 'payment', $p->id);
        $input = ['units' => [$row], 'action' => 'approve', 'reason' => 'Concurrent check'];
        $pa = $s->preview($a, $input);
        $pb = $s->preview($b, $input);
        $results = $this->race(['mode' => 'review', 'actor' => $a->id, 'token' => $pa['preview_token'], 'key' => (string) Str::uuid()], ['mode' => 'review', 'actor' => $b->id, 'token' => $pb['preview_token'], 'key' => (string) Str::uuid()]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('payment_review_events', 1);
        $this->assertDatabaseCount('payment_review_operations', 1);
        $this->assertSame('completed', $p->fresh()->status);
    }

    public function test_rotation_and_stale_system_save_cannot_restore_the_old_pin(): void
    {
        [,$a] = $this->fixture();
        $s = app(WalletSettingsService::class);
        $s->updateFreeTrialPin('1234', $a->id);
        $payload = $s->currentSystemConfig(false);
        $results = $this->race(['mode' => 'rotate', 'actor' => $a->id], ['mode' => 'settings', 'actor' => $a->id, 'payload' => $payload]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertFalse($s->verifyFreeTrialPin('1234'));
        $this->assertTrue($s->verifyFreeTrialPin('5678'));
        $this->assertSame(2, $s->currentSystemConfig(false)['free_trial_pin_generation']);
    }

    public function test_duplicate_trial_workers_send_one_entitlement_mutation(): void
    {
        [$m,$a,$b,$c] = $this->fixture();
        $product = Product::factory()->create(['platform_id' => $m->id, 'tier' => 'vip', 'is_active' => true]);
        $deal = Deal::factory()->create(['platform_id' => $m->id, 'client_id' => $c->id, 'product_id' => $product->id, 'status' => 'pending', 'activated_at' => null, 'expires_at' => null, 'payment_id' => null]);
        $terms = ['action' => 'activate', 'platform_id' => $m->id, 'client_ids' => [$c->id], 'source_deal_id' => $deal->id, 'product_id' => $product->id, 'duration_days' => 7, 'reason' => 'Concurrent trial acceptance'];
        $r = app(TrialApprovalService::class)->create($a, $terms);
        $file = sys_get_temp_dir().'/crm-trial-count-'.Str::uuid();
        $input = ['mode' => 'trial', 'actor' => $a->id, 'request_id' => $r->id, 'mutations' => $file, 'expiry' => now()->addDays(7)->timestamp];
        $this->race($input, $input);
        $this->assertFileExists($file);
        $this->assertCount(1, file($file));
        unlink($file);
        $this->assertDatabaseCount('trial_activation_operations', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('active', $r->fresh()->status);
    }
}
