<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->applyStatuses([
            'processing',
            'draft',
            'scheduled',
            'running',
            'completed',
            'partial',
            'failed',
            'cancelled',
        ]);
    }

    public function down(): void
    {
        DB::table('push_campaigns')
            ->where('status', 'cancelled')
            ->update(['status' => 'failed']);

        $this->applyStatuses([
            'processing',
            'draft',
            'scheduled',
            'running',
            'completed',
            'partial',
            'failed',
        ]);
    }

    /**
     * @param  array<int,string>  $statuses
     */
    private function applyStatuses(array $statuses): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->rebuildSqliteTable($statuses);
            return;
        }

        $enumValues = implode("','", array_map(static fn (string $status) => str_replace("'", "''", $status), $statuses));
        DB::statement("ALTER TABLE push_campaigns MODIFY status ENUM('{$enumValues}') NOT NULL DEFAULT 'processing'");
    }

    /**
     * @param  array<int,string>  $statuses
     */
    private function rebuildSqliteTable(array $statuses): void
    {
        $quotedStatuses = implode(', ', array_map(
            static fn (string $status) => "'" . str_replace("'", "''", $status) . "'",
            $statuses
        ));

        DB::statement('PRAGMA foreign_keys=OFF');

        try {
            DB::beginTransaction();

            DB::statement(
                "CREATE TABLE push_campaigns_tmp (
                    id integer primary key autoincrement not null,
                    name varchar not null,
                    platform_id integer not null,
                    provider varchar(50) null,
                    status varchar not null default 'processing' check (status in ({$quotedStatuses})),
                    total_items integer not null default 0,
                    sent_count integer not null default 0,
                    failed_count integer not null default 0,
                    scheduled_at datetime null,
                    executed_at datetime null,
                    completed_at datetime null,
                    confirmed_at datetime null,
                    created_by integer null,
                    auto_push_plan_id integer null,
                    auto_push_run_id integer null,
                    upload_batch_id varchar(36) null,
                    source_filename varchar(255) null,
                    created_at datetime null,
                    updated_at datetime null,
                    foreign key(platform_id) references platforms(id),
                    foreign key(created_by) references users(id) on delete set null,
                    foreign key(auto_push_plan_id) references auto_push_plans(id) on delete set null,
                    foreign key(auto_push_run_id) references auto_push_runs(id) on delete set null
                )"
            );

            DB::statement(
                'INSERT INTO push_campaigns_tmp (
                    id, name, platform_id, provider, status, total_items, sent_count, failed_count,
                    scheduled_at, executed_at, completed_at, confirmed_at, created_by,
                    auto_push_plan_id, auto_push_run_id, upload_batch_id, source_filename,
                    created_at, updated_at
                )
                SELECT
                    id, name, platform_id, provider, status, total_items, sent_count, failed_count,
                    scheduled_at, executed_at, completed_at, confirmed_at, created_by,
                    auto_push_plan_id, auto_push_run_id, upload_batch_id, source_filename,
                    created_at, updated_at
                FROM push_campaigns'
            );

            DB::statement('DROP TABLE push_campaigns');
            DB::statement('ALTER TABLE push_campaigns_tmp RENAME TO push_campaigns');
            DB::statement('CREATE INDEX push_campaigns_platform_id_status_index ON push_campaigns (platform_id, status)');
            DB::statement('CREATE INDEX push_campaigns_created_by_index ON push_campaigns (created_by)');
            DB::statement('CREATE INDEX push_campaigns_scheduled_at_index ON push_campaigns (scheduled_at)');
            DB::statement('CREATE INDEX push_campaigns_upload_batch_id_index ON push_campaigns (upload_batch_id)');
            DB::statement('CREATE INDEX push_campaigns_auto_push_plan_id_index ON push_campaigns (auto_push_plan_id)');

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        } finally {
            DB::statement('PRAGMA foreign_keys=ON');
        }
    }
};
