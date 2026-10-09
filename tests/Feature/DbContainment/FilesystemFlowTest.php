<?php

namespace Tests\Feature\DbContainment;

use App\Models\DbContainmentMarket;
use App\Services\DbContainment\ContainmentException;
use App\Services\DbContainment\ContainmentExecutor;
use App\Services\DbContainment\ContainmentService;
use App\Services\DbContainment\FilesystemContainment;
use App\Services\DbContainment\RestrictedHostTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

class FilesystemFlowTest extends TestCase
{
    use BuildsWordPressFixture, RefreshDatabase;

    private string $vault;

    private array $journals = [];

    private int $mutations = 0;

    private bool $loseResponse = false;

    private bool $pendingJournal = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->bootScanner();
        $this->vault = sys_get_temp_dir().'/containment-files-'.Str::uuid();
        config(['db_scanner.enabled' => false, 'db_containment.enabled' => true, 'db_containment.filesystem_enabled' => true, 'db_containment.quarantine_enabled' => true, 'db_containment.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'db_containment.vault' => $this->vault]);
        $identity = ['path' => 'unexpected.php', 'size' => 0, 'mtime_ns' => 1, 'ctime_ns' => 2, 'mode' => 0640, 'inode' => 42, 'dev' => 1, 'sha256' => hash('sha256', '')];
        $host = \Mockery::mock(RestrictedHostTransport::class);
        $host->shouldReceive('call')->andReturnUsing(function ($market, $request) use ($identity) {
            return match ($request['action']) {
                'diagnose' => ['root_id' => 'fixture', 'files' => [$identity], 'gaps' => []],
                'inspect' => ['root_id' => 'fixture', 'identity' => $identity],
                'operation_status' => ['journal' => $this->journals[$request['operation_id']] ?? null],
                default => $this->mutateHost($request),
            };
        });
        $this->app->instance(RestrictedHostTransport::class, $host);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->vault.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->vault)) {
            rmdir($this->vault);
        }
        parent::tearDown();
    }

    private function mutateHost(array $request): array
    {
        $this->mutations++;
        $result = ['ok' => true, $request['action'] === 'restore' ? 'restored' : 'quarantined' => true];
        $this->journals[$request['operation_id']] = ['status' => $this->pendingJournal ? 'intent' : 'complete', 'result' => $result];
        if ($this->loseResponse) {
            throw new ContainmentException('host_transport_failed');
        }

        return $result;
    }

    private function approved(): array
    {
        $platform = $this->marketPlatform('Synthetic', 'files.test');
        DbContainmentMarket::query()->create(['platform_id' => $platform->id, 'enabled' => true, 'filesystem_enabled' => true, 'quarantine_enabled' => true, 'configuration' => []]);
        $actor = $this->adminUser();
        $files = app(FilesystemContainment::class)->diagnose($actor, $platform);
        $observation = \App\Models\DbContainmentFileObservation::findOrFail($files['observations'][0]['id']);
        $op = app(FilesystemContainment::class)->preview($actor, $observation, (string) Str::uuid());
        $op = app(ContainmentService::class)->confirm($actor, $op, 'files.test', null, $op->preview_digest);

        return [$op, $actor];
    }

    public function test_quarantine_and_separately_confirmed_restore_have_durable_independent_operations(): void
    {
        [$op, $actor] = $this->approved();
        $executor = app(ContainmentExecutor::class);
        $executor->execute($op->id);
        $this->assertSame('verified', $op->fresh()->status);
        $this->assertTrue($op->fresh()->result['filesystem_verified']);
        $restore = app(ContainmentService::class)->restorePreview($actor, $op->fresh(), (string) Str::uuid());
        $this->assertSame('preview', $restore->status);
        $this->assertSame(1, $this->mutations);
        app(ContainmentService::class)->confirm($actor, $restore, 'files.test', null, $restore->preview_digest);
        $executor->execute($restore->id);
        $this->assertSame('verified', $restore->fresh()->status);
        $this->assertSame('verified', $op->fresh()->status);
        $this->assertNotSame($op->fresh()->backup_id, $restore->fresh()->backup_id);
        $this->assertSame(2, $this->mutations);
    }

    public function test_lost_host_response_recovers_completed_journal_without_replaying_move(): void
    {
        [$op] = $this->approved();
        $this->loseResponse = true;
        $executor = app(ContainmentExecutor::class);
        $executor->execute($op->id);
        $this->assertSame('recovery_pending', $op->fresh()->status);
        $executor->execute($op->id);
        $this->assertSame('verified', $op->fresh()->status);
        $this->assertSame(1, $this->mutations);
    }

    public function test_uncertain_host_intent_pins_market_and_refuses_automatic_replay(): void
    {
        [$op] = $this->approved();
        $this->loseResponse = $this->pendingJournal = true;
        $executor = app(ContainmentExecutor::class);
        $executor->execute($op->id);
        $executor->execute($op->id);
        $this->assertSame('recovery_pending', $op->fresh()->status);
        $this->assertSame('host_outcome_unknown_manual_reconciliation', $op->fresh()->result_code);
        $this->assertSame(1, $this->mutations);
        $this->assertSame($op->id, DB::table('db_market_operation_leases')->where('platform_id', $op->platform_id)->value('operation_id'));
        $this->assertNull(DB::table('db_market_operation_leases')->where('platform_id', 0)->value('operation_id'));
    }
}
