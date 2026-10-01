<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Observatory (market database scanner) — additive CRM tables only.
 *
 * Nothing here touches a market WordPress database or an existing CRM table.
 * The scanner ships disabled: the settings row is created with enabled=false
 * and every schedule is seeded disabled by `crm:db-scan-sync-packs`.
 *
 * Operational rollback is "scanner off", never this down migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('db_scan_settings')) {
            Schema::create('db_scan_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('enabled')->default(false);
                $table->boolean('paused')->default(false);
                $table->boolean('emergency_stop')->default(false);
                $table->json('limits')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('db_scan_connections')) {
            Schema::create('db_scan_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('platform_id')->unique()->constrained('platforms')->restrictOnDelete();
                $table->string('driver', 16)->default('mysql');
                $table->string('host', 191)->nullable();
                $table->unsignedInteger('port')->default(3306);
                $table->string('socket', 255)->nullable();
                $table->string('database', 128);
                $table->text('username');
                $table->text('password')->nullable();
                $table->string('prefix', 64)->default('wp_');
                $table->string('tls_mode', 16)->default('none');
                $table->text('tls_ca')->nullable();
                $table->string('host_group', 120);
                $table->unsignedInteger('config_version')->default(1);
                $table->boolean('enabled')->default(false);
                $table->string('preflight_status', 20)->default('never');
                $table->timestamp('preflight_at')->nullable();
                $table->unsignedInteger('preflight_config_version')->nullable();
                $table->string('preflight_error_code', 60)->nullable();
                $table->string('preflight_error', 300)->nullable();
                $table->json('capabilities')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->index('host_group');
            });
        }

        if (! Schema::hasTable('db_scan_rules')) {
            Schema::create('db_scan_rules', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->string('pack', 40);
                $table->string('pack_version', 20);
                $table->string('category', 20);
                $table->string('kind', 20);
                $table->string('title', 200);
                $table->json('surfaces');
                $table->json('definition');
                $table->string('default_severity', 10);
                $table->string('default_confidence', 20)->nullable();
                $table->json('profiles');
                $table->boolean('allowlistable')->default(true);
                $table->text('why')->nullable();
                $table->text('remediation')->nullable();
                $table->json('references')->nullable();
                $table->boolean('enabled')->default(true);
                $table->char('definition_hash', 64);
                $table->boolean('retired')->default(false);
                $table->timestamps();
                $table->index(['pack', 'category']);
            });
        }

        if (! Schema::hasTable('db_scan_rule_versions')) {
            Schema::create('db_scan_rule_versions', function (Blueprint $table) {
                $table->id();
                $table->string('rule_key', 100);
                $table->string('pack_version', 20);
                $table->char('definition_hash', 64);
                $table->json('definition');
                $table->timestamp('created_at')->nullable();
                $table->unique(['rule_key', 'definition_hash'], 'dbscan_rule_versions_key_hash_unique');
            });
        }

        if (! Schema::hasTable('db_scan_rule_overrides')) {
            Schema::create('db_scan_rule_overrides', function (Blueprint $table) {
                $table->id();
                $table->string('rule_key', 100);
                $table->string('scope_key', 40);
                $table->unsignedBigInteger('platform_id')->nullable()->index();
                $table->boolean('enabled')->nullable();
                $table->string('severity', 10)->nullable();
                $table->json('thresholds')->nullable();
                $table->json('disabled_lists')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['rule_key', 'scope_key'], 'dbscan_overrides_rule_scope_unique');
            });
        }

        if (! Schema::hasTable('db_scan_lists')) {
            Schema::create('db_scan_lists', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80);
                $table->string('scope_key', 40);
                $table->unsignedBigInteger('platform_id')->nullable()->index();
                $table->string('kind', 20);
                $table->string('description', 255)->nullable();
                $table->json('entries');
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['key', 'scope_key'], 'dbscan_lists_key_scope_unique');
            });
        }

        if (! Schema::hasTable('db_scan_config_versions')) {
            Schema::create('db_scan_config_versions', function (Blueprint $table) {
                $table->id();
                $table->char('hash', 64)->unique();
                $table->longText('config');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('db_scan_schedules')) {
            Schema::create('db_scan_schedules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('profile', 10);
                $table->string('cron', 60);
                $table->json('market_scope');
                $table->json('window')->nullable();
                $table->boolean('enabled')->default(false);
                $table->unsignedInteger('revision')->default(1);
                $table->timestamp('next_due_at')->nullable();
                $table->timestamp('last_dispatched_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('db_scan_occurrences')) {
            Schema::create('db_scan_occurrences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('schedule_id');
                $table->unsignedInteger('schedule_revision');
                $table->unsignedBigInteger('platform_id');
                $table->timestamp('due_at_utc')->nullable();
                $table->string('state', 20)->default('pending');
                $table->timestamp('claimed_at')->nullable();
                $table->unsignedBigInteger('pass_id')->nullable();
                $table->string('skip_reason', 120)->nullable();
                $table->timestamps();
                $table->unique(['schedule_id', 'schedule_revision', 'platform_id', 'due_at_utc'], 'dbscan_occurrences_unique');
                $table->index(['state', 'due_at_utc'], 'dbscan_occurrences_state_due');
            });
        }

        if (! Schema::hasTable('db_scan_sweeps')) {
            Schema::create('db_scan_sweeps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_id');
                $table->string('profile', 10);
                $table->unsignedBigInteger('config_version_id');
                $table->string('status', 24)->default('running');
                $table->json('high_water')->nullable();
                $table->json('cursors')->nullable();
                $table->json('coverage')->nullable();
                $table->timestamp('oldest_observed_at')->nullable();
                $table->timestamp('newest_observed_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_served_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->string('stop_reason', 120)->nullable();
                $table->boolean('continuation_paused')->default(false);
                $table->timestamps();
                $table->index(['platform_id', 'status'], 'dbscan_sweeps_platform_status');
            });
        }

        if (! Schema::hasTable('db_scan_passes')) {
            Schema::create('db_scan_passes', function (Blueprint $table) {
                $table->id();
                $table->string('trigger', 20);
                $table->string('mode', 12)->default('scan');
                $table->string('profile', 10);
                $table->unsignedBigInteger('schedule_id')->nullable()->index();
                $table->unsignedBigInteger('sweep_id')->nullable()->index();
                $table->unsignedBigInteger('triggered_by')->nullable();
                $table->string('idempotency_key', 80)->nullable();
                $table->json('scope')->nullable();
                $table->json('rules')->nullable();
                $table->boolean('verbose')->default(false);
                $table->boolean('bypass_window')->default(false);
                $table->string('status', 24)->default('queued');
                $table->string('pause_reason', 20)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->unique(['triggered_by', 'idempotency_key'], 'dbscan_passes_idempotency');
                $table->index(['status', 'created_at'], 'dbscan_passes_status_created');
            });
        }

        if (! Schema::hasTable('db_scan_market_runs')) {
            Schema::create('db_scan_market_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pass_id');
                $table->unsignedBigInteger('platform_id');
                $table->unsignedBigInteger('sweep_id')->nullable()->index();
                $table->string('mode', 12)->default('scan');
                $table->string('profile', 10);
                $table->string('status', 24)->default('queued');
                $table->unsignedBigInteger('config_version_id')->nullable();
                $table->unsignedInteger('connection_config_version')->nullable();
                $table->unsignedBigInteger('generation')->default(0);
                $table->string('owner_token', 64)->nullable();
                $table->timestamp('heartbeat_at')->nullable();
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('deadline_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->double('active_seconds')->default(0);
                $table->unsignedInteger('budget_seconds')->default(30);
                $table->unsignedTinyInteger('transient_failures')->default(0);
                $table->string('error_code', 60)->nullable();
                $table->string('error_message', 300)->nullable();
                $table->string('pause_reason', 20)->nullable();
                $table->string('control', 20)->nullable();
                $table->timestamp('resolution_completed_at')->nullable();
                $table->json('cursor')->nullable();
                $table->json('metrics')->nullable();
                $table->string('db_engine', 80)->nullable();
                $table->unsignedBigInteger('snapshot_id')->nullable();
                $table->json('test_samples')->nullable();
                $table->timestamps();
                $table->unique(['pass_id', 'platform_id'], 'dbscan_runs_pass_platform');
                $table->index(['platform_id', 'status'], 'dbscan_runs_platform_status');
                $table->index(['status', 'next_attempt_at'], 'dbscan_runs_status_next');
            });
        }

        if (! Schema::hasTable('db_scan_admissions')) {
            Schema::create('db_scan_admissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_id')->unique();
                $table->unsignedBigInteger('active_run_id')->nullable();
                $table->unsignedBigInteger('generation')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('db_scan_slots')) {
            Schema::create('db_scan_slots', function (Blueprint $table) {
                $table->id();
                $table->string('slot_key', 140)->unique();
                $table->unsignedBigInteger('owner_run_id')->nullable();
                $table->string('owner_token', 64)->nullable();
                $table->unsignedBigInteger('generation')->default(0);
                $table->timestamp('lease_expires_at')->nullable();
                $table->timestamp('heartbeat_at')->nullable();
                $table->string('quarantined_reason', 200)->nullable();
                $table->timestamp('quarantined_at')->nullable();
                $table->timestamps();
                $table->index('lease_expires_at');
            });
        }

        if (! Schema::hasTable('db_scan_outbox')) {
            Schema::create('db_scan_outbox', function (Blueprint $table) {
                $table->id();
                $table->string('dedupe_key', 120)->unique();
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('generation');
                $table->timestamp('available_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamps();
                $table->index(['published_at', 'available_at'], 'dbscan_outbox_pending');
            });
        }

        if (! Schema::hasTable('db_scan_chunks')) {
            Schema::create('db_scan_chunks', function (Blueprint $table) {
                $table->id();
                $table->char('chunk_key', 64)->unique();
                $table->unsignedBigInteger('run_id')->index();
                $table->string('surface_key', 60);
                $table->string('adapter_version', 20);
                $table->string('range_start', 40);
                $table->string('range_end', 40);
                $table->unsignedInteger('rows')->default(0);
                $table->unsignedInteger('candidates')->default(0);
                $table->unsignedBigInteger('bytes')->default(0);
                $table->timestamp('committed_at')->nullable();
            });
        }

        if (! Schema::hasTable('db_scan_surface_coverage')) {
            Schema::create('db_scan_surface_coverage', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('market_run_id');
                $table->unsignedBigInteger('sweep_id')->nullable();
                $table->string('surface_key', 60);
                $table->string('adapter_version', 20);
                $table->string('status', 20)->default('incomplete');
                $table->string('reason', 40)->nullable();
                $table->unsignedBigInteger('rows_scanned')->default(0);
                $table->unsignedBigInteger('candidates')->default(0);
                $table->unsignedBigInteger('bytes_read')->default(0);
                $table->unsignedInteger('values_truncated')->default(0);
                $table->unsignedInteger('decode_capped')->default(0);
                $table->unsignedInteger('excluded_values')->default(0);
                $table->string('cursor_start', 40)->nullable();
                $table->string('cursor_end', 40)->nullable();
                $table->string('high_water', 40)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['market_run_id', 'surface_key'], 'dbscan_surface_cov_run_surface');
            });
        }

        if (! Schema::hasTable('db_scan_rule_coverage')) {
            Schema::create('db_scan_rule_coverage', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('market_run_id');
                $table->string('rule_key', 100);
                $table->char('rule_version_hash', 64);
                $table->string('surface_key', 60);
                $table->char('scope_hash', 64);
                $table->string('status', 20)->default('incomplete');
                $table->string('reason', 40)->nullable();
                $table->unsignedBigInteger('rows')->default(0);
                $table->unsignedBigInteger('candidates')->default(0);
                $table->unsignedInteger('matches')->default(0);
                $table->unsignedInteger('decode_capped')->default(0);
                $table->unsignedInteger('values_truncated')->default(0);
                $table->timestamps();
                $table->unique(['market_run_id', 'rule_key', 'surface_key'], 'dbscan_rule_cov_unique');
            });
        }

        if (! Schema::hasTable('db_scan_events')) {
            Schema::create('db_scan_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('market_run_id');
                $table->timestamp('at', 3)->nullable();
                $table->string('level', 10);
                $table->string('rule_key', 100)->nullable();
                $table->string('surface', 60)->nullable();
                $table->string('message', 500);
                $table->json('context')->nullable();
                $table->index(['market_run_id', 'id'], 'dbscan_events_run_id');
                $table->index('at');
            });
        }

        if (! Schema::hasTable('db_scan_snapshots')) {
            Schema::create('db_scan_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_id');
                $table->unsignedBigInteger('market_run_id');
                $table->string('profile', 10);
                $table->json('components');
                $table->timestamp('taken_at')->nullable();
                $table->timestamps();
                $table->index(['platform_id', 'taken_at'], 'dbscan_snapshots_platform_taken');
            });
        }

        if (! Schema::hasTable('db_scan_findings')) {
            Schema::create('db_scan_findings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('platform_id')->constrained('platforms')->restrictOnDelete();
                $table->char('fingerprint', 64)->unique();
                $table->string('rule_key', 100);
                $table->char('rule_version_hash', 64);
                $table->string('pack', 40);
                $table->string('pack_version', 20);
                $table->string('category', 20);
                $table->string('behavior', 40)->nullable();
                $table->string('severity', 10);
                $table->string('confidence', 20)->nullable();
                $table->string('title', 255);
                $table->json('subject');
                $table->char('subject_hash', 64);
                $table->json('evidence');
                $table->string('status', 20)->default('open');
                $table->timestamp('snoozed_until')->nullable();
                $table->unsignedBigInteger('assigned_to')->nullable();
                $table->string('note', 1000)->nullable();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedInteger('occurrences')->default(1);
                $table->unsignedBigInteger('latest_observation_id')->nullable();
                $table->unsignedBigInteger('last_run_id')->nullable();
                $table->unsignedBigInteger('suppression_id')->nullable();
                $table->timestamps();
                $table->index(['platform_id', 'status', 'severity', 'last_seen_at'], 'dbscan_findings_filters');
                $table->index(['rule_key', 'status'], 'dbscan_findings_rule_status');
                $table->index(['category', 'status'], 'dbscan_findings_category_status');
            });
        }

        if (! Schema::hasTable('db_scan_observations')) {
            Schema::create('db_scan_observations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('finding_id')->index();
                $table->unsignedBigInteger('market_run_id');
                $table->string('rule_key', 100);
                $table->char('rule_version_hash', 64);
                $table->unsignedBigInteger('config_version_id')->nullable();
                $table->char('subject_hash', 64);
                $table->char('payload_hash', 64)->nullable();
                $table->string('payload_hash_type', 20)->nullable();
                $table->string('confidence', 20)->nullable();
                $table->string('behavior', 40)->nullable();
                $table->json('evidence');
                $table->timestamp('created_at')->nullable();
                $table->unique(['market_run_id', 'rule_key', 'subject_hash'], 'dbscan_observations_unique');
            });
        }

        if (! Schema::hasTable('db_scan_finding_events')) {
            Schema::create('db_scan_finding_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('finding_id')->index();
                $table->unsignedBigInteger('market_run_id')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('type', 30);
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20)->nullable();
                $table->string('note', 1000)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('db_scan_suppressions')) {
            Schema::create('db_scan_suppressions', function (Blueprint $table) {
                $table->id();
                $table->string('rule_key', 100);
                $table->string('scope_key', 40);
                $table->unsignedBigInteger('platform_id')->nullable()->index();
                $table->char('subject_hash', 64)->nullable();
                $table->char('value_fingerprint', 64)->nullable();
                $table->char('rule_version_hash', 64);
                $table->string('reason', 500);
                $table->unsignedBigInteger('actor_id');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->timestamps();
                $table->index(['rule_key', 'scope_key'], 'dbscan_suppressions_rule_scope');
            });
        }

        if (! Schema::hasTable('db_scan_audit_events')) {
            Schema::create('db_scan_audit_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_type', 10)->default('user');
                $table->string('scope_key', 40);
                $table->unsignedBigInteger('platform_id')->nullable()->index();
                $table->string('entity', 40);
                $table->string('entity_id', 60)->nullable();
                $table->string('action', 40);
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('db_scan_daily_budgets')) {
            Schema::create('db_scan_daily_budgets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_id');
                $table->date('day');
                $table->double('active_seconds')->default(0);
                $table->timestamps();
                $table->unique(['platform_id', 'day'], 'dbscan_budgets_platform_day');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'db_scan_daily_budgets', 'db_scan_audit_events', 'db_scan_suppressions', 'db_scan_finding_events',
            'db_scan_observations', 'db_scan_findings', 'db_scan_snapshots', 'db_scan_events',
            'db_scan_rule_coverage', 'db_scan_surface_coverage', 'db_scan_chunks', 'db_scan_outbox',
            'db_scan_slots', 'db_scan_admissions', 'db_scan_market_runs', 'db_scan_passes', 'db_scan_sweeps',
            'db_scan_occurrences', 'db_scan_schedules', 'db_scan_config_versions', 'db_scan_lists',
            'db_scan_rule_overrides', 'db_scan_rule_versions', 'db_scan_rules', 'db_scan_connections',
            'db_scan_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
