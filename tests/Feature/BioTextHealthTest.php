<?php

namespace Tests\Feature;

use App\Models\BioTextFinding;
use App\Models\BioTextScan;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\WpSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BioTextHealthTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://ci.example.test/wp-json/exotic-crm-sync/v1';

    /** What the old SEO link step wrote: UTF-8 read as Latin-1. */
    private const GARBLED = "<p>\u{00C3}\u{0080} <a href=\"/escorts/cocody\">Cocody</a>, sans d\u{00C3}\u{00A9}tour. Une pr\u{00C3}\u{00A9}sence qui met \u{00C3}\u{00A0} l\u{00E2}\u{0080}\u{0099}aise.</p>";

    private const REPAIRED = '<p>À <a href="/escorts/cocody">Cocody</a>, sans détour. Une présence qui met à l’aise.</p>';

    /** @var array<int, string> the fake market's bios by post ID */
    private array $bios = [];

    /** @var list<int> post IDs WordPress received an update for */
    private array $writes = [];

    /** When true the fake market ignores bio updates, as a misbehaving site would. */
    private bool $ignoreWrites = false;

    private bool $siteDown = false;

    /** Simulate a successful write followed by an unavailable verification read. */
    private bool $failReadsAfterWrite = false;

    public function test_a_check_records_broken_bios_with_a_backup_and_leaves_clean_ones_out(): void
    {
        [$platform, $admin] = $this->market([
            101 => self::GARBLED,
            102 => '<p>Sans détour, à Abidjan.</p>',
            103 => "<p>Here's a revised bio for Amina:</p><p>Warm and discreet.</p>",
            104 => '<p>Rencontre [Name], pr'."\u{00C3}\u{00A9}".'sence douce.</p>',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/bio-text-health/scans', ['platform_id' => $platform->id])->assertCreated();

        $this->getJson("/api/crm/bio-text-health?platform_id={$platform->id}")
            ->assertOk()
            ->assertJsonPath('scan.status', 'scanned')
            ->assertJsonPath('scan.profiles_scanned', 4)
            ->assertJsonPath('scan.profiles_affected', 3)
            ->assertJsonPath('scan.profiles_fixable', 2)
            ->assertJsonPath('scan.profiles_manual', 2)
            ->assertJsonPath('scan.issue_counts.broken_accents', 2)
            ->assertJsonPath('scan.issue_counts.ai_text', 2)
            ->assertJsonPath('scan.can_repair', true);

        $garbled = BioTextFinding::query()->where('wp_post_id', 101)->firstOrFail();
        $this->assertSame(self::GARBLED, $garbled->original_html);
        $this->assertSame(',broken_accents,', $garbled->kinds);
        $this->assertTrue($garbled->fixable);
        $this->assertFalse(BioTextFinding::query()->where('wp_post_id', 103)->firstOrFail()->fixable);
        $this->assertNull(BioTextFinding::query()->where('wp_post_id', 102)->first());
        $this->assertSame([], $this->writes, 'A check never writes to WordPress.');
    }

    public function test_repair_writes_the_safe_fix_verifies_it_and_restore_puts_the_backup_back_exactly(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED, 102 => '<p>Fine.</p>']);
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);

        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/repair")
            ->assertOk();

        $this->assertSame(self::REPAIRED, $this->bios[101]);
        $finding = BioTextFinding::query()->where('wp_post_id', 101)->firstOrFail();
        $this->assertSame('repaired', $finding->status);
        $this->assertSame(self::REPAIRED, $finding->repaired_html);
        $this->assertSame('repaired', $scan->fresh()->status);
        $this->assertDatabaseHas('timeline_events', ['entity_id' => $finding->client_id, 'event_type' => 'profile_bio_text_repaired']);

        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/restore")->assertOk();

        // Byte for byte: the save-time accent repair must not touch a restore.
        $this->assertSame(self::GARBLED, $this->bios[101]);
        $this->assertSame('restored', $finding->fresh()->status);
        $this->assertSame('restored', $scan->fresh()->status);
    }

    public function test_repair_skips_a_bio_fixed_since_the_check_and_backs_up_one_edited_since(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED, 102 => '<p>Ancienne pr'."\u{00C3}\u{00A9}".'sence.</p>']);
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);

        $this->bios[101] = '<p>Rewritten cleanly by the advertiser.</p>';
        $edited = '<p>Nouvelle pr'."\u{00C3}\u{00A9}".'sence, toujours cass'."\u{00C3}\u{00A9}".'e.</p>';
        $this->bios[102] = $edited;

        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/repair")->assertOk();

        $this->assertNotContains(101, $this->writes);
        $this->assertSame('unchanged', BioTextFinding::query()->where('wp_post_id', 101)->value('status'));
        $this->assertSame('<p>Nouvelle présence, toujours cassée.</p>', $this->bios[102]);
        $this->assertSame($edited, BioTextFinding::query()->where('wp_post_id', 102)->value('original_html'));
    }

    public function test_restore_leaves_a_bio_edited_after_the_repair_alone(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED]);
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);
        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/repair")->assertOk();

        $this->bios[101] = '<p>Newer text from the advertiser.</p>';
        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/restore")->assertOk();

        $this->assertSame('<p>Newer text from the advertiser.</p>', $this->bios[101]);
        $finding = BioTextFinding::query()->where('wp_post_id', 101)->firstOrFail();
        $this->assertSame('repaired', $finding->status);
        $this->assertStringContainsString('Edited after the repair', (string) $finding->error);
        $this->assertSame('failed', $scan->fresh()->status);
    }

    public function test_selected_repair_touches_only_the_chosen_bios(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED, 102 => self::GARBLED]);
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);
        $chosen = BioTextFinding::query()->where('wp_post_id', 102)->value('id');

        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/repair", ['finding_ids' => [$chosen]])->assertOk();

        $this->assertSame([102], $this->writes);
        $this->assertSame(self::GARBLED, $this->bios[101]);
        $this->getJson("/api/crm/bio-text-health/scans/{$scan->id}")
            ->assertJsonPath('data.open_fixable', 1)
            ->assertJsonPath('data.restorable', 1);
    }

    public function test_a_site_that_keeps_the_broken_text_is_reported_not_trusted(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED]);
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);
        $this->ignoreWrites = true;

        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/repair")->assertOk();

        $finding = BioTextFinding::query()->where('wp_post_id', 101)->firstOrFail();
        $this->assertSame('failed', $finding->status);
        $this->assertStringContainsString('still shows broken text', (string) $finding->error);
        $this->getJson("/api/crm/bio-text-health/scans/{$scan->id}")
            ->assertJsonPath('data.repair.failed', 1)
            ->assertJsonPath('data.can_restore', true);
    }

    public function test_a_repair_is_not_called_repaired_when_wordpress_cannot_confirm_the_write(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED]);
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);
        $this->failReadsAfterWrite = true;

        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/repair")->assertOk();

        $finding = BioTextFinding::query()->where('wp_post_id', 101)->firstOrFail();
        $this->assertSame('failed', $finding->status);
        $this->assertStringContainsString('confirm the repair', (string) $finding->error);
        $this->getJson("/api/crm/bio-text-health/scans/{$scan->id}")
            ->assertJsonPath('data.repair.repaired', 0)
            ->assertJsonPath('data.repair.failed', 1)
            ->assertJsonPath('data.can_restore', true);
    }

    public function test_a_failed_repair_can_resume_when_its_findings_are_already_queued(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED]);
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);
        BioTextFinding::query()->where('scan_id', $scan->id)->update(['status' => 'queued']);
        $scan->forceFill(['status' => 'failed', 'notes' => 'The previous worker stopped.'])->save();

        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/repair")->assertOk();

        $this->assertSame(self::REPAIRED, $this->bios[101]);
        $this->assertSame('repaired', BioTextFinding::query()->where('wp_post_id', 101)->value('status'));
    }

    public function test_a_site_that_stops_answering_fails_the_check_instead_of_reporting_all_clear(): void
    {
        [$platform, $admin] = $this->market(array_fill_keys(range(101, 106), self::GARBLED));
        Sanctum::actingAs($admin);
        $this->siteDown = true;

        $this->postJson('/api/crm/bio-text-health/scans', ['platform_id' => $platform->id])->assertCreated();

        $scan = BioTextScan::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $scan->status);
        $this->assertNull($scan->scanned_at);
        $this->assertStringContainsString('stopped answering', (string) $scan->notes);
    }

    public function test_the_contact_scrubbed_copy_is_repaired_and_restored_with_the_live_bio(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED]);
        $client = Client::query()->where('wp_post_id', 101)->firstOrFail();
        $client->forceFill(['bio_original_html' => self::GARBLED])->save();
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);

        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/repair")->assertOk();
        $this->assertSame(self::REPAIRED, $client->fresh()->bio_original_html);

        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/restore")->assertOk();
        $this->assertSame(self::GARBLED, $client->fresh()->bio_original_html);
    }

    public function test_every_bio_write_repairs_garbled_accents_unless_restoring_verbatim(): void
    {
        [$platform] = $this->market([101 => '<p>Fine.</p>']);
        $wp = new WpSyncService($platform);

        $wp->updateClientProfile(101, ['content' => self::GARBLED, 'city' => 'Abidjan']);
        $this->assertSame(self::REPAIRED, $this->bios[101]);

        $wp->updateClientProfile(101, ['content' => self::GARBLED], repairBioText: false);
        $this->assertSame(self::GARBLED, $this->bios[101]);
    }

    public function test_export_lists_each_bio_before_and_after(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED]);
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);
        $this->postJson("/api/crm/bio-text-health/scans/{$scan->id}/repair")->assertOk();

        $csv = $this->get("/api/crm/bio-text-health/scans/{$scan->id}/export")->assertOk()->streamedContent();

        $this->assertStringStartsWith("client_id,wp_post_id,name,issues,status,note,bio_before,bio_after,repaired_at,restored_at\n", $csv);
        $this->assertStringContainsString(',101,', $csv);
        $this->assertStringContainsString('broken_accents,repaired', $csv);
        $this->assertStringContainsString('détour', $csv);
    }

    public function test_findings_filter_by_view_issue_and_name(): void
    {
        [$platform, $admin] = $this->market([
            101 => self::GARBLED,
            103 => "<p>Here's a revised bio for Amina:</p>",
        ]);
        Sanctum::actingAs($admin);
        $scan = $this->scan($platform);

        $this->getJson("/api/crm/bio-text-health/scans/{$scan->id}/findings?view=manual")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.wp_post_id', 103);
        $this->getJson("/api/crm/bio-text-health/scans/{$scan->id}/findings?kind=broken_accents")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.fixable', true);
        $this->getJson("/api/crm/bio-text-health/scans/{$scan->id}/findings?search=Profile%20103")
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_one_run_per_market_and_only_the_latest_check_can_repair(): void
    {
        [$platform, $admin] = $this->market([101 => self::GARBLED]);
        Sanctum::actingAs($admin);
        $old = $this->scan($platform);
        $this->scan($platform);

        $this->postJson("/api/crm/bio-text-health/scans/{$old->id}/repair")->assertStatus(409);

        BioTextScan::query()->latest('id')->firstOrFail()->forceFill(['status' => 'repairing'])->save();
        $this->postJson('/api/crm/bio-text-health/scans', ['platform_id' => $platform->id])->assertStatus(409);
    }

    public function test_sales_users_cannot_open_or_run_bio_text_checks(): void
    {
        [$platform] = $this->market([101 => self::GARBLED]);
        Sanctum::actingAs(User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]));

        $this->getJson("/api/crm/bio-text-health?platform_id={$platform->id}")->assertForbidden();
        $this->postJson('/api/crm/bio-text-health/scans', ['platform_id' => $platform->id])->assertForbidden();
    }

    private function scan(Platform $platform): BioTextScan
    {
        $this->postJson('/api/crm/bio-text-health/scans', ['platform_id' => $platform->id])->assertCreated();

        return BioTextScan::query()->latest('id')->firstOrFail();
    }

    /**
     * A market whose WordPress keeps bios in memory: reads return them and
     * updates change them, so repair, verify and restore run end to end.
     *
     * @param  array<int, string>  $bios
     * @return array{0: Platform, 1: User}
     */
    private function market(array $bios): array
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => self::API,
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        foreach (array_keys($bios) as $postId) {
            Client::factory()->create([
                'platform_id' => $platform->id,
                'wp_post_id' => $postId,
                'name' => "Profile {$postId}",
            ]);
        }
        $this->bios = $bios;

        Http::fake(function (HttpRequest $request) {
            if (! preg_match('#/clients/(\d+)(/update)?$#', parse_url($request->url(), PHP_URL_PATH), $m)) {
                return Http::response(['ok' => true]);
            }
            $postId = (int) $m[1];

            if (! empty($m[2])) {
                $this->writes[] = $postId;
                if (! $this->ignoreWrites) {
                    $this->bios[$postId] = (string) data_get($request->data(), 'fields.content', $this->bios[$postId] ?? '');
                }

                return Http::response(['ok' => true]);
            }

            if ($this->siteDown || ($this->failReadsAfterWrite && $this->writes !== []) || ! array_key_exists($postId, $this->bios)) {
                return Http::response(['message' => 'down'], 503);
            }

            return Http::response(['wp_post_id' => $postId, 'post' => ['id' => $postId, 'content' => $this->bios[$postId]]]);
        });

        return [$platform, User::factory()->create(['role' => 'admin', 'status' => 'active'])];
    }
}
