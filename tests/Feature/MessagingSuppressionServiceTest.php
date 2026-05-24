<?php

namespace Tests\Feature;

use App\Models\MessagingSuppression;
use App\Models\Platform;
use App\Models\User;
use App\Services\Messaging\SuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingSuppressionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_detects_active_global_and_platform_suppressions(): void
    {
        $platform = Platform::factory()->create();
        $service = app(SuppressionService::class);

        $service->recordOptOut('0748612016', 'whatsapp', 'keyword_stop');

        $this->assertTrue($service->isSuppressed('+254748612016', 'whatsapp'));
        $this->assertTrue($service->isSuppressed('+254748612016', 'whatsapp', $platform->id));
        $this->assertFalse($service->isSuppressed('+254748612016', 'sms', $platform->id));
    }

    public function test_all_channel_suppression_blocks_individual_channels(): void
    {
        $service = app(SuppressionService::class);

        $service->recordOptOut('0748612016', 'all', 'manual');

        $this->assertTrue($service->isSuppressed('254748612016', 'whatsapp'));
        $this->assertTrue($service->isSuppressed('254748612016', 'sms'));
        $this->assertTrue($service->isSuppressed('254748612016', 'email'));
    }

    public function test_record_opt_out_is_idempotent_for_active_suppressions(): void
    {
        $platform = Platform::factory()->create();
        $service = app(SuppressionService::class);

        $first = $service->recordOptOut('0748612016', 'whatsapp', 'keyword_stop', platformId: $platform->id);
        $second = $service->recordOptOut('+254748612016', 'whatsapp', 'keyword_stop', platformId: $platform->id);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, MessagingSuppression::count());
    }

    public function test_revoked_suppressions_do_not_block_and_history_can_repeat(): void
    {
        $platform = Platform::factory()->create();
        $actor = User::factory()->create();
        $service = app(SuppressionService::class);

        $first = $service->recordOptOut('0748612016', 'whatsapp', 'keyword_stop', platformId: $platform->id);
        $service->revoke($first, $actor);

        $this->assertFalse($service->isSuppressed('254748612016', 'whatsapp', $platform->id));

        $second = $service->recordOptOut('254748612016', 'whatsapp', 'manual', platformId: $platform->id);

        $this->assertFalse($first->is($second));
        $this->assertSame(2, MessagingSuppression::count());
        $this->assertTrue($service->isSuppressed('254748612016', 'whatsapp', $platform->id));
        $this->assertSame($actor->id, $first->refresh()->revoked_by);
    }
}
