<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneCrmHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_expired_history_and_audit_logs_in_bounded_batches(): void
    {
        $platform = Platform::factory()->create();
        $actor = User::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        DB::table('client_retention_insight_history')->insert([
            [
                'client_id' => $client->id,
                'score' => 24,
                'band' => 'Stable',
                'recorded_date' => now()->subDays(91)->toDateString(),
                'created_at' => now()->subDays(91),
            ],
            [
                'client_id' => $client->id,
                'score' => 28,
                'band' => 'Watchlist',
                'recorded_date' => now()->subDays(90)->toDateString(),
                'created_at' => now()->subDays(90),
            ],
        ]);

        $expiredAudit = $this->audit($platform, $actor, now()->subDays(366));
        $retainedAudit = $this->audit($platform, $actor, now()->subDays(365));
        $nullDatedAudit = $this->audit($platform, $actor, null);

        $this->artisan('crm:prune-history', [
            '--history-days' => 90,
            '--audit-days' => 365,
            '--chunk' => 1,
            '--max-batches' => 0,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('client_retention_insight_history', [
            'client_id' => $client->id,
            'recorded_date' => now()->subDays(91)->toDateString(),
        ]);
        $this->assertDatabaseHas('client_retention_insight_history', [
            'client_id' => $client->id,
            'recorded_date' => now()->subDays(90)->toDateString(),
        ]);
        $this->assertDatabaseMissing('audit_log', ['id' => $expiredAudit->id]);
        $this->assertDatabaseHas('audit_log', ['id' => $retainedAudit->id]);
        $this->assertDatabaseHas('audit_log', ['id' => $nullDatedAudit->id]);
    }

    public function test_dry_run_reports_candidates_without_deleting_them(): void
    {
        $platform = Platform::factory()->create();
        $actor = User::factory()->create();
        $audit = $this->audit($platform, $actor, now()->subDays(366));

        $this->artisan('crm:prune-history', ['--audit-days' => 365, '--dry-run' => true])
            ->expectsOutputToContain('Would delete 1 audit logs')
            ->assertExitCode(0);

        $this->assertDatabaseHas('audit_log', ['id' => $audit->id]);
    }

    private function audit(Platform $platform, User $actor, $createdAt): AuditLog
    {
        return AuditLog::query()->create([
            'platform_id' => $platform->id,
            'actor_id' => $actor->id,
            'action' => 'retention_test',
            'entity_type' => 'client',
            'entity_id' => 1,
            'created_at' => $createdAt,
        ]);
    }
}
