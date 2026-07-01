<?php

namespace Tests\Feature;

use App\Jobs\ProcessPushUploadJob;
use App\Jobs\SendPushNotificationJob;
use App\Models\Client;
use App\Models\IntegrationSetting;
use App\Models\Platform;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Models\PushSubscriberSnapshot;
use App\Models\User;
use App\Services\PushCampaign\ProfileExtractionService;
use App\Services\PushCampaign\PushCampaignItemMatchService;
use App\Services\PushCampaign\PushCampaignService;
use App\Services\PushCampaign\UploadBatchStatusService;
use App\Services\PushNotification\ExoticPushProvider;
use App\Services\PushNotification\PushProviderService;
use App\Services\PushNotification\SubscriberSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CrmPushCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_user_can_list_push_campaigns(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Kenya Push',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-1',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/push-campaigns');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $campaign->id);
    }

    public function test_campaign_listing_includes_platform_timezone_and_parseable_dates(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya', 'Africa/Nairobi');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Timezone Campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-timezone',
            'scheduled_at' => now()->addDay()->utc(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/push-campaigns');
        $response->assertOk()
            ->assertJsonPath('data.0.id', $campaign->id)
            ->assertJsonPath('data.0.platform.timezone', 'Africa/Nairobi');

        $scheduledAt = $response->json('data.0.scheduled_at');
        $createdAt = $response->json('data.0.created_at');

        $this->assertNotNull(Carbon::parse((string) $scheduledAt));
        $this->assertNotNull(Carbon::parse((string) $createdAt));
    }

    public function test_sales_user_cannot_access_push_campaigns(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($user);

        $this->getJson('/api/crm/push-campaigns')->assertStatus(403);
    }

    public function test_marketing_user_cannot_create_client(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        Sanctum::actingAs($user);

        $this->postJson('/api/crm/clients', [
            'platform_id' => $platform->id,
            'name' => 'Restricted Client',
        ])->assertStatus(403);
    }

    public function test_marketing_user_can_read_clients_list(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 1001,
            'name' => 'Client One',
            'phone_normalized' => '254700000001',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/crm/clients')->assertOk();
    }

    public function test_push_upload_dispatches_background_job_and_returns_batch_id(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        Queue::fake();
        Cache::flush();

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('PUSH DOCUMENT 2026.xlsx', 32, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->postJson('/api/crm/push-campaigns/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(202)->assertJsonStructure(['batch_id', 'status']);

        Queue::assertPushed(ProcessPushUploadJob::class, 1);
    }

    public function test_push_upload_dry_run_dispatches_job_with_dry_run_flag(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        Queue::fake();
        Cache::flush();

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('PUSH DOCUMENT 2026.xlsx', 32, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->postJson('/api/crm/push-campaigns/upload', [
            'file' => $file,
            'dry_run' => true,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('dry_run', true)
            ->assertJsonStructure(['batch_id', 'status']);

        Queue::assertPushed(ProcessPushUploadJob::class, function (ProcessPushUploadJob $job): bool {
            return $job->dryRun === true;
        });
    }

    public function test_small_dry_run_upload_processes_inline_without_queue_delay(): void
    {
        $platform = $this->createPlatform('Exotic Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        Cache::flush();
        config()->set('services.push_campaigns.inline_dry_run_max_rows', 50);

        Sanctum::actingAs($user);

        $filePath = storage_path('framework/testing/inline-dry-run-' . Str::uuid() . '.xlsx');
        @mkdir(dirname($filePath), 0777, true);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KENYA');
        $sheet->setCellValue('A1', 'DATE');
        $sheet->setCellValue('B1', 'PROFILE URL');
        $sheet->setCellValue('C1', '2026 MESSAGES');
        $sheet->setCellValue('D1', 'TIME');
        $sheet->setCellValue('A2', '7th January');
        $sheet->setCellValue('B2', 'https://kenya.example/escort/a/');
        $sheet->setCellValue('C2', 'Inline dry run message');
        $sheet->setCellValue('D2', '10:00:00');
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile(
            $filePath,
            'Kenya Push 2026.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->postJson('/api/crm/push-campaigns/upload', [
            'file' => $upload,
            'dry_run' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('processed_inline', true)
            ->assertJsonPath('status_payload.status', 'ready')
            ->assertJsonPath('status_payload.sheets_parsed', 1)
            ->assertJsonPath('status_payload.total_items', 1)
            ->assertJsonPath('status_payload.year', 2026);
        $this->assertSame(0, DB::table('jobs')->count());

        @unlink($filePath);
    }

    public function test_push_upload_limits_endpoint_returns_php_limits(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/push-campaigns/upload/limits');

        $response->assertOk();
        $response->assertJsonStructure([
            'upload_max_filesize',
            'post_max_size',
            'upload_max_bytes',
            'post_max_bytes',
        ]);
    }

    public function test_upload_status_includes_queue_overview_for_current_user(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        $otherUser = $this->createUser('marketing', [$platform->id]);

        /** @var UploadBatchStatusService $uploadBatchStatusService */
        $uploadBatchStatusService = app(UploadBatchStatusService::class);

        $uploadBatchStatusService->put('batch-older', [
            'batch_id' => 'batch-older',
            'status' => 'queued',
            'source_filename' => 'Older.xlsx',
            'queued_at' => now()->subMinutes(8)->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => true,
        ]);

        $uploadBatchStatusService->put('batch-current', [
            'batch_id' => 'batch-current',
            'status' => 'queued',
            'source_filename' => 'Current.xlsx',
            'queued_at' => now()->subMinutes(1)->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => true,
        ]);

        $uploadBatchStatusService->put('batch-other-user', [
            'batch_id' => 'batch-other-user',
            'status' => 'queued',
            'source_filename' => 'OtherUser.xlsx',
            'queued_at' => now()->subMinutes(2)->toDateTimeString(),
            'initiated_by' => $otherUser->id,
            'dry_run' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/push-campaigns/upload/batch-current/status');
        $response->assertOk()
            ->assertJsonPath('queue.ahead_count', 1)
            ->assertJsonPath('queue.position', 2)
            ->assertJsonCount(2, 'queue.recent')
            ->assertJsonPath('queue.recent.0.batch_id', 'batch-current')
            ->assertJsonPath('queue.recent.1.batch_id', 'batch-older');
    }

    public function test_upload_queue_endpoint_lists_current_user_batches_with_actions(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        $otherUser = $this->createUser('marketing', [$platform->id]);

        /** @var UploadBatchStatusService $uploadBatchStatusService */
        $uploadBatchStatusService = app(UploadBatchStatusService::class);
        $uploadBatchStatusService->put('queue-a', [
            'batch_id' => 'queue-a',
            'status' => 'queued',
            'source_filename' => 'Queue A.xlsx',
            'queued_at' => now()->subMinutes(2)->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => true,
        ]);
        $uploadBatchStatusService->put('queue-b', [
            'batch_id' => 'queue-b',
            'status' => 'processing',
            'source_filename' => 'Queue B.xlsx',
            'queued_at' => now()->subMinute()->toDateTimeString(),
            'started_at' => now()->subSeconds(30)->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => false,
        ]);
        $uploadBatchStatusService->put('queue-c', [
            'batch_id' => 'queue-c',
            'status' => 'ready',
            'source_filename' => 'Queue C.xlsx',
            'queued_at' => now()->subMinutes(4)->toDateTimeString(),
            'updated_at' => now()->subMinutes(3)->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => true,
            'total_items' => 255,
        ]);
        $uploadBatchStatusService->put('queue-d', [
            'batch_id' => 'queue-d',
            'status' => 'ready',
            'source_filename' => 'Queue D.xlsx',
            'queued_at' => now()->subMinutes(6)->toDateTimeString(),
            'updated_at' => now()->subMinutes(5)->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => false,
            'total_items' => 90,
        ]);
        $uploadBatchStatusService->put('queue-e', [
            'batch_id' => 'queue-e',
            'status' => 'queued',
            'source_filename' => 'Queue E.xlsx',
            'queued_at' => now()->subMinutes(7)->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => false,
        ]);
        PushCampaign::query()->create([
            'name' => 'Queue D Campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'queue-d',
        ]);
        $uploadBatchStatusService->put('queue-other', [
            'batch_id' => 'queue-other',
            'status' => 'queued',
            'source_filename' => 'Other User.xlsx',
            'queued_at' => now()->subMinutes(3)->toDateTimeString(),
            'initiated_by' => $otherUser->id,
            'dry_run' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/push-campaigns/upload/queue');
        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'per_page',
                'total',
                'from',
                'to',
                'health',
            ])
            ->assertJsonCount(5, 'items')
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('items.0.batch_id', 'queue-b')
            ->assertJsonPath('data.0.batch_id', 'queue-b')
            ->assertJsonPath('items.1.batch_id', 'queue-a')
            ->assertJsonPath('items.1.can_cancel', true)
            ->assertJsonPath('items.1.can_process_now', true)
            ->assertJsonPath('items.0.can_cancel', false);

        $response->assertJsonFragment([
            'batch_id' => 'queue-c',
            'can_create_from_dry_run' => true,
        ]);

        $response->assertJsonFragment([
            'batch_id' => 'queue-d',
            'can_confirm' => true,
        ]);

        $response->assertJsonFragment([
            'batch_id' => 'queue-e',
            'can_process_now' => true,
        ]);
    }

    public function test_upload_queue_endpoint_supports_page_and_per_page_slicing(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        $otherUser = $this->createUser('marketing', [$platform->id]);

        /** @var UploadBatchStatusService $uploadBatchStatusService */
        $uploadBatchStatusService = app(UploadBatchStatusService::class);

        for ($i = 1; $i <= 13; $i++) {
            $uploadBatchStatusService->put('page-batch-' . $i, [
                'batch_id' => 'page-batch-' . $i,
                'status' => 'queued',
                'source_filename' => 'Queue ' . $i . '.xlsx',
                'queued_at' => now()->subMinutes($i)->toDateTimeString(),
                'initiated_by' => $user->id,
                'dry_run' => true,
            ]);
        }

        $uploadBatchStatusService->put('page-batch-other-user', [
            'batch_id' => 'page-batch-other-user',
            'status' => 'queued',
            'source_filename' => 'Other User.xlsx',
            'queued_at' => now()->subSeconds(30)->toDateTimeString(),
            'initiated_by' => $otherUser->id,
            'dry_run' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/push-campaigns/upload/queue?page=2&per_page=5');
        $response->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('per_page', 5)
            ->assertJsonPath('total', 13)
            ->assertJsonPath('from', 6)
            ->assertJsonPath('to', 10)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.batch_id', 'page-batch-6')
            ->assertJsonPath('data.4.batch_id', 'page-batch-10');
    }

    public function test_paste_upload_endpoint_queues_large_non_dry_run_payloads(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        Queue::fake();
        config()->set('services.push_campaigns.inline_dry_run_max_rows', 0);
        Sanctum::actingAs($user);

        $lines = ["Date\tProfile URL\tMessage\tTime"];
        for ($i = 1; $i <= 25; $i++) {
            $lines[] = sprintf(
                "7th January\thttps://kenya.example/?p=%d\tHello Kenya %d\t10:%02d:00",
                1000 + $i,
                $i,
                $i % 60
            );
        }
        $content = implode("\n", $lines);

        $response = $this->postJson('/api/crm/push-campaigns/upload/paste', [
            'platform_id' => $platform->id,
            'content' => $content,
            'dry_run' => false,
            'year' => 2026,
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'batch_id',
                'status',
                'dry_run',
                'processed_inline',
                'status_payload',
            ])
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('processed_inline', false);

        $batchId = (string) $response->json('batch_id');
        $this->assertNotSame('', $batchId);

        Queue::assertPushed(ProcessPushUploadJob::class, function (ProcessPushUploadJob $job) use ($batchId): bool {
            return $job->batchId === $batchId
                && $job->dryRun === false
                && str_contains($job->sourceFilename, 'Kenya Push 2026.xlsx');
        });
    }

    public function test_paste_upload_endpoint_processes_small_non_dry_run_payloads_in_express_mode(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        config()->set('queue.default', 'database');
        Sanctum::actingAs($user);

        $content = implode("\n", [
            "Date\tProfile URL\tMessage\tTime",
            "7th January\thttps://kenya.example/?p=1001\tHello Kenya\t10:00:00",
            "\thttps://kenya.example/?p=1002\tHello Kenya 2\t12:00:00",
        ]);

        $response = $this->postJson('/api/crm/push-campaigns/upload/paste', [
            'platform_id' => $platform->id,
            'content' => $content,
            'dry_run' => false,
            'year' => 2026,
        ]);

        $response->assertOk()
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('processed_inline', true)
            ->assertJsonPath('status_payload.express_mode', true)
            ->assertJsonPath('status_payload.status', 'ready');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_paste_upload_endpoint_rejects_invalid_content_shape_with_422(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/push-campaigns/upload/paste', [
            'platform_id' => $platform->id,
            'content' => "Invalid row without tabs",
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Expected tab-separated columns',
            (string) $response->json('message')
        );
    }

    public function test_marketing_user_can_cancel_queued_upload_batch(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        config()->set('queue.default', 'database');
        config()->set('services.push_campaigns.inline_dry_run_max_rows', 0);

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('PUSH DOCUMENT 2026.xlsx', 32, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $uploadResponse = $this->postJson('/api/crm/push-campaigns/upload', [
            'file' => $file,
            'dry_run' => true,
        ])->assertStatus(202);

        $batchId = (string) $uploadResponse->json('batch_id');
        $this->assertNotSame('', $batchId);
        $this->assertGreaterThan(0, DB::table('jobs')->count());

        $cancelResponse = $this->deleteJson('/api/crm/push-campaigns/upload/' . $batchId);
        $cancelResponse->assertOk()
            ->assertJsonPath('status_payload.status', 'cancelled');

        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_marketing_user_can_process_queued_dry_run_now(): void
    {
        $platform = $this->createPlatform('Exotic Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        config()->set('queue.default', 'database');
        config()->set('services.push_campaigns.inline_dry_run_max_rows', 0);
        Cache::flush();

        Sanctum::actingAs($user);

        $filePath = storage_path('framework/testing/process-now-' . Str::uuid() . '.xlsx');
        @mkdir(dirname($filePath), 0777, true);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KENYA');
        $sheet->setCellValue('A1', 'DATE');
        $sheet->setCellValue('B1', 'PROFILE URL');
        $sheet->setCellValue('C1', '2026 MESSAGES');
        $sheet->setCellValue('D1', 'TIME');
        $sheet->setCellValue('A2', '7th January');
        $sheet->setCellValue('B2', 'https://kenya.example/escort/a/');
        $sheet->setCellValue('C2', 'Process now message');
        $sheet->setCellValue('D2', '10:00:00');
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        $upload = new UploadedFile(
            $filePath,
            'Kenya Push 2026.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $uploadResponse = $this->postJson('/api/crm/push-campaigns/upload', [
            'file' => $upload,
            'dry_run' => true,
        ])->assertStatus(202);

        $batchId = (string) $uploadResponse->json('batch_id');
        $this->assertGreaterThan(0, DB::table('jobs')->count());

        $processNowResponse = $this->postJson('/api/crm/push-campaigns/upload/' . $batchId . '/process-now');
        $processNowResponse->assertOk()
            ->assertJsonPath('status_payload.status', 'ready')
            ->assertJsonPath('status_payload.sheets_parsed', 1)
            ->assertJsonPath('status_payload.total_items', 1);

        $this->assertSame(0, DB::table('jobs')->count());

        @unlink($filePath);
    }

    public function test_marketing_user_can_create_campaigns_from_ready_dry_run_batch(): void
    {
        $platform = $this->createPlatform('Exotic Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        config()->set('queue.default', 'database');

        Sanctum::actingAs($user);

        $filePath = storage_path('framework/testing/create-from-dry-run-' . Str::uuid() . '.xlsx');
        @mkdir(dirname($filePath), 0777, true);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KENYA');
        $sheet->setCellValue('A1', 'DATE');
        $sheet->setCellValue('B1', 'PROFILE URL');
        $sheet->setCellValue('C1', '2026 MESSAGES');
        $sheet->setCellValue('D1', 'TIME');
        $sheet->setCellValue('A2', '7th January');
        $sheet->setCellValue('B2', 'https://kenya.example/escort/a/');
        $sheet->setCellValue('C2', 'Create from dry run');
        $sheet->setCellValue('D2', '10:00:00');
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        $batchId = 'dry-run-ready-batch';
        $storageRelative = 'push-uploads/' . $batchId . '.xlsx';
        @mkdir(dirname(storage_path('app/' . $storageRelative)), 0777, true);
        copy($filePath, storage_path('app/' . $storageRelative));

        app(UploadBatchStatusService::class)->put($batchId, [
            'batch_id' => $batchId,
            'status' => 'ready',
            'source_filename' => 'Kenya Push 2026.xlsx',
            'stored_path' => $storageRelative,
            'queued_at' => now()->subMinutes(5)->toDateTimeString(),
            'updated_at' => now()->subMinute()->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => true,
            'total_items' => 1,
        ]);

        Queue::fake();

        $response = $this->postJson('/api/crm/push-campaigns/upload/' . $batchId . '/create-from-dry-run');
        $response->assertStatus(202)
            ->assertJsonPath('status_payload.status', 'queued')
            ->assertJsonPath('status_payload.dry_run', false);

        Queue::assertPushed(ProcessPushUploadJob::class, function (ProcessPushUploadJob $job) use ($batchId): bool {
            return $job->batchId === $batchId
                && $job->dryRun === false;
        });

        @unlink($filePath);
        @unlink(storage_path('app/' . $storageRelative));
    }

    public function test_marketing_user_can_express_create_campaigns_from_small_paste_dry_run_batch(): void
    {
        $platform = $this->createPlatform('Exotic Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        config()->set('queue.default', 'database');

        Sanctum::actingAs($user);

        $filePath = storage_path('framework/testing/create-from-paste-dry-run-' . Str::uuid() . '.xlsx');
        @mkdir(dirname($filePath), 0777, true);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KENYA');
        $sheet->setCellValue('A1', 'DATE');
        $sheet->setCellValue('B1', 'PROFILE URL');
        $sheet->setCellValue('C1', '2026 MESSAGES');
        $sheet->setCellValue('D1', 'TIME');
        $sheet->setCellValue('A2', '7th January');
        $sheet->setCellValue('B2', 'https://kenya.example/escort/a/');
        $sheet->setCellValue('C2', 'Create from paste dry run');
        $sheet->setCellValue('D2', '10:00:00');
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        $batchId = 'paste-dry-run-ready-batch';
        $storageRelative = 'push-uploads/' . $batchId . '.xlsx';
        @mkdir(dirname(storage_path('app/' . $storageRelative)), 0777, true);
        copy($filePath, storage_path('app/' . $storageRelative));

        app(UploadBatchStatusService::class)->put($batchId, [
            'batch_id' => $batchId,
            'status' => 'ready',
            'source_filename' => 'Kenya Push 2026.xlsx',
            'stored_path' => $storageRelative,
            'queued_at' => now()->subMinutes(5)->toDateTimeString(),
            'updated_at' => now()->subMinute()->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => true,
            'paste_mode' => true,
            'total_items' => 1,
        ]);

        config()->set('queue.default', 'database');

        $response = $this->postJson('/api/crm/push-campaigns/upload/' . $batchId . '/create-from-dry-run');
        $response->assertOk()
            ->assertJsonPath('status_payload.status', 'ready')
            ->assertJsonPath('status_payload.express_mode', true);
        $this->assertSame(0, DB::table('jobs')->count());

        @unlink($filePath);
        @unlink(storage_path('app/' . $storageRelative));
    }

    public function test_marketing_user_can_confirm_ready_batch_from_upload_queue(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $batchId = 'confirm-queue-batch';
        app(UploadBatchStatusService::class)->put($batchId, [
            'batch_id' => $batchId,
            'status' => 'ready',
            'source_filename' => 'Kenya Push 2026.xlsx',
            'queued_at' => now()->subMinutes(2)->toDateTimeString(),
            'updated_at' => now()->subMinute()->toDateTimeString(),
            'initiated_by' => $user->id,
            'dry_run' => false,
            'total_items' => 1,
        ]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Queue Confirm Campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => $batchId,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/push-campaigns/upload/' . $batchId . '/confirm');
        $response->assertOk()
            ->assertJsonPath('confirmed_count', 1)
            ->assertJsonPath('campaigns.0.id', $campaign->id);

        $this->assertNotNull($campaign->fresh()->confirmed_at);
    }

    public function test_dashboard_route_is_not_captured_by_wildcard_model_binding(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/push-campaigns/dashboard');

        $response->assertOk();
        $response->assertJsonStructure(['total_campaigns', 'pending_campaigns', 'sent_today', 'avg_click_rate']);
    }

    public function test_parser_handles_fill_down_dates_and_timezone_conversion(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya', 'Africa/Nairobi');
        $service = app(ProfileExtractionService::class);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KENYA');
        $sheet->setCellValue('A1', 'DATE');
        $sheet->setCellValue('B1', 'PROFILE URL');
        $sheet->setCellValue('C1', '2026 MESSAGES');
        $sheet->setCellValue('D1', 'TIME');

        $sheet->setCellValue('A2', '7th January');
        $sheet->setCellValue('B2', 'https://kenya.example/escort/a/');
        $sheet->setCellValue('C2', 'Message one');
        $sheet->setCellValue('D2', '10:00:00');

        $sheet->setCellValue('A3', '');
        $sheet->setCellValue('B3', 'https://kenya.example/escort/b/');
        $sheet->setCellValue('C3', 'Message two');
        $sheet->setCellValue('D3', '12:00:00');

        $rows = $service->parseSheet($sheet, 'KENYA', 2026);

        $this->assertCount(2, $rows);
        $this->assertSame($platform->id, $rows[0]['platform_id']);
        $this->assertSame('2026-01-07 07:00:00', Carbon::parse($rows[0]['scheduled_at'], 'UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-07 09:00:00', Carbon::parse($rows[1]['scheduled_at'], 'UTC')->format('Y-m-d H:i:s'));
    }

    public function test_parser_normalizes_legacy_market_timezone_alias_during_import_parsing(): void
    {
        $this->createPlatform("Côte d'Ivoire", 'ivoire.example', "Côte d'Ivoire", 'Africa/Yamoussoukro');
        $service = app(ProfileExtractionService::class);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Côte d'Ivoire");
        $sheet->setCellValue('A1', 'DATE');
        $sheet->setCellValue('B1', 'PROFILE URL');
        $sheet->setCellValue('C1', '2026 MESSAGES');
        $sheet->setCellValue('D1', 'TIME');
        $sheet->setCellValue('A2', '10th April');
        $sheet->setCellValue('B2', 'https://www.exoticivoire.com/escorte/ami/');
        $sheet->setCellValue('C2', 'Message one');
        $sheet->setCellValue('D2', '12:00:00');

        $rows = $service->parseSheet($sheet, "Côte d'Ivoire", 2026);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-04-10 12:00:00', Carbon::parse($rows[0]['scheduled_at'], 'UTC')->format('Y-m-d H:i:s'));
    }

    public function test_sheet_alias_mapping_resolves_ivoire_to_ivory_coast(): void
    {
        $platform = $this->createPlatform('Ivory Coast', 'ivoire.example', 'Ivory Coast');
        $service = app(ProfileExtractionService::class);

        $resolved = $service->resolveSheetToPlatform('IVOIRE');

        $this->assertNotNull($resolved);
        $this->assertSame($platform->id, $resolved->id);
    }

    public function test_single_sheet_upload_can_resolve_platform_from_filename(): void
    {
        $platform = $this->createPlatform('Exotic Kenya', 'kenya.example', 'Kenya');
        $service = app(ProfileExtractionService::class);

        $resolved = $service->resolvePlatformForSheet('Sheet1', 'Kenya Push 2026.xlsx', true);
        $this->assertNotNull($resolved);
        $this->assertSame($platform->id, $resolved->id);

        $notResolved = $service->resolvePlatformForSheet('Sheet1', 'Kenya Push 2026.xlsx', false);
        $this->assertNull($notResolved);
    }

    public function test_execute_campaign_only_queues_items_within_next_24_hours(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('admin', [$platform->id]);
        $campaign = PushCampaign::query()->create([
            'name' => 'Timed campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-timed',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/a',
            'custom_message' => 'A',
            'scheduled_at' => now()->addHours(2),
            'status' => 'pending',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/b',
            'custom_message' => 'B',
            'scheduled_at' => now()->addHours(30),
            'status' => 'pending',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/c',
            'custom_message' => 'C',
            'scheduled_at' => null,
            'status' => 'pending',
        ]);

        Queue::fake();

        app(PushCampaignService::class)->executeCampaign($campaign, $user->id);

        Queue::assertPushed(SendPushNotificationJob::class, 2);

        $statuses = PushCampaignItem::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->pluck('status')
            ->all();

        $this->assertSame(['scheduled', 'pending', 'scheduled'], $statuses);
    }

    public function test_dispatch_readiness_endpoint_returns_timing_counts_for_pending_items(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-04 09:00:00', 'Africa/Nairobi')->utc());

        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Readiness snapshot',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-readiness',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/overdue',
            'custom_message' => 'Overdue',
            'scheduled_at' => Carbon::parse('2026-03-04 08:30:00', 'Africa/Nairobi')->utc(),
            'status' => 'pending',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/immediate',
            'custom_message' => 'Immediate',
            'scheduled_at' => Carbon::parse('2026-03-04 09:00:00', 'Africa/Nairobi')->utc(),
            'status' => 'pending',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/delayed',
            'custom_message' => 'Delayed',
            'scheduled_at' => Carbon::parse('2026-03-04 12:00:00', 'Africa/Nairobi')->utc(),
            'status' => 'pending',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/outside',
            'custom_message' => 'Outside',
            'scheduled_at' => Carbon::parse('2026-03-06 10:00:00', 'Africa/Nairobi')->utc(),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/push-campaigns/{$campaign->id}/dispatch-readiness");
        $response->assertOk()
            ->assertJsonPath('counts.total_pending', 4)
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonPath('counts.send_immediately', 1)
            ->assertJsonPath('counts.queue_with_delay', 1)
            ->assertJsonPath('counts.outside_dispatch_window', 1)
            ->assertJsonPath('can_activate', false);

        Carbon::setTestNow();
    }

    public function test_campaign_detail_includes_item_timing_state_fields(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-04 16:00:00', 'Africa/Nairobi')->utc());

        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Timing fields campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-timing-fields',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/overdue',
            'custom_message' => 'Overdue',
            'scheduled_at' => Carbon::parse('2026-03-04 08:00:00', 'Africa/Nairobi')->utc(),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/push-campaigns/{$campaign->id}");
        $response->assertOk()
            ->assertJsonPath('items.data.0.timing_state', 'overdue')
            ->assertJsonPath('items.data.0.is_overdue', true)
            ->assertJsonPath('items.data.0.timing_reference_timezone', 'Africa/Nairobi');

        Carbon::setTestNow();
    }

    public function test_execute_endpoint_is_blocked_when_pending_items_are_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-04 16:00:00', 'Africa/Nairobi')->utc());

        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        $campaign = PushCampaign::query()->create([
            'name' => 'Blocked execute',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-blocked-execute',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/overdue',
            'custom_message' => 'Too late',
            'scheduled_at' => Carbon::parse('2026-03-04 02:00:00', 'Africa/Nairobi')->utc(),
            'status' => 'pending',
        ]);

        Queue::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/push-campaigns/{$campaign->id}/execute");
        $response->assertStatus(422)
            ->assertJsonPath('can_activate', false)
            ->assertJsonPath('counts.overdue', 1);

        Queue::assertNotPushed(SendPushNotificationJob::class);
        Carbon::setTestNow();
    }

    public function test_execute_endpoint_returns_dispatch_plan_when_activation_is_allowed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-04 09:00:00', 'Africa/Nairobi')->utc());

        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        $campaign = PushCampaign::query()->create([
            'name' => 'Allowed execute',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-allowed-execute',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/immediate',
            'custom_message' => 'Immediate',
            'scheduled_at' => null,
            'status' => 'pending',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/future',
            'custom_message' => 'Future',
            'scheduled_at' => Carbon::parse('2026-03-04 12:00:00', 'Africa/Nairobi')->utc(),
            'status' => 'pending',
        ]);

        Queue::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/push-campaigns/{$campaign->id}/execute");
        $response->assertOk()
            ->assertJsonPath('dispatch_plan.can_activate', true)
            ->assertJsonPath('dispatch_plan.counts.send_immediately', 1)
            ->assertJsonPath('dispatch_plan.counts.queue_with_delay', 1);

        Queue::assertPushed(SendPushNotificationJob::class, 2);
        Carbon::setTestNow();
    }

    public function test_schedule_endpoint_blocks_activation_that_would_make_items_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-04 09:00:00', 'Africa/Nairobi')->utc());

        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        $campaign = PushCampaign::query()->create([
            'name' => 'Blocked schedule',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-blocked-schedule',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/noon',
            'custom_message' => 'Noon',
            'scheduled_at' => Carbon::parse('2026-03-04 14:00:00', 'Africa/Nairobi')->utc(),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/push-campaigns/{$campaign->id}/schedule", [
            'scheduled_at' => '2026-03-04 16:00:00',
            'timezone' => 'Africa/Nairobi',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('can_activate', false)
            ->assertJsonPath('counts.overdue', 1);

        Carbon::setTestNow();
    }

    public function test_schedule_endpoint_uses_campaign_market_timezone_and_keeps_outside_window_items_valid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-03 08:00:00', 'UTC')->utc());

        $platform = $this->createPlatform("Côte d'Ivoire", 'ivoire.example', "Côte d'Ivoire", 'Africa/Abidjan');
        $user = $this->createUser('marketing', [$platform->id]);
        $campaign = PushCampaign::query()->create([
            'name' => 'Ivoire schedule',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-ivoire-schedule',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://www.exoticivoire.com/escorte/ami/',
            'custom_message' => 'Bonsoir',
            'scheduled_at' => Carbon::parse('2026-03-06 10:00:00', 'Africa/Abidjan')->utc(),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/push-campaigns/{$campaign->id}/schedule", [
            'scheduled_at' => '2026-03-04 12:00:00',
            'timezone' => 'Africa/Nairobi',
        ]);

        $response->assertOk()
            ->assertJsonPath('dispatch_plan.can_activate', true)
            ->assertJsonPath('dispatch_plan.counts.outside_dispatch_window', 1)
            ->assertJsonPath('dispatch_plan.activation_timezone', 'Africa/Abidjan');

        $this->assertSame('2026-03-04 12:00:00', $campaign->fresh()->scheduled_at?->copy()->utc()->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_marketing_user_can_cancel_scheduled_campaign_and_delete_it_afterwards(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Scheduled campaign',
            'platform_id' => $platform->id,
            'status' => 'scheduled',
            'created_by' => $user->id,
            'scheduled_at' => now()->addHour()->utc(),
        ]);
        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/?p=101',
            'custom_message' => 'Pending send',
            'scheduled_at' => now()->addHours(2)->utc(),
            'status' => 'pending',
        ]);
        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/?p=102',
            'custom_message' => 'Queued send',
            'scheduled_at' => now()->addHours(3)->utc(),
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/push-campaigns/{$campaign->id}/cancel")
            ->assertOk()
            ->assertJsonPath('campaign.status', 'cancelled')
            ->assertJsonPath('summary.skipped_count', 2);

        $this->assertDatabaseHas('push_campaign_items', [
            'campaign_id' => $campaign->id,
            'status' => 'skipped',
            'error_message' => 'campaign_cancelled: Cancelled by CRM operator before send.',
        ]);

        $this->deleteJson("/api/crm/push-campaigns/{$campaign->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Campaign deleted.');
    }

    public function test_marketing_user_can_cancel_running_campaign_without_touching_sent_items(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Running campaign',
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_by' => $user->id,
            'sent_count' => 1,
        ]);
        $sentItem = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/?p=201',
            'custom_message' => 'Already sent',
            'status' => 'sent',
            'sent_at' => now()->subMinute(),
        ]);
        $scheduledItem = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/?p=202',
            'custom_message' => 'Still queued',
            'scheduled_at' => now()->addMinutes(30)->utc(),
            'status' => 'scheduled',
        ]);
        $pendingItem = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/?p=203',
            'custom_message' => 'Still pending',
            'scheduled_at' => now()->addHour()->utc(),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/push-campaigns/{$campaign->id}/cancel")
            ->assertOk()
            ->assertJsonPath('campaign.status', 'cancelled')
            ->assertJsonPath('summary.sent_count', 1)
            ->assertJsonPath('summary.skipped_count', 2);

        $this->assertDatabaseHas('push_campaign_items', [
            'id' => $sentItem->id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('push_campaign_items', [
            'id' => $scheduledItem->id,
            'status' => 'skipped',
        ]);
        $this->assertDatabaseHas('push_campaign_items', [
            'id' => $pendingItem->id,
            'status' => 'skipped',
        ]);
    }

    public function test_send_push_job_marks_item_failed_when_send_window_is_missed(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        $campaign = PushCampaign::query()->create([
            'name' => 'Missed window campaign',
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-missed-window',
        ]);

        $scheduledAt = now()->subMinutes(20);
        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/item',
            'custom_message' => 'Missed',
            'scheduled_at' => $scheduledAt,
            'status' => 'scheduled',
        ]);

        $this->mock(\App\Services\PushNotification\PushProviderService::class, function ($mock): void {
            $mock->shouldNotReceive('sendPush');
        });

        $job = new SendPushNotificationJob((int) $item->id);
        $job->handle(
            app(\App\Services\PushNotification\PushProviderService::class),
            app(\App\Services\AuditService::class)
        );

        $fresh = $item->fresh();
        $this->assertSame('failed', (string) $fresh->status);
        $this->assertStringStartsWith('missed_window:', (string) $fresh->error_message);
        $this->assertNull($fresh->provider_notification_id);
        $this->assertNull($fresh->sent_at);
    }

    public function test_dispatch_command_promotes_running_campaign_pending_items(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $admin = $this->createUser('admin', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Running campaign',
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_by' => $admin->id,
            'upload_batch_id' => 'batch-running',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/a',
            'custom_message' => 'A',
            'scheduled_at' => now()->addHours(3),
            'status' => 'pending',
        ]);

        Queue::fake();

        $this->artisan('crm:dispatch-scheduled-pushes')->assertExitCode(0);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
        $this->assertSame('scheduled', $item->fresh()->status);
    }

    public function test_dispatch_command_skips_blocked_scheduled_campaign_and_continues_running_campaigns(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-04 16:00:00', 'Africa/Nairobi')->utc());

        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $admin = $this->createUser('admin', [$platform->id]);

        $blockedCampaign = PushCampaign::query()->create([
            'name' => 'Blocked scheduled campaign',
            'platform_id' => $platform->id,
            'status' => 'scheduled',
            'created_by' => $admin->id,
            'upload_batch_id' => 'batch-blocked-command',
            'scheduled_at' => now()->subMinute(),
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $blockedCampaign->id,
            'profile_url' => 'https://kenya.example/old-item',
            'custom_message' => 'Old item',
            'scheduled_at' => Carbon::parse('2026-03-04 02:00:00', 'Africa/Nairobi')->utc(),
            'status' => 'pending',
        ]);

        $runningCampaign = PushCampaign::query()->create([
            'name' => 'Running campaign',
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_by' => $admin->id,
            'upload_batch_id' => 'batch-running-command',
        ]);

        $runningItem = PushCampaignItem::query()->create([
            'campaign_id' => $runningCampaign->id,
            'profile_url' => 'https://kenya.example/future-item',
            'custom_message' => 'Future item',
            'scheduled_at' => now()->addHours(2),
            'status' => 'pending',
        ]);

        Queue::fake();

        $this->artisan('crm:dispatch-scheduled-pushes')->assertExitCode(0);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
        $this->assertSame('scheduled', (string) $runningItem->fresh()->status);
        $this->assertSame('scheduled', (string) $blockedCampaign->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_subscribers_endpoint_is_platform_scoped(): void
    {
        $platformA = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platformB = $this->createPlatform('Uganda', 'uganda.example', 'Uganda');
        $user = $this->createUser('marketing', [$platformA->id]);

        PushSubscriberSnapshot::query()->create([
            'platform_id' => $platformA->id,
            'provider' => 'webpushr',
            'total_subscribers' => 100,
            'active_subscribers' => 80,
            'snapshot_date' => now()->toDateString(),
            'raw_response' => ['total' => 100, 'active' => 80],
        ]);

        PushSubscriberSnapshot::query()->create([
            'platform_id' => $platformB->id,
            'provider' => 'webpushr',
            'total_subscribers' => 120,
            'active_subscribers' => 90,
            'snapshot_date' => now()->toDateString(),
            'raw_response' => ['total' => 120, 'active' => 90],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/push-campaigns/subscribers');

        $response->assertOk();
        $items = $response->json('items');

        $this->assertCount(1, $items);
        $this->assertSame($platformA->id, $items[0]['platform_id']);
    }

    public function test_marketing_user_can_list_crm_escort_profiles_for_selected_platform(): void
    {
        $platformA = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platformB = $this->createPlatform('Uganda', 'uganda.example', 'Uganda');
        $user = $this->createUser('marketing', [$platformA->id]);

        Client::query()->create([
            'platform_id' => $platformA->id,
            'wp_post_id' => 2001,
            'name' => 'Escort Kenya',
            'phone_normalized' => '254700100001',
            'client_type' => 'escort',
            'wp_profile_url' => 'https://kenya.example/escort/escort-kenya/',
        ]);

        Client::query()->create([
            'platform_id' => $platformA->id,
            'wp_post_id' => 2002,
            'name' => 'Agency Kenya',
            'phone_normalized' => '254700100002',
            'client_type' => 'agency',
        ]);

        Client::query()->create([
            'platform_id' => $platformB->id,
            'wp_post_id' => 2003,
            'name' => 'Escort Uganda',
            'phone_normalized' => '256700100003',
            'client_type' => 'escort',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/push-campaigns/crm-profiles?platform_id=' . $platformA->id);

        $response->assertOk();
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame('Escort Kenya', $items[0]['name']);
    }

    public function test_crm_profiles_search_matches_email_and_formatted_phone(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 2101,
            'name' => 'Nia Searchable',
            'phone_normalized' => '254700111222',
            'email' => 'nia.search@example.com',
            'client_type' => 'escort',
        ]);

        Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 2102,
            'name' => 'Other Escort',
            'phone_normalized' => '254733555000',
            'email' => 'other@example.com',
            'client_type' => 'escort',
        ]);

        Sanctum::actingAs($user);

        $emailQuery = http_build_query([
            'platform_id' => $platform->id,
            'search' => 'nia.search@example.com',
        ]);
        $emailResponse = $this->getJson('/api/crm/push-campaigns/crm-profiles?' . $emailQuery);
        $emailResponse->assertOk();
        $this->assertCount(1, $emailResponse->json('data'));
        $this->assertSame('Nia Searchable', $emailResponse->json('data.0.name'));

        $phoneQuery = http_build_query([
            'platform_id' => $platform->id,
            'search' => '+254 700-111-222',
        ]);
        $phoneResponse = $this->getJson('/api/crm/push-campaigns/crm-profiles?' . $phoneQuery);
        $phoneResponse->assertOk();
        $this->assertCount(1, $phoneResponse->json('data'));
        $this->assertSame('Nia Searchable', $phoneResponse->json('data.0.name'));
    }

    public function test_marketing_user_can_create_campaign_from_selected_crm_escorts(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya', 'Africa/Nairobi');
        $user = $this->createUser('marketing', [$platform->id]);

        $escortA = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 3001,
            'name' => 'Escort A',
            'phone_normalized' => '254700200001',
            'client_type' => 'escort',
            'main_image_url' => 'https://kenya.example/images/a.jpg',
        ]);

        $escortB = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 3002,
            'name' => 'Escort B',
            'phone_normalized' => '254700200002',
            'client_type' => 'escort',
            'main_image_url' => 'https://kenya.example/images/b.jpg',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/push-campaigns/from-crm', [
            'platform_id' => $platform->id,
            'client_ids' => [$escortA->id, $escortB->id],
            'message' => 'Tonight only. Message now.',
            'campaign_name' => 'Kenya CRM Select',
            'scheduled_at' => '2026-01-07 10:00:00',
            'timezone' => 'Africa/Nairobi',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('created_items', 2);
        $campaignId = (int) $response->json('campaign.id');
        $this->assertGreaterThan(0, $campaignId);

        $campaign = PushCampaign::query()->findOrFail($campaignId);
        $this->assertSame('draft', $campaign->status);
        $this->assertSame(2, (int) $campaign->total_items);

        $items = PushCampaignItem::query()
            ->where('campaign_id', $campaignId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(
            ['https://kenya.example/?p=3001', 'https://kenya.example/?p=3002'],
            $items->pluck('profile_url')->all()
        );
        $this->assertSame('2026-01-07 07:00:00', optional($items->first()->scheduled_at)->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function test_marketing_user_can_create_campaign_from_selected_crm_escorts_using_market_timezone(): void
    {
        $platform = $this->createPlatform("Côte d'Ivoire", 'ivoire.example', "Côte d'Ivoire", 'Africa/Abidjan');
        $user = $this->createUser('marketing', [$platform->id]);

        $escort = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 10136,
            'name' => 'Ami',
            'phone_normalized' => '22570123456',
            'client_type' => 'escort',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/push-campaigns/from-crm', [
            'platform_id' => $platform->id,
            'client_ids' => [$escort->id],
            'message' => 'Bonsoir.',
            'campaign_name' => 'Ivoire CRM Select',
            'scheduled_at' => '2026-04-10 12:00:00',
            'timezone' => 'Africa/Nairobi',
        ]);

        $response->assertStatus(201)->assertJsonPath('created_items', 1);

        $campaignId = (int) $response->json('campaign.id');
        $item = PushCampaignItem::query()->where('campaign_id', $campaignId)->firstOrFail();

        $this->assertSame('2026-04-10 12:00:00', $item->scheduled_at?->copy()->utc()->format('Y-m-d H:i:s'));
    }

    public function test_marketing_user_can_update_campaign_item_message(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Editable campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-edit-item',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/a',
            'custom_message' => 'Old message',
            'scheduled_at' => now()->addHour(),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}", [
            'custom_message' => 'Updated unique message',
        ]);

        $response->assertOk();
        $response->assertJsonPath('item.custom_message', 'Updated unique message');
        $this->assertSame('Updated unique message', $item->fresh()->custom_message);
    }

    public function test_marketing_user_can_update_campaign_item_core_profile_fields(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Editable core fields',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-edit-item-core',
            'total_items' => 1,
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/legacy-slug',
            'custom_message' => 'Old message',
            'status' => 'failed',
            'error_message' => 'no_post_id: Could not resolve profile',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}", [
            'profile_url' => 'https://kenya.example/?p=991',
            'profile_name' => 'Updated Name',
            'profile_phone' => '254700001111',
            'profile_image_url' => 'https://kenya.example/images/updated.jpg',
            'profile_age' => '24',
            'custom_message' => 'Updated message body',
            'scheduled_at' => '2026-03-05 10:00:00',
            'timezone' => 'Africa/Nairobi',
        ]);

        $response->assertOk()
            ->assertJsonPath('item.profile_name', 'Updated Name')
            ->assertJsonPath('item.profile_phone', '254700001111')
            ->assertJsonPath('item.profile_image_url', 'https://kenya.example/images/updated.jpg')
            ->assertJsonPath('item.profile_age', '24')
            ->assertJsonPath('item.custom_message', 'Updated message body')
            ->assertJsonPath('item.status', 'pending')
            ->assertJsonPath('item.wp_post_id', 991);

        $fresh = $item->fresh();
        $this->assertSame('pending', (string) $fresh->status);
        $this->assertNull($fresh->error_message);
    }

    public function test_marketing_user_can_update_campaign_item_schedule_using_campaign_market_timezone(): void
    {
        $platform = $this->createPlatform("Côte d'Ivoire", 'ivoire.example', "Côte d'Ivoire", 'Africa/Abidjan');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Editable timezone item',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-edit-item-timezone',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://www.exoticivoire.com/escorte/ami/',
            'custom_message' => 'Bonsoir',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}", [
            'custom_message' => 'Updated message body',
            'scheduled_at' => '2026-04-10 12:00:00',
            'timezone' => 'Africa/Nairobi',
        ]);

        $response->assertOk()
            ->assertJsonPath('item.custom_message', 'Updated message body');

        $this->assertSame('2026-04-10 12:00:00', $item->fresh()->scheduled_at?->copy()->utc()->format('Y-m-d H:i:s'));
    }

    public function test_extraction_resolves_wp_post_id_from_link_header_shortlink(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.kenya.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        $campaign = PushCampaign::query()->create([
            'name' => 'Link header extraction',
            'platform_id' => $platform->id,
            'status' => 'processing',
            'upload_batch_id' => 'batch-link-header',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 624,
            'name' => 'Samira 8',
            'phone_normalized' => '254700100624',
            'main_image_url' => 'https://kenya.example/images/samira.jpg',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/samira-8/',
            'custom_message' => 'Hello',
            'status' => 'pending_extraction',
        ]);

        Http::fake([
            'https://kenya.example/escort/samira-8/' => Http::response(
                '<html><head></head><body>ok</body></html>',
                200,
                [
                    'content-type' => 'text/html; charset=utf-8',
                    'link' => '<https://kenya.example/?p=624>; rel="shortlink"',
                ]
            ),
            'https://wp.kenya.test/*' => Http::response([], 404),
        ]);

        app(ProfileExtractionService::class)->extractProfileBatch(collect([$item]), $platform);

        $fresh = $item->fresh();
        $this->assertSame((int) $client->id, (int) $fresh->client_id);
        $this->assertSame(624, (int) $fresh->wp_post_id);
        $this->assertSame('pending', (string) $fresh->status);
        $this->assertNull($fresh->error_message);
    }

    public function test_extraction_resolves_wp_post_id_from_embedded_html_markers(): void
    {
        $platform = $this->createPlatform("Côte d'Ivoire", 'ivoire.example', "Côte d'Ivoire", 'Africa/Abidjan');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.ivoire.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        $campaign = PushCampaign::query()->create([
            'name' => 'Marker extraction',
            'platform_id' => $platform->id,
            'status' => 'processing',
            'upload_batch_id' => 'batch-marker-extraction',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 10136,
            'name' => 'Ami',
            'phone_normalized' => '2257010136',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://www.exoticivoire.com/escorte/ami/',
            'custom_message' => 'Bonsoir',
            'status' => 'pending_extraction',
        ]);

        Http::fake([
            'https://www.exoticivoire.com/escorte/ami/' => Http::response(
                <<<'HTML'
<html>
    <head><title>Ami</title></head>
    <body class="single single-escorte postid-10136">
        <input type="hidden" name="profile_id" value="10136" />
        <script>
            var CURRENT_ID = 10136;
            var pid = 10136;
            window.__page = {"cachePurgePostId":10136};
        </script>
    </body>
</html>
HTML,
                200,
                ['content-type' => 'text/html; charset=utf-8']
            ),
            'https://wp.ivoire.test/*' => Http::response([], 404),
        ]);

        app(ProfileExtractionService::class)->extractProfileBatch(collect([$item]), $platform);

        $fresh = $item->fresh();
        $this->assertSame((int) $client->id, (int) $fresh->client_id);
        $this->assertSame(10136, (int) $fresh->wp_post_id);
        $this->assertSame('pending', (string) $fresh->status);
        $this->assertNull($fresh->error_message);
    }

    public function test_auto_match_supports_escorte_profile_urls(): void
    {
        $platform = $this->createPlatform("Côte d'Ivoire", 'ivoire.example', "Côte d'Ivoire", 'Africa/Abidjan');

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 6085,
            'name' => 'Vera',
            'phone_normalized' => '22570006085',
        ]);

        $match = app(PushCampaignItemMatchService::class)->resolveAutoMatch(
            (int) $platform->id,
            'https://www.exoticivoire.com/escorte/vera/'
        );

        $this->assertSame('matched', (string) ($match['reason'] ?? ''));
        $this->assertSame((int) $client->id, (int) data_get($match, 'candidate.id'));
        $this->assertSame(6085, (int) data_get($match, 'candidate.wp_post_id'));
    }

    public function test_extraction_prefers_taxonomy_city_name_over_numeric_city_code(): void
    {
        $platform = $this->createPlatform('DRC', 'exoticdrc.com', 'Congo', 'Africa/Lubumbashi');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.exoticdrc.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
            'phone_prefix' => '243',
            'currency_code' => 'CDF',
        ])->save();

        $campaign = PushCampaign::query()->create([
            'name' => 'City label extraction',
            'platform_id' => $platform->id,
            'status' => 'processing',
            'upload_batch_id' => 'batch-city-label',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://www.exoticdrc.com/escorte/lala/',
            'custom_message' => 'Bonjour',
            'status' => 'pending_extraction',
        ]);

        Http::fake([
            'https://www.exoticdrc.com/escorte/lala/' => Http::response(
                <<<'HTML'
<html>
    <head><title>Lala</title></head>
    <body class="single single-escorte postid-2055">
        <input type="hidden" name="profile_id" value="2055" />
    </body>
</html>
HTML,
                200,
                ['content-type' => 'text/html; charset=utf-8']
            ),
            'https://wp.exoticdrc.test/wp-json/exotic-crm/v1/clients/2055' => Http::response([
                'data' => [
                    'name' => 'Lala',
                    'phone' => '243983804852',
                    'city' => '84',
                    'taxonomies' => [
                        'city' => [
                            'name' => 'Lubumbashi',
                        ],
                    ],
                ],
            ], 200),
            'https://wp.exoticdrc.test/wp-json/exotic-crm/v1/clients/2055/media' => Http::response([], 404),
        ]);

        app(ProfileExtractionService::class)->extractProfileBatch(collect([$item]), $platform);

        $fresh = $item->fresh();
        $this->assertSame(2055, (int) $fresh->wp_post_id);
        $this->assertSame('pending', (string) $fresh->status);
        $this->assertSame('Lubumbashi', (string) $fresh->profile_city);
    }

    public function test_extraction_classifies_http_404_as_actionable_failure(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.kenya.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        $campaign = PushCampaign::query()->create([
            'name' => '404 extraction',
            'platform_id' => $platform->id,
            'status' => 'processing',
            'upload_batch_id' => 'batch-404',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/missing-profile/',
            'custom_message' => 'Hello',
            'status' => 'pending_extraction',
        ]);

        Http::fake([
            'https://kenya.example/escort/missing-profile*' => Http::response('Not found', 404, ['content-type' => 'text/html']),
            'https://wp.kenya.test/*' => Http::response([], 404),
        ]);

        app(ProfileExtractionService::class)->extractProfileBatch(collect([$item]), $platform);

        $fresh = $item->fresh();
        $this->assertSame('failed', (string) $fresh->status);
        $this->assertStringStartsWith('http_404:', (string) $fresh->error_message);
    }

    public function test_extraction_marks_ambiguous_auto_match_for_manual_resolution(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.kenya.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        config()->set('services.push_campaigns.auto_match_min_margin', 999);

        $campaign = PushCampaign::query()->create([
            'name' => 'Ambiguous extraction',
            'platform_id' => $platform->id,
            'status' => 'processing',
            'upload_batch_id' => 'batch-ambiguous',
        ]);

        Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 8001,
            'name' => 'Samira 8',
            'phone_normalized' => '254700000801',
        ]);

        Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 8002,
            'name' => 'Samira',
            'phone_normalized' => '254700000802',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/samira-8/',
            'custom_message' => 'Hello',
            'status' => 'pending_extraction',
        ]);

        Http::fake([
            'https://kenya.example/escort/samira-8/' => Http::response('<html><head><title>Profile</title></head></html>', 200, ['content-type' => 'text/html']),
            'https://wp.kenya.test/*' => Http::response([], 404),
        ]);

        app(ProfileExtractionService::class)->extractProfileBatch(collect([$item]), $platform);

        $fresh = $item->fresh();
        $this->assertSame('failed', (string) $fresh->status);
        $this->assertStringStartsWith('ambiguous_match:', (string) $fresh->error_message);
    }

    public function test_extraction_recovers_redirected_homepage_url_when_a_clear_crm_match_exists(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.kenya.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        $campaign = PushCampaign::query()->create([
            'name' => 'Redirect home recovery',
            'platform_id' => $platform->id,
            'status' => 'processing',
            'upload_batch_id' => 'batch-redirect-home',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 2293,
            'name' => 'Miracle Massage',
            'phone_normalized' => '254700002293',
            'profile_status' => 'publish',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/mira/',
            'custom_message' => 'Hello',
            'status' => 'pending_extraction',
        ]);

        Http::fake([
            'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/2293' => Http::response([
                'client' => [
                    'name' => 'Miracle Massage',
                    'phone' => '254711002293',
                    'main_image_url' => 'https://cdn.kenya.test/miracle.jpg',
                    'meta' => [
                        'age' => '25',
                    ],
                ],
            ], 200),
            'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/2293/media' => Http::response([], 200),
        ]);

        $extractor = new class(app(PushCampaignItemMatchService::class)) extends ProfileExtractionService {
            public function __construct(PushCampaignItemMatchService $matchService)
            {
                parent::__construct($matchService);
            }

            protected function fetchHtml(string $url): array
            {
                if ($url === 'https://kenya.example/escort/mira/') {
                    return [
                        'status' => 200,
                        'content_type' => 'text/html',
                        'html' => '<html><body class="home page postid-2038"></body></html>',
                        'requested_url' => $url,
                        'effective_url' => 'https://kenya.example',
                        'redirected' => true,
                        'link_header' => '',
                    ];
                }

                return parent::fetchHtml($url);
            }
        };

        $extractor->extractProfileBatch(collect([$item]), $platform);

        $fresh = $item->fresh();
        $this->assertSame('pending', (string) $fresh->status);
        $this->assertSame($client->id, (int) $fresh->client_id);
        $this->assertSame(2293, (int) $fresh->wp_post_id);
        $this->assertSame('https://kenya.example/?p=2293', (string) $fresh->profile_url);
        $this->assertSame('254711002293', (string) $fresh->profile_phone);
        $this->assertSame('https://cdn.kenya.test/miracle.jpg', (string) $fresh->profile_image_url);
        $this->assertSame('25', (string) $fresh->profile_age);
        $this->assertNull($fresh->error_message);
    }

    public function test_match_candidates_endpoint_returns_paginated_candidates(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Match candidates',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-candidates',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/luna/',
            'custom_message' => 'Hello',
            'status' => 'failed',
        ]);

        foreach (range(1, 12) as $index) {
            Client::query()->create([
                'platform_id' => $platform->id,
                'client_type' => 'escort',
                'wp_post_id' => 9100 + $index,
                'name' => 'Luna Candidate ' . $index,
                'phone_normalized' => '25470030' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'profile_status' => 'publish',
            ]);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/match-candidates?per_page=10&page=1");
        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'per_page',
                'total',
                'from',
                'to',
            ])
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('total', 12)
            ->assertJsonCount(10, 'data');
    }

    public function test_match_crm_endpoint_binds_item_and_hydrates_wp_fields(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.kenya.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Match CRM',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-match-crm',
            'total_items' => 1,
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/luna-old/',
            'custom_message' => 'Hello',
            'status' => 'failed',
            'error_message' => 'redirect_home: URL redirected',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 777,
            'name' => 'Luna',
            'phone_normalized' => '254700999777',
            'main_image_url' => 'https://kenya.example/images/luna.jpg',
        ]);

        Http::fake([
            'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/777' => Http::response([
                'client' => [
                    'name' => 'Luna',
                    'phone' => '254711000111',
                    'main_image_url' => 'https://cdn.kenya.test/luna-fresh.jpg',
                    'meta' => [
                        'age' => '25',
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/match-crm", [
            'client_id' => $client->id,
            'replace_profile_url' => true,
            'hydrate_wp_details' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('item.client_id', $client->id)
            ->assertJsonPath('item.status', 'pending')
            ->assertJsonPath('item.profile_phone', '254711000111')
            ->assertJsonPath('item.profile_image_url', 'https://cdn.kenya.test/luna-fresh.jpg')
            ->assertJsonPath('item.profile_age', '25')
            ->assertJsonPath('item.error_message', null);
    }

    public function test_hydrate_profile_endpoint_derives_age_from_birthday_and_prefers_main_media_image(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.kenya.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Hydrate profile campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-hydrate-profile',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 95350,
            'name' => 'Terrian',
            'phone_normalized' => '254741015966',
            'main_image_url' => null,
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'wp_post_id' => 95350,
            'profile_url' => 'https://kenya.example/?p=95350',
            'custom_message' => 'Hydrate me',
            'status' => 'pending_extraction',
            'scheduled_at' => '2026-03-05 11:00:00',
            'profile_age' => null,
            'profile_image_url' => null,
        ]);

        Http::fake([
            'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/95350' => Http::response([
                'client' => [
                    'name' => 'Terrian',
                    'phone' => '254741015966',
                    'meta' => [
                        'birthday' => '2003-01-10',
                    ],
                ],
            ], 200),
            'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/95350/media' => Http::response([
                'data' => [
                    [
                        'id' => 3001,
                        'url' => 'https://cdn.kenya.test/media/terrian-side.webp',
                        'filename' => 'terrian-side.webp',
                        'is_main' => false,
                    ],
                    [
                        'id' => 3002,
                        'url' => 'https://cdn.kenya.test/media/terrian-main.webp',
                        'filename' => 'terrian-main.webp',
                        'is_main' => true,
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/hydrate-profile", [
            'force' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('item.profile_age', '23')
            ->assertJsonPath('item.profile_image_url', 'https://cdn.kenya.test/media/terrian-main.webp')
            ->assertJsonPath('item.status', 'pending')
            ->assertJsonPath('sources.age_source', 'wp_birthday_derived')
            ->assertJsonPath('sources.image_source', 'wp_media_main')
            ->assertJsonCount(2, 'media');
    }

    public function test_hydrate_profile_endpoint_uses_scheduled_at_date_for_age_derivation(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.kenya.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Scheduled age reference',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-hydrate-age-reference',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 95350,
            'name' => 'Terrian',
            'phone_normalized' => '254741015966',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'wp_post_id' => 95350,
            'profile_url' => 'https://kenya.example/?p=95350',
            'custom_message' => 'Hydrate me',
            'status' => 'pending_extraction',
            // 2024-01-09 21:15 UTC -> 2024-01-10 00:15 Africa/Nairobi.
            'scheduled_at' => '2024-01-09 21:15:00',
            'profile_age' => null,
        ]);

        Http::fake([
            'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/95350' => Http::response([
                'client' => [
                    'name' => 'Terrian',
                    'meta' => [
                        'birthday' => '2003-01-10',
                    ],
                ],
            ], 200),
            'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/95350/media' => Http::response([
                'data' => [],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/hydrate-profile", [
            'force' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('item.profile_age', '21')
            ->assertJsonPath('sources.age_source', 'wp_birthday_derived');
    }

    public function test_select_item_media_updates_item_only_without_mutating_client_main_image(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.kenya.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Select media campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-select-media',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 95350,
            'name' => 'Terrian',
            'phone_normalized' => '254741015966',
            'main_image_url' => 'https://kenya.example/media/client-main.webp',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'wp_post_id' => 95350,
            'profile_url' => 'https://kenya.example/?p=95350',
            'custom_message' => 'Select media',
            'status' => 'failed',
            'profile_image_url' => null,
        ]);

        Http::fake([
            'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/95350/media' => Http::response([
                'data' => [
                    [
                        'id' => 5001,
                        'url' => 'https://cdn.kenya.test/media/terrian-main.webp',
                        'filename' => 'terrian-main.webp',
                        'is_main' => true,
                    ],
                    [
                        'id' => 5002,
                        'url' => 'https://cdn.kenya.test/media/terrian-alt.webp',
                        'filename' => 'terrian-alt.webp',
                        'is_main' => false,
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/media/select", [
            'attachment_id' => 5002,
        ]);

        $response->assertOk()
            ->assertJsonPath('item.profile_image_url', 'https://cdn.kenya.test/media/terrian-alt.webp')
            ->assertJsonPath('selected_media.id', 5002);

        $this->assertSame('https://kenya.example/media/client-main.webp', (string) $client->fresh()->main_image_url);
        $this->assertSame('https://cdn.kenya.test/media/terrian-alt.webp', (string) $item->fresh()->profile_image_url);
    }

    public function test_upload_item_media_endpoint_allows_marketing_and_applies_uploaded_image_to_item(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $platform->forceFill([
            'wp_api_url' => 'https://wp.kenya.test/wp-json/exotic-crm/v1',
            'wp_api_user' => 'api-user',
            'wp_api_password' => 'api-pass',
        ])->save();

        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Upload media campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-upload-media',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
            'wp_post_id' => 95350,
            'name' => 'Terrian',
            'phone_normalized' => '254741015966',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'wp_post_id' => 95350,
            'profile_url' => 'https://kenya.example/?p=95350',
            'custom_message' => 'Upload media',
            'status' => 'failed',
            'profile_image_url' => null,
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'POST' && $request->url() === 'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/95350/media') {
                return Http::response([
                    'attachment' => [
                        'id' => 9001,
                        'url' => 'https://cdn.kenya.test/media/terrian-uploaded.webp',
                        'filename' => 'terrian-uploaded.webp',
                        'mime_type' => 'image/webp',
                        'uploaded_at' => '2026-03-04T11:00:00Z',
                    ],
                ], 200);
            }

            if ($request->method() === 'GET' && $request->url() === 'https://wp.kenya.test/wp-json/exotic-crm/v1/clients/95350/media') {
                return Http::response([
                    'data' => [
                        [
                            'id' => 9001,
                            'url' => 'https://cdn.kenya.test/media/terrian-uploaded.webp',
                            'filename' => 'terrian-uploaded.webp',
                            'is_main' => false,
                            'mime_type' => 'image/webp',
                            'uploaded_at' => '2026-03-04T11:00:00Z',
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $response = $this->post("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/media/upload", [
            'file' => UploadedFile::fake()->image('terrian-upload.png'),
            'apply_to_item' => '1',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('item.profile_image_url', 'https://cdn.kenya.test/media/terrian-uploaded.webp')
            ->assertJsonPath('uploaded_media.id', 9001)
            ->assertJsonPath('uploaded_media.url', 'https://cdn.kenya.test/media/terrian-uploaded.webp')
            ->assertJsonCount(1, 'media');
    }

    public function test_remove_item_endpoint_soft_skips_item_and_updates_totals(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Remove item campaign',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-remove-item',
            'total_items' => 2,
        ]);

        $itemA = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/a/',
            'custom_message' => 'A',
            'status' => 'pending',
        ]);

        PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/b/',
            'custom_message' => 'B',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/crm/push-campaigns/{$campaign->id}/items/{$itemA->id}");
        $response->assertOk()
            ->assertJsonPath('item.status', 'skipped');

        $this->assertSame('skipped', (string) $itemA->fresh()->status);
        $this->assertSame(1, (int) $campaign->fresh()->total_items);
    }

    public function test_sent_item_cannot_be_edited_matched_or_removed(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Immutable sent item',
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_by' => $user->id,
            'upload_batch_id' => 'batch-sent-item',
        ]);

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/escort/sent/',
            'custom_message' => 'Sent already',
            'status' => 'sent',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}", [
            'custom_message' => 'New message',
        ])->assertStatus(422);

        $this->postJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/match-crm", [
            'client_id' => 999999,
        ])->assertStatus(422);

        $this->deleteJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}")
            ->assertStatus(422);

        $this->postJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/hydrate-profile", [
            'force' => true,
        ])->assertStatus(422);

        $this->postJson("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/media/select", [
            'attachment_id' => 123,
        ])->assertStatus(422);

        $this->post("/api/crm/push-campaigns/{$campaign->id}/items/{$item->id}/media/upload", [
            'file' => UploadedFile::fake()->image('sent-item.png'),
            'apply_to_item' => '1',
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }

    public function test_sync_subscribers_returns_diagnostics_for_single_market_when_provider_fails(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);

        $this->mock(SubscriberSyncService::class, function ($mock) use ($platform): void {
            $mock->shouldReceive('syncPlatform')
                ->once()
                ->withArgs(fn(Platform $input): bool => (int) $input->id === (int) $platform->id)
                ->andReturn(null);
        });

        $this->mock(PushProviderService::class, function ($mock) use ($platform): void {
            $mock->shouldReceive('debugSubscriberCountForPlatform')
                ->once()
                ->with((int) $platform->id)
                ->andReturn([
                    'ok' => false,
                    'provider' => 'webpushr',
                    'total' => 0,
                    'active' => 0,
                    'error' => 'Provider credentials are incomplete.',
                ]);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/push-campaigns/subscribers/sync', [
            'platform_id' => $platform->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('synced', 0);
        $response->assertJsonPath('diagnostics.0.platform_id', $platform->id);
        $response->assertJsonPath('diagnostics.0.provider', 'webpushr');
        $response->assertJsonPath('message', 'Provider credentials are incomplete.');
    }

    public function test_provider_diagnostic_includes_webpushr_error_message(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');

        IntegrationSetting::query()->updateOrCreate(
            ['key' => 'push_provider_config'],
            [
                'value' => [
                    'enabled' => true,
                    'default_provider' => 'webpushr',
                    'platforms' => [
                        (string) $platform->id => [
                            'active_provider' => 'webpushr',
                            'fallback_provider' => 'none',
                            'webpushr' => [
                                'api_key' => 'sample-key',
                                'auth_token' => 'sample-token',
                            ],
                        ],
                    ],
                ],
            ]
        );

        Http::fake([
            'https://api.webpushr.com/v1/site/subscriber_count' => Http::response([
                'status' => 'failure',
                'description' => 'Subscription expired',
            ], 403),
        ]);

        $diagnostic = app(PushProviderService::class)->debugSubscriberCountForPlatform((int) $platform->id);

        $this->assertFalse((bool) $diagnostic['ok']);
        $this->assertSame('webpushr', $diagnostic['provider']);
        $this->assertStringContainsString('403', (string) $diagnostic['error']);
        $this->assertStringContainsString('Subscription expired', (string) $diagnostic['error']);
    }

    public function test_sub_admin_can_update_push_provider_config(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('sub_admin', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/crm/settings/integrations/push-provider', [
            'enabled' => true,
            'default_provider' => 'webpushr',
            'platforms' => [
                (string) $platform->id => [
                    'active_provider' => 'webpushr',
                    'fallback_provider' => 'none',
                    'webpushr' => [
                        'api_key' => 'key-1',
                        'auth_token' => 'token-1',
                    ],
                ],
            ],
            'reason' => 'Configure push routing for Kenya',
        ]);

        $response->assertOk();
        $response->assertJsonPath('push_provider.enabled', true);
    }

    public function test_exotic_push_provider_maps_send_payload_and_headers(): void
    {
        config(['services.exotic_push.base_url' => 'https://push.example.test/api/']);

        Http::fake([
            'https://push.example.test/api/sites/site-123/rest-api/notifications' => Http::response([
                'success' => true,
                'data' => [
                    'notificationId' => 'epe-notification-1',
                    'jobId' => 'job-1',
                    'queued' => true,
                ],
            ]),
        ]);

        $provider = new ExoticPushProvider();
        $result = $provider->send([
            'title' => Str::repeat('T', 160),
            'message' => Str::repeat('M', 520),
            'target_url' => 'https://kenya.example/profiles/1',
            'icon_url' => 'https://kenya.example/icon.png',
            'image_url' => 'https://kenya.example/image.jpg',
        ], [
            'site_id' => 'site-123',
            'api_key' => 'rest_sample',
            'auth_token' => 'token-sample',
        ], [
            'idempotency_key' => 'epe-item-99',
        ]);

        $this->assertTrue((bool) $result['success']);
        $this->assertSame('exoticpush', $result['provider']);
        $this->assertSame('epe-notification-1', $result['provider_notification_id']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://push.example.test/api/sites/site-123/rest-api/notifications'
                && ($request->header('X-EPE-Site-Key')[0] ?? null) === 'rest_sample'
                && ($request->header('Authorization')[0] ?? null) === 'Bearer token-sample'
                && ($request->header('Idempotency-Key')[0] ?? null) === 'epe-item-99'
                && mb_strlen((string) ($data['title'] ?? '')) === 150
                && mb_strlen((string) ($data['body'] ?? '')) === 500
                && ($data['url'] ?? null) === 'https://kenya.example/profiles/1'
                && ($data['icon'] ?? null) === 'https://kenya.example/icon.png'
                && ($data['image'] ?? null) === 'https://kenya.example/image.jpg';
        });
    }

    public function test_exotic_push_provider_treats_success_flag_as_required(): void
    {
        config(['services.exotic_push.base_url' => 'https://push.example.test/api']);

        Http::fake([
            'https://push.example.test/api/sites/site-123/rest-api/notifications' => Http::response([
                'success' => false,
                'message' => 'Rejected by provider',
            ]),
        ]);

        $result = (new ExoticPushProvider())->send([
            'title' => 'Test',
            'message' => 'Body',
            'target_url' => 'https://kenya.example/profiles/1',
        ], [
            'site_id' => 'site-123',
            'api_key' => 'rest_sample',
            'auth_token' => 'token-sample',
        ]);

        $this->assertFalse((bool) $result['success']);
        $this->assertSame(200, data_get($result, 'provider_response.status'));
        $this->assertSame('Rejected by provider', data_get($result, 'provider_response.body.message'));
    }

    public function test_exotic_push_provider_returns_failures_for_unauthorized_and_rate_limited_send(): void
    {
        config(['services.exotic_push.base_url' => 'https://push.example.test/api']);

        Http::fake([
            'https://push.example.test/api/sites/site-401/rest-api/notifications' => Http::response([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401),
            'https://push.example.test/api/sites/site-429/rest-api/notifications' => Http::response([
                'success' => false,
                'message' => 'Rate limited',
                'retryAfterMs' => 1000,
            ], 429),
        ]);

        foreach ([401, 429] as $status) {
            $result = (new ExoticPushProvider())->send([
                'title' => 'Test',
                'message' => 'Body',
                'target_url' => 'https://kenya.example/profiles/1',
            ], [
                'site_id' => 'site-' . $status,
                'api_key' => 'rest_sample',
                'auth_token' => 'token-sample',
            ]);

            $this->assertFalse((bool) $result['success']);
            $this->assertSame($status, data_get($result, 'provider_response.status'));
        }
    }

    public function test_exotic_push_provider_maps_status_and_subscriber_count(): void
    {
        config(['services.exotic_push.base_url' => 'https://push.example.test/api']);

        Http::fake([
            'https://push.example.test/api/sites/site-123/rest-api/notifications/notification-123/status' => Http::response([
                'data' => [
                    'status' => 'sent',
                    'sent' => 148,
                    'delivered' => 140,
                    'clicked' => 25,
                    'failed' => 8,
                ],
            ]),
            'https://push.example.test/api/sites/site-123/rest-api/subscribers/count' => Http::response([
                'data' => [
                    'subscriberCount' => 148,
                ],
            ]),
        ]);

        $provider = new ExoticPushProvider();
        $config = [
            'site_id' => 'site-123',
            'api_key' => 'rest_sample',
            'auth_token' => 'token-sample',
        ];

        $status = $provider->getStatus('notification-123', $config);
        $count = $provider->getSubscriberCount($config);

        $this->assertSame(148, $status['total_sent']);
        $this->assertSame(140, $status['delivered']);
        $this->assertSame(25, $status['clicked']);
        $this->assertSame(8, $status['failed']);
        $this->assertSame(148, $count['total']);
        $this->assertSame(148, $count['active']);
    }

    public function test_sub_admin_can_save_and_mask_exotic_push_provider_config(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('sub_admin', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/crm/settings/integrations/push-provider', [
            'enabled' => true,
            'default_provider' => 'exoticpush',
            'platforms' => [
                (string) $platform->id => [
                    'active_provider' => 'exoticpush',
                    'fallback_provider' => 'webpushr',
                    'exoticpush' => [
                        'site_id' => 'site-123',
                        'api_key' => 'rest_sample',
                        'auth_token' => 'token-sample',
                    ],
                ],
            ],
            'reason' => 'Configure EPE for Kenya',
        ]);

        $response->assertOk();
        $response->assertJsonPath("push_provider.platforms.{$platform->id}.exoticpush.site_id", 'site-123');
        $response->assertJsonPath("push_provider.platforms.{$platform->id}.exoticpush.api_key", '••••••••');
        $response->assertJsonPath("push_provider.platforms.{$platform->id}.exoticpush.api_key_configured", true);
        $response->assertJsonPath("push_provider.platforms.{$platform->id}.exoticpush.auth_token_configured", true);

        $response = $this->patchJson('/api/crm/settings/integrations/push-provider', [
            'enabled' => true,
            'default_provider' => 'exoticpush',
            'platforms' => [
                (string) $platform->id => [
                    'active_provider' => 'exoticpush',
                    'fallback_provider' => 'none',
                    'exoticpush' => [
                        'site_id' => 'site-456',
                        'api_key' => '',
                        'auth_token' => '',
                    ],
                ],
            ],
            'reason' => 'Rotate EPE site id only',
        ]);

        $response->assertOk();
        $response->assertJsonPath("push_provider.platforms.{$platform->id}.exoticpush.site_id", 'site-456');
        $response->assertJsonPath("push_provider.platforms.{$platform->id}.exoticpush.api_key_configured", true);
        $response->assertJsonPath("push_provider.platforms.{$platform->id}.exoticpush.auth_token_configured", true);

        $stored = IntegrationSetting::query()->where('key', 'push_provider_config')->value('value');
        $this->assertSame('site-456', data_get($stored, "platforms.{$platform->id}.exoticpush.site_id"));
        $this->assertSame('rest_sample', data_get($stored, "platforms.{$platform->id}.exoticpush.api_key"));
        $this->assertSame('token-sample', data_get($stored, "platforms.{$platform->id}.exoticpush.auth_token"));
    }

    public function test_refresh_analytics_uses_item_provider_meta_when_present(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $campaign = PushCampaign::query()->create([
            'name' => 'Fallback analytics',
            'platform_id' => $platform->id,
            'provider' => 'webpushr',
            'status' => 'running',
        ]);
        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/profile',
            'custom_message' => 'Hello',
            'status' => 'sent',
            'provider_notification_id' => 'epe-notification-1',
            'provider_meta' => [
                'provider' => 'exoticpush',
            ],
        ]);

        $this->mock(PushProviderService::class, function ($mock) use ($platform): void {
            $mock->shouldReceive('pollAnalytics')
                ->once()
                ->with('epe-notification-1', 'exoticpush', ['platform_id' => (int) $platform->id])
                ->andReturn([
                    'total_sent' => 10,
                    'delivered' => 9,
                    'clicked' => 2,
                    'failed' => 1,
                    'closed' => null,
                    'raw' => [],
                ]);
        });

        app(PushCampaignService::class)->refreshAnalytics($campaign);

        $this->assertSame(10, data_get($item->fresh()->delivery_stats, 'total_sent'));
    }

    private function createPlatform(string $name, string $domain, string $country, string $timezone = 'Africa/Nairobi'): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => $domain,
            'country' => $country,
            'timezone' => $timezone,
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' User',
            'email' => strtolower($role) . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => $assignedMarketIds,
            'status' => 'active',
        ]);
    }
}
