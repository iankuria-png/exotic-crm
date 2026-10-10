<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanAuditEvent;
use App\Models\DbScanFinding;
use App\Models\DbScanFindingEvent;
use App\Models\DbScanSchedule;
use App\Services\DbScanner\FindingInterpretation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

class DbObservatoryExperienceTest extends TestCase
{
    use BuildsWordPressFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootScanner();
        $this->withoutExceptionHandling();
    }

    private function finding($platform, array $extra = []): DbScanFinding
    {
        return DbScanFinding::query()->create(array_replace([
            'platform_id' => $platform->id, 'fingerprint' => hash('sha256', Str::uuid()),
            'rule_key' => 'access.account_campaign', 'rule_version_hash' => str_repeat('a', 64),
            'pack' => 'core', 'pack_version' => '1', 'category' => 'access', 'confidence' => 'strong',
            'severity' => 'critical', 'title' => 'Account campaign', 'subject' => ['object_id' => 474],
            'subject_hash' => hash('sha256', Str::uuid()), 'evidence' => ['details' => ['login' => 'Moody', 'at_utc' => '2026-09-10T22:26:41Z']],
            'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()->subMinute(),
        ], $extra));
    }

    public function test_family_filters_match_summary_and_keep_severity_independent_of_age(): void
    {
        $p = $this->marketPlatform();
        $cases = [
            ['login_campaign', ['rule_key' => 'malware.reinfection_cluster', 'category' => 'malware']],
            ['login_campaign', []],
            ['stored_script', ['rule_key' => 'malware.remote_loader', 'category' => 'malware', 'severity' => 'warn', 'confidence' => 'needs_review']],
            ['confirmed_payload', ['rule_key' => 'malware.payload', 'category' => 'malware', 'confidence' => 'confirmed']],
            ['permissions', ['rule_key' => 'access.role_grants_admin_capability']],
            ['failed_attempt', ['rule_key' => 'access.activity_behaviour', 'title' => 'Daily failed-login pressure across high-volume IPs', 'severity' => 'warn']],
            ['activity_record', ['rule_key' => 'access.activity_behaviour', 'severity' => 'info']],
            ['access_review', ['rule_key' => 'access.application_passwords']],
            ['hygiene', ['rule_key' => 'hygiene.autoload', 'category' => 'hygiene']],
        ];
        foreach ($cases as [$family, $extra]) {
            $f = $this->finding($p, $extra);
            $this->assertSame($family, FindingInterpretation::family($f));
        }
        Sanctum::actingAs($this->adminUser());
        $overview = $this->getJson('/api/crm/db-observatory/overview')->assertOk()->json();
        foreach ($overview['evidence_families'] as $family) {
            $result = $this->getJson('/api/crm/db-observatory/findings?status=active&family='.$family['key'])->assertOk();
            $this->assertSame($family['findings'], $result->json('meta.total'));
            foreach ($result->json('data') as $row) {
                $this->assertSame($family['key'], $row['interpretation']['family']);
            }
        }
        $old = $this->getJson('/api/crm/db-observatory/findings?family=login_campaign')->json('data.0');
        $this->assertSame('critical', $old['severity']);
        $this->assertSame('2026-09-10T22:26:41+00:00', $old['interpretation']['event_at']);
    }

    public function test_unknown_invalid_dates_daily_precision_and_campaign_start_are_explicit(): void
    {
        $p = $this->marketPlatform();
        foreach (['2026-02-31T00:00:00Z', 'yesterday', '', null] as $date) {
            $this->assertNull(FindingInterpretation::eventAt($this->finding($p, ['evidence' => ['details' => ['at_utc' => $date]]])));
        }
        $daily = $this->finding($p, ['rule_key' => 'access.activity_behaviour', 'title' => 'Daily failed-login pressure across high-volume IPs']);
        $this->assertSame('day', FindingInterpretation::summary($daily)['event_precision']);
        $this->assertSame('Earliest recorded activity', FindingInterpretation::summary($this->finding($p))['event_label']);
    }

    public function test_correlations_group_exact_indicators_and_links_stay_scoped(): void
    {
        $a = $this->marketPlatform('Egypt', 'egypt.test');
        $b = $this->marketPlatform('Namibia', 'namibia.test');
        $allowed = $this->finding($a);
        $denied = $this->finding($b, ['evidence' => ['details' => ['login' => 'secret-subject', 'at_utc' => '2025-01-01T00:00:00Z']]]);
        $extra = ['rule_key' => 'malware.reinfection_cluster', 'category' => 'malware', 'behavior' => 'cross_market_campaign', 'evidence' => ['details' => ['indicator_type' => 'successful_ip', 'indicator' => '192.0.2.1', 'platform_ids' => [$a->id, $b->id], 'linked_finding_ids' => [$allowed->id, $denied->id]]]];
        $f = $this->finding($a, $extra);
        $this->finding($b, $extra);
        Sanctum::actingAs($this->adminUser());
        $groups = $this->getJson('/api/crm/db-observatory/findings?group_by=related&family=login_campaign')->assertOk()->json('review_groups');
        $group = collect($groups)->firstWhere('rule_key', 'malware.reinfection_cluster');
        $this->assertSame(2, $group['findings']);
        $members = $this->getJson('/api/crm/db-observatory/findings?review_group='.$group['key'])->assertOk();
        $this->assertSame(2, $members->json('meta.total'));
        Sanctum::actingAs($this->adminUser('sub_admin', [$a->id]));
        $detail = $this->getJson('/api/crm/db-observatory/findings/'.$f->id)->assertOk();
        $this->assertSame([$allowed->id], $detail->json('finding.evidence.details.linked_finding_ids'));
        $this->assertSame('2026-09-10T22:26:41+00:00', $detail->json('finding.linked_activity_start'));
        $this->assertNull($detail->json('finding.interpretation.event_at'));
        $this->assertStringNotContainsString('secret-subject', $detail->getContent());
        $this->assertStringNotContainsString('namibia.test', $detail->getContent());
        $group = $this->getJson('/api/crm/db-observatory/findings?group_by=related&rule_key=malware.reinfection_cluster')->json('review_groups.0');
        $this->assertSame(1, $group['markets']);
        $this->assertSame(1, $group['findings']);
    }

    public function test_role_groups_include_role_name_and_account_subjects_remain_market_specific(): void
    {
        $a = $this->marketPlatform();
        $b = $this->marketPlatform('Kenya', 'kenya.test');
        foreach ([$a, $b] as $p) {
            foreach (['sales', 'seo'] as $role) {
                $this->finding($p, ['rule_key' => 'access.role_grants_admin_capability', 'evidence' => ['details' => ['role' => $role, 'definition_hash' => 'same-capabilities']]]);
            }
            $this->finding($p, ['subject_hash' => str_repeat('b', 64)]);
        }
        $groups = FindingInterpretation::groups(DbScanFinding::all(), [$a->id => 'Zimbabwe', $b->id => 'Kenya']);
        $this->assertCount(4, $groups);
        $this->assertCount(2, array_filter($groups, fn ($g) => $g['findings'] === 2));
    }

    public function test_report_is_audited_without_resolving_and_tracks_ties_and_later_history(): void
    {
        $this->freezeTime();
        $p = $this->marketPlatform();
        $f = $this->finding($p);
        Sanctum::actingAs($admin = $this->adminUser());
        $path = '/api/crm/db-observatory/findings/'.$f->id;
        $this->patchJson($path, ['record_remediation' => true, 'note' => 'Exact account lock verified; tracker proof retained.'])->assertOk()->assertJsonPath('status', 'open')->assertJsonPath('workflow.stage', 'awaiting_verification');
        $this->assertTrue(DbScanFindingEvent::query()->where('finding_id', $f->id)->where('type', 'remediation_recorded')->where('actor_id', $admin->id)->exists());
        $this->assertTrue(DbScanAuditEvent::query()->where('action', 'remediation_report')->exists());
        $this->assertNull($f->fresh()->resolved_at);
        $overview = $this->getJson('/api/crm/db-observatory/overview')->assertOk();
        $this->assertSame(1, $overview->json('work.awaiting_verification'));
        $this->assertSame(0, $overview->json('work.needs_follow_up'));
        $this->assertSame(1, $this->getJson('/api/crm/db-observatory/findings?workflow=awaiting_verification')->json('meta.total'));
        $f->forceFill(['last_seen_at' => now()])->save();
        $this->getJson($path)->assertOk()->assertJsonPath('finding.workflow.stage', 'timing_uncertain');
        $this->travel(2)->seconds();
        $f->forceFill(['last_seen_at' => now()])->save();
        $this->getJson($path)->assertOk()->assertJsonPath('finding.workflow.stage', 'evidence_after_report');
        $this->assertSame(1, $this->getJson('/api/crm/db-observatory/findings?workflow=needs_follow_up')->json('meta.total'));
        $f->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
        $this->getJson($path)->assertOk()->assertJsonPath('finding.workflow.stage', 'resolved')->assertJsonPath('finding.interpretation.current_controls', 'Not established by this finding');
    }

    public function test_report_requires_meaningful_evidence_and_cannot_change_status_or_bypass_permissions(): void
    {
        $this->withExceptionHandling();
        $p = $this->marketPlatform();
        $f = $this->finding($p);
        $path = '/api/crm/db-observatory/findings/'.$f->id;
        Sanctum::actingAs($this->adminUser());
        foreach ([['record_remediation' => true], ['record_remediation' => true, 'note' => str_repeat(' ', 12)], ['record_remediation' => true, 'note' => 'Documented repair', 'status' => 'acknowledged']] as $payload) {
            $this->patchJson($path, $payload)->assertStatus(422);
        }
        $this->patchJson($path, ['status' => 'resolved'])->assertStatus(422);
        $f->forceFill(['status' => 'resolved'])->save();
        $this->patchJson($path, ['record_remediation' => true, 'note' => 'Documented repair'])->assertStatus(422);
        Sanctum::actingAs($this->adminUser('sub_admin', [$p->id]));
        $this->patchJson($path, ['record_remediation' => true, 'note' => 'Documented repair'])->assertForbidden();
        $this->assertSame(0, DbScanFindingEvent::query()->where('type', 'remediation_recorded')->count());
    }

    public function test_markets_include_blocked_domains_and_disabled_schedules_never_appear_due(): void
    {
        $p = $this->marketPlatform('Uganda', 'uganda.test');
        $other = $this->marketPlatform('UG', 'ug.test');
        $pdo = $this->newFixture();
        $this->connectFixture($p, $this->fixturePath($pdo));
        $attrs = ['name' => 'Daily', 'profile' => 'deep', 'cron' => '0 0 * * *', 'market_scope' => ['mode' => 'platforms', 'platform_ids' => [$p->id]], 'enabled' => false, 'next_due_at' => now()->addHour()];
        $schedule = DbScanSchedule::query()->create($attrs);
        Sanctum::actingAs($this->adminUser());
        $result = collect($this->getJson('/api/crm/db-observatory/markets')->assertOk()->json('data'))->keyBy('platform_id');
        $this->assertTrue($result[$p->id]['reader_ready']);
        $this->assertNull($result[$p->id]['next_schedule']);
        $this->assertFalse($result[$other->id]['reader_ready']);
        $this->assertSame('ug.test', $result[$other->id]['domain']);
        $this->assertSame('Configure the reader connection', $result[$other->id]['next_action']);
        $this->assertNull($result[$other->id]['last_sweep']);
        $schedule->forceFill(['enabled' => true])->save();
        $this->assertNotNull(collect($this->getJson('/api/crm/db-observatory/markets')->json('data'))->keyBy('platform_id')[$p->id]['next_schedule']);
    }

    public function test_related_group_pages_share_one_selection_and_complete_family_totals(): void
    {
        $p = $this->marketPlatform();
        for ($i = 1; $i <= 12; $i++) {
            for ($member = 0; $member < 2; $member++) {
                $this->finding($p, ['rule_key' => 'malware.reinfection_cluster', 'category' => 'malware', 'evidence' => ['details' => ['indicator_type' => 'successful_ip', 'indicator' => '192.0.2.'.$i]]]);
            }
        }
        Sanctum::actingAs($this->adminUser());
        $first = $this->getJson('/api/crm/db-observatory/findings?group_by=related&page=1')->assertOk()->json();
        $second = $this->getJson('/api/crm/db-observatory/findings?group_by=related&page=2')->assertOk()->json();
        $this->assertCount(10, $first['review_groups']);
        $this->assertCount(2, $second['review_groups']);
        $this->assertSame(12, $second['review_meta']['total']);
        $this->assertSame(24, $second['review_meta']['findings_considered']);
        $this->assertSame(24, $second['families'][0]['findings']);
        $this->assertSame([], array_intersect(array_column($first['review_groups'], 'key'), array_column($second['review_groups'], 'key')));
    }

    public function test_correlation_presentation_uses_batched_queries(): void
    {
        $p = $this->marketPlatform();
        $link = $this->finding($p);
        for ($i = 0; $i < 50; $i++) {
            $this->finding($p, ['rule_key' => 'malware.reinfection_cluster', 'category' => 'malware', 'behavior' => 'cross_market_campaign', 'evidence' => ['details' => ['platform_ids' => [$p->id], 'linked_finding_ids' => [$link->id]]]]);
        }
        Sanctum::actingAs($this->adminUser());
        DB::enableQueryLog();
        $this->getJson('/api/crm/db-observatory/findings?per_page=100')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(22, count($queries), 'Linked evidence and remediation must not query once per correlation.');
    }

    public function test_group_cap_and_member_export_share_the_same_bounded_selection(): void
    {
        $p = $this->marketPlatform();
        $base = $this->finding($p, ['rule_key' => 'malware.reinfection_cluster', 'category' => 'malware', 'behavior' => 'cross_market_campaign', 'evidence' => ['details' => ['indicator_type' => 'successful_ip', 'indicator' => '192.0.2.25']], 'last_seen_at' => now()]);
        $attrs = $base->getAttributes();
        unset($attrs['id']);
        for ($offset = 0; $offset < 10001; $offset += 400) {
            $batch = [];
            for ($i = $offset; $i < min($offset + 400, 10001); $i++) {
                $batch[] = array_replace($attrs, ['fingerprint' => hash('sha256', 'cap-'.$i), 'subject_hash' => hash('sha256', 'subject-'.$i)]);
            }
            DB::table('db_scan_findings')->insert($batch);
        }
        Sanctum::actingAs($this->adminUser());
        $r = $this->getJson('/api/crm/db-observatory/findings?group_by=related')->assertOk();
        $this->assertTrue($r->json('review_meta.sampled'));
        $this->assertSame(10000, $r->json('review_groups.0.findings'));
        $this->assertSame(10002, $r->json('meta.total'));
        $group = $r->json('review_groups.0.key');
        $members = $this->getJson('/api/crm/db-observatory/findings?review_group='.$group)->assertOk();
        $this->assertTrue($members->json('selection_sampled'));
        $this->assertSame(10000, $members->json('meta.total'));
        $export = $this->get('/api/crm/db-observatory/findings/export?review_group='.$group)->assertOk();
        $this->assertSame('1', $export->headers->get('X-Selection-Sampled'));
        $this->assertCount(10001, array_filter(explode("\n", $export->streamedContent())));
    }
}
