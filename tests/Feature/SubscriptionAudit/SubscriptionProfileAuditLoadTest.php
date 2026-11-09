<?php

namespace Tests\Feature\SubscriptionAudit;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\SubscriptionAudit\SubscriptionAuditBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionProfileAuditLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_fleet_sized_population_with_eighteen_thousand_paid_actions_fits_build_budget(): void
    {
        $staff = User::factory()->create(['role' => 'admin']);
        $site = Platform::factory()->create(['currency_code' => 'KES']);
        $product = Product::factory()->create(['platform_id' => $site->id]);
        $at = now('UTC')->subDay()->startOfSecond()->toDateTimeString();
        $clientBase = Client::factory()->raw(['platform_id' => $site->id, 'lifecycle_state' => 'active']);
        $dealBase = Deal::factory()->raw(['platform_id' => $site->id, 'client_id' => 1, 'product_id' => $product->id, 'currency' => 'KES', 'amount' => '10.10']);
        $paymentBase = Payment::factory()->raw(['platform_id' => $site->id, 'product_id' => $product->id, 'currency' => 'KES', 'amount' => '10.10', 'provider_environment' => 'live', 'raw_payload' => null]);
        foreach (range(0, 35) as $batch) {
            $clients = $deals = $payments = $audits = [];
            for ($offset = 1; $offset <= 500; $offset++) {
                $id = $batch * 500 + $offset;
                $clients[] = array_replace($clientBase, ['id' => $id, 'wp_post_id' => $id + 100000, 'wp_user_id' => $id + 200000, 'phone_normalized' => '254800'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
                $deals[] = array_replace($dealBase, ['id' => $id, 'client_id' => $id, 'payment_id' => null, 'activated_at' => $at]);
                $payments[] = array_replace($paymentBase, ['id' => $id, 'client_id' => $id, 'deal_id' => $id, 'transaction_uuid' => sprintf('00000000-0000-4000-8000-%012d', $id), 'transaction_reference' => 'SYNTHETIC-LOAD-'.$id]);
                $audits[] = ['platform_id' => $site->id, 'actor_id' => $staff->id, 'action' => 'deal_activate', 'entity_type' => 'deal', 'entity_id' => $id,
                    'after_state' => json_encode(['deal_status' => 'active', 'payment_id' => $id, 'amount' => '10.10', 'currency' => 'KES', 'expires_at' => now()->addMonth()->toDateTimeString()]), 'created_at' => $at];
            }
            DB::table('clients')->insert($clients);
            DB::table('deals')->insert($deals);
            DB::table('payments')->insert($payments);
            DB::table('audit_log')->insert($audits);
        }
        $extra = [];
        for ($id = 18001; $id <= 34200; $id++) {
            $extra[] = array_replace($clientBase, ['id' => $id, 'wp_post_id' => $id + 100000, 'wp_user_id' => $id + 200000, 'phone_normalized' => '254800'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
            if (count($extra) === 500) {
                DB::table('clients')->insert($extra);
                $extra = [];
            }
        }
        if ($extra) {
            DB::table('clients')->insert($extra);
        }
        unset($extra);
        unset($clients, $deals, $payments, $audits);
        $started = microtime(true);
        $run = app(SubscriptionAuditBuilder::class)->build();
        $seconds = microtime(true) - $started;
        $this->assertSame(34200, DB::table('subscription_audit_profiles')->where('run_id', $run->id)->count());
        $this->assertSame(18000, DB::table('subscription_audit_events')->where('run_id', $run->id)->count());
        $this->assertSame(18000, DB::table('subscription_audit_profiles')->where('run_id', $run->id)->where('payment_history_state', 'cash_recorded')->count());
        $this->assertSame(18000, DB::table('payments')->count());
        $this->assertEquals('181800.00', DB::table('payments')->sum('amount'));
        $this->assertLessThan(120, $seconds);
        if (DB::connection()->getDriverName() === 'mysql') {
            $plan = DB::select('EXPLAIN SELECT id FROM subscription_audit_profiles WHERE run_id = ? AND platform_id = ? AND business_active = 1 ORDER BY id LIMIT 100', [$run->id, $site->id]);
            $this->assertStringContainsString('sub_profile_scope', $plan[0]->possible_keys ?? '');
            fwrite(STDOUT, 'Profile index plan: '.json_encode(['key' => $plan[0]->key, 'access' => $plan[0]->type, 'extra' => $plan[0]->Extra])."\n");
        }
        fwrite(STDOUT, '\nProfile load metrics: '.json_encode(['profiles' => 34200, 'paid_actions' => 18000, 'source_rows' => 88200, 'build_seconds' => round($seconds, 3), 'php_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1)])."\n");
    }
}
