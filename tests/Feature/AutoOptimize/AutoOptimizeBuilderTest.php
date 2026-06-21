<?php

namespace Tests\Feature\AutoOptimize;

use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Models\AutoOptimizeRun;
use App\Models\Client;
use App\Models\Platform;
use App\Services\AutoOptimize\AutoOptimizeAlertService;
use App\Services\AutoOptimize\AutoOptimizeBuilder;
use App\Services\AutoOptimize\AutoOptimizeImagePicker;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\LanguageDetector;
use App\Services\Seo\ProfileSnapshotBuilder;
use App\Services\WpSyncFactory;
use App\Services\WpSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoOptimizeBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;
    private AutoOptimizePlan $plan;
    private AutoOptimizeRun $run;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform = Platform::query()->create([
            'name' => 'Kenya', 'domain' => 'k.example', 'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi', 'wp_api_url' => 'https://k.example/wp-json',
            'phone_prefix' => '254', 'currency_code' => 'KES', 'is_active' => true,
        ]);
        $this->plan = AutoOptimizePlan::query()->create([
            'name' => 'P', 'platform_id' => $this->platform->id, 'enabled' => false,
            'actions' => ['optimize_bio' => true, 'switch_main_image' => false, 'generation' => ['respect_existing_language' => false]],
        ]);
        $this->run = AutoOptimizeRun::query()->create([
            'auto_optimize_plan_id' => $this->plan->id, 'platform_id' => $this->platform->id, 'status' => 'running',
        ]);
        $this->client = Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => 101, 'seo_score' => 40]);
    }

    // A realistic ~55-word bio (SimHash is reliable at this length).
    private const EXISTING_BIO = '<p>Amara is a warm and confident companion based in the Westlands area of Nairobi. She offers relaxed incall and outcall sessions for guests who value good conversation and an easy, unhurried mood. With a playful personality and a genuine smile, she keeps every meeting comfortable, private and memorable for those looking for quality company in the city.</p>';

    public function test_previous_bio_is_read_from_post_content_not_empty(): void
    {
        $item = $this->buildWith(
            wpProfile: ['post' => ['content' => self::EXISTING_BIO]],   // ← real WP shape
            generatedBio: '<p>Zara brings playful coastal energy to private companionship in Mombasa. She is available for dinner dates and quiet evenings, blending sharp wit with a calm, attentive presence that makes every guest feel at ease throughout their time together by the ocean.</p>',
            generatedScore: 80,
        );

        $this->assertSame('pending', $item->status);
        $this->assertSame(self::EXISTING_BIO, $item->previous_bio_html, 'previous bio must come from post.content');
        $this->assertNotSame(md5(''), $item->source_bio_hash);
    }

    public function test_skips_only_when_new_bio_matches_clients_own_current_bio(): void
    {
        // Generation returns essentially the SAME substantial text → genuine no-op → skip
        $item = $this->buildWith(
            wpProfile: ['post' => ['content' => self::EXISTING_BIO]],
            generatedBio: self::EXISTING_BIO,
            generatedScore: 80,
        );

        $this->assertSame('skipped', $item->status);
        $this->assertSame('bio_unchanged_from_current', $item->reason);
    }

    public function test_empty_current_bio_is_never_a_no_op(): void
    {
        $item = $this->buildWith(
            wpProfile: ['post' => ['content' => '']],     // no existing bio
            generatedBio: '<p>Brand new bio for a profile that had none.</p>',
            generatedScore: 75,
        );

        $this->assertSame('pending', $item->status);
        $this->assertSame('', $item->previous_bio_html);
    }

    private function buildWith(array $wpProfile, string $generatedBio, int $generatedScore): AutoOptimizeItem
    {
        $wpMock = $this->createMock(WpSyncService::class);
        $wpMock->method('getClientProfile')->willReturn($wpProfile);
        $factory = $this->createMock(WpSyncFactory::class);
        $factory->method('forPlatform')->willReturn($wpMock);

        $bioMock = $this->createMock(BioGenerationService::class);
        $bioMock->method('generate')->willReturn([
            'bio_html' => $generatedBio,
            'score' => $generatedScore,
            'breakdown' => ['word_count' => 25, 'links' => 20, 'completeness' => 20, 'media' => 15],
            'provider_used' => 'claude',
            'language_used' => 'en',
            'fallback_used' => false,
            'usage' => ['estimated_cost_usd' => 0.0004],
        ]);

        $builder = new AutoOptimizeBuilder(
            $factory,
            app(ProfileSnapshotBuilder::class),
            $bioMock,
            app(AutoOptimizeImagePicker::class),
            app(AutoOptimizeAlertService::class),
            app(LanguageDetector::class),
        );

        $item = AutoOptimizeItem::query()->create([
            'auto_optimize_plan_id' => $this->plan->id,
            'auto_optimize_run_id' => $this->run->id,
            'platform_id' => $this->platform->id,
            'client_id' => $this->client->id,
            'status' => 'queued',
        ]);

        $builder->buildItem($item);
        return $item->fresh();
    }
}
