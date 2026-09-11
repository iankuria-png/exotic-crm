<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use App\Services\MissedChatsCountService;
use App\Services\Ops\OperationsSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MissedChatsCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->switchSupportBoard(true);
    }

    private function switchSupportBoard(bool $on): void
    {
        $settings = app(OperationsSettingsService::class);
        $settings->update(
            [['key' => 'ops.support_board.enabled', 'value' => $on]],
            null,
            'admin'
        );
        $settings->forget();
    }

    private function platform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Market '.Str::random(4),
            'domain' => 'm-'.Str::random(6).'.example.test',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'support_board_api_url' => 'https://cloud.board.support/script/include/api.php',
            'support_board_token' => 'tok-'.Str::random(8),
            'support_board_sender_id' => '1',
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin '.Str::random(4),
            'email' => Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }

    private function seedCount(Platform $platform, int $count): void
    {
        Cache::put(
            MissedChatsCountService::cacheKey((int) $platform->id),
            ['count' => $count, 'computed_at' => now()->toIso8601String()],
            now()->addMinutes(90)
        );
    }

    public function test_the_dashboard_request_never_calls_support_board(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $platform = $this->platform();
        $this->seedCount($platform, 7);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/crm/dashboard')->assertOk();

        // The whole point of the change: the read path holds no socket.
        Http::assertNothingSent();
    }

    public function test_the_total_sums_the_cached_figure_for_each_market(): void
    {
        $a = $this->platform();
        $b = $this->platform();
        $this->seedCount($a, 4);
        $this->seedCount($b, 6);

        $this->assertSame(10, app(MissedChatsCountService::class)->cachedTotal(null));
    }

    public function test_only_the_markets_in_scope_are_counted(): void
    {
        $mine = $this->platform();
        $theirs = $this->platform();
        $this->seedCount($mine, 3);
        $this->seedCount($theirs, 99);

        $this->assertSame(
            3,
            app(MissedChatsCountService::class)->cachedTotal([(int) $mine->id])
        );
    }

    public function test_an_empty_scope_is_zero_but_an_uncomputed_market_is_null(): void
    {
        $service = app(MissedChatsCountService::class);

        // Scoped to no markets at all is a real answer: zero.
        $this->assertSame(0, $service->cachedTotal([]));

        // A configured market with nothing cached yet has no answer, which the
        // tile renders as a dash rather than a misleading zero.
        $this->platform();
        $this->assertNull($service->cachedTotal(null));
    }

    public function test_a_market_that_fails_its_refresh_keeps_its_previous_figure(): void
    {
        $platform = $this->platform();
        $this->seedCount($platform, 5);

        Http::fake([
            '*' => Http::response('gateway down', 502),
        ]);

        $result = app(MissedChatsCountService::class)->refreshAll();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['refreshed']);
        $this->assertSame(
            5,
            app(MissedChatsCountService::class)->cachedTotal(null),
            'a failed refresh must not blank the previous count'
        );
    }

    public function test_the_refresh_walks_pages_and_excludes_resolved_conversations(): void
    {
        $platform = $this->platform();

        // Support Board wraps payloads in {success, response} and names the field
        // conversation_status_code on the wire; the service normalizes it to
        // status_code. 4 is resolved and must not count. Page two ends the walk.
        Http::fakeSequence()
            ->push([
                'success' => true,
                'response' => [
                    ['conversation_id' => 1, 'conversation_status_code' => 0],
                    ['conversation_id' => 2, 'conversation_status_code' => 4],
                    ['conversation_id' => 3, 'conversation_status_code' => 2],
                ],
            ])
            ->push(['success' => true, 'response' => []]);

        $result = app(MissedChatsCountService::class)->refreshAll();

        $this->assertSame(1, $result['refreshed']);
        $this->assertSame(2, app(MissedChatsCountService::class)->cachedTotal(null));
    }

    public function test_the_refresh_stands_down_while_support_board_is_switched_off(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->platform();
        $this->switchSupportBoard(false);

        $result = app(MissedChatsCountService::class)->refreshAll();

        $this->assertSame(['refreshed' => 0, 'failed' => 0, 'skipped' => 0], $result);
        Http::assertNothingSent();
    }

    public function test_the_command_reports_what_it_did(): void
    {
        $this->platform();
        $this->switchSupportBoard(false);

        $this->artisan('crm:refresh-missed-chats')
            ->expectsOutputToContain('Support Board is switched off.')
            ->assertSuccessful();
    }
}
