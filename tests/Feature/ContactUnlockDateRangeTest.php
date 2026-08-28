<?php

namespace Tests\Feature;

use App\Models\ContactUnlockEvent;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\VisitorContactUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ContactUnlockDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_unlock_trail_and_summary_by_attempt_date_window(): void
    {
        $platform = Platform::factory()->create();
        $inside = $this->unlock($platform, now()->subDay(), 'INSIDE');
        $outside = $this->unlock($platform, now()->subDays(10), 'OUTSIDE');
        $this->assertNotNull($inside);
        $this->assertNotNull($outside);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->getJson('/api/crm/settings/billing/contact-unlock?from='.now()->subDays(2)->toDateString().'&to='.now()->toDateString());

        $response->assertOk()
            ->assertJsonPath('summary.total_unlocks', 1)
            ->assertJsonPath('recent_unlocks_meta.total', 1)
            ->assertJsonPath('recent_unlocks.0.payment.reference', 'INSIDE');
    }

    public function test_pulse_custom_range_matches_equivalent_preset_and_missing_custom_dates_falls_back_to_today(): void
    {
        $platform = Platform::factory()->create();
        $this->unlock($platform, now(), 'TODAY');
        $this->unlock($platform, now()->subDays(10), 'OLD');
        ContactUnlockEvent::query()->create([
            'platform_id' => $platform->id,
            'event_type' => ContactUnlockEvent::TYPE_CTA_CLICK,
            'session_hash' => hash('sha256', 'today'),
            'event_id_hash' => hash('sha256', 'event-today'),
            'occurred_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $today = now()->toDateString();
        $custom = $this->getJson("/api/crm/settings/billing/contact-unlock/pulse?range=custom&from={$today}&to={$today}&platform_id={$platform->id}");
        $preset = $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?range=today&platform_id='.$platform->id);
        $fallback = $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?range=custom&platform_id='.$platform->id);

        $custom->assertOk();
        $preset->assertOk();
        $fallback->assertOk();
        $this->assertSame($preset->json('kpis.checkout_starts'), $custom->json('kpis.checkout_starts'));
        $this->assertSame($preset->json('kpis.checkout_starts'), $fallback->json('kpis.checkout_starts'));
    }

    public function test_export_honors_date_window_and_writes_meta_sheet(): void
    {
        $platform = Platform::factory()->create();
        $this->unlock($platform, now()->subDay(), 'INSIDE');
        $this->unlock($platform, now()->subDays(10), 'OUTSIDE');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();
        $response = $this->postJson('/api/crm/settings/billing/contact-unlock/export', [
            'from' => $from,
            'to' => $to,
        ]);

        $response->assertOk()
            ->assertHeader('X-Export-Row-Total', '1')
            ->assertHeader('X-Export-Truncated', 'false');

        $path = tempnam(sys_get_temp_dir(), 'unlock-export-').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Contact Unlocks');
        $meta = $spreadsheet->getSheetByName('Export Meta');

        $this->assertSame('INSIDE', $sheet->getCell('P2')->getValue());
        $this->assertSame(5000, (int) $meta->getCell('B2')->getValue());
        $this->assertSame(1, (int) $meta->getCell('B3')->getValue());
        $this->assertStringContainsString($from, (string) $meta->getCell('B6')->getValue());
        $this->assertStringContainsString($to, (string) $meta->getCell('B6')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }

    private function unlock(Platform $platform, $createdAt, string $reference): VisitorContactUnlock
    {
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
            'status' => 'completed',
            'reference_number' => $reference,
            'amount' => 299,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'completed_at' => $createdAt,
        ]);

        $unlock = VisitorContactUnlock::query()->create([
            'platform_id' => $platform->id,
            'payment_id' => $payment->id,
            'scope' => VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
            'status' => VisitorContactUnlock::STATUS_ACTIVE,
            'gross_amount' => 299,
            'credit_amount' => 0,
            'amount_due' => 299,
            'visitor_phone_hash' => hash('sha256', Str::random(16)),
            'visitor_phone_masked' => '254*****111',
            'idempotency_key_hash' => hash('sha256', Str::random(16)),
            'session_token_hash' => hash('sha256', Str::random(16)),
            'public_token_hash' => hash('sha256', Str::random(16)),
        ]);
        $unlock->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $unlock;
    }
}
