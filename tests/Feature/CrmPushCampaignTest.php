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
use App\Services\PushCampaign\PushCampaignService;
use App\Services\PushCampaign\UploadBatchStatusService;
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
            ->assertJsonCount(5, 'items')
            ->assertJsonPath('items.0.batch_id', 'queue-b')
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
            'scheduled_at' => now()->subHour(),
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

    public function test_dispatch_command_promotes_running_campaign_pending_items(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $this->createUser('admin', [$platform->id]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Running campaign',
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_by' => 1,
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
