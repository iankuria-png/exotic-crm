<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('label');
            $t->char('country', 2);
            $t->string('provider_key', 64);
            $t->string('account_kind', 32);
            $t->text('account_identifier');
            $t->char('account_fingerprint', 64);
            $t->char('currency', 3);
            $t->string('timezone', 64);
            $t->string('ownership', 32);
            $t->unsignedBigInteger('custodian_id')->nullable();
            $t->string('mode', 16)->default('off');
            $t->json('policy')->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->dateTime('suspended_at')->nullable();
            $t->string('suspension_reason', 80)->nullable();
            $t->timestamps();
            $t->unique(['country', 'provider_key', 'account_fingerprint'], 'collection_account_scope');
        });
        Schema::create('collection_account_platforms', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('account_id')->constrained('collection_accounts')->restrictOnDelete();
            $t->unsignedBigInteger('platform_id');
            $t->unsignedBigInteger('manual_method_id')->nullable();
            $t->json('destination_snapshot');
            $t->dateTime('valid_from');
            $t->dateTime('valid_until')->nullable();
            $t->index(['account_id', 'platform_id']);
        });
        Schema::create('collection_devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('label');
            $t->string('external_device_id', 128)->unique();
            $t->string('key_id', 128)->unique();
            $t->text('signing_secret');
            $t->dateTime('revoked_at')->nullable();
            $t->dateTime('cutover_at')->nullable();
            $t->text('inbox_cursor')->nullable();
            $t->json('acceptance')->nullable();
            $t->dateTime('last_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('collection_sim_bindings', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('device_id')->constrained('collection_devices')->restrictOnDelete();
            $t->foreignUuid('account_id')->constrained('collection_accounts')->restrictOnDelete();
            $t->integer('sim_number');
            $t->char('recipient_fingerprint', 64);
            $t->char('iccid_fingerprint', 64)->nullable();
            $t->string('carrier_name')->nullable();
            $t->dateTime('valid_from');
            $t->dateTime('valid_until')->nullable();
            $t->index(['device_id', 'sim_number']);
        });
        Schema::create('collection_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('account_id')->constrained('collection_accounts')->restrictOnDelete();
            $t->string('reference_raw', 128);
            $t->string('reference_norm', 128);
            $t->bigInteger('principal_minor')->nullable();
            $t->char('currency', 3)->nullable();
            $t->text('payer_phone')->nullable();
            $t->char('payer_fingerprint', 64)->nullable()->index();
            $t->dateTime('provider_at')->nullable();
            $t->dateTime('provider_latest_at')->nullable();
            $t->dateTime('first_received_at')->nullable();
            $t->dateTime('last_received_at')->nullable();
            $t->dateTime('enrichment_deadline');
            $t->boolean('historical_only')->default(true);
            $t->string('funds_state', 32)->default('observed');
            $t->string('evidence_level', 32)->default('phone');
            $t->string('decision_state', 32)->default('collecting');
            $t->json('field_provenance')->nullable();
            $t->json('conflicts')->nullable();
            $t->json('candidate_snapshot')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('reviewed_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamps();
            // Currency is deliberately absent: disagreement quarantines one receipt.
            $t->unique(['account_id', 'reference_norm'], 'collection_receipt_identity');
            $t->index(['decision_state', 'enrichment_deadline']);
        });
        Schema::create('collection_receipt_aliases', function (Blueprint $t) {
            $t->id();
            $t->uuid('account_id');
            $t->unsignedBigInteger('receipt_id');
            $t->string('reference', 128)->collation('utf8mb4_bin');
            $t->string('evidence_reference', 191);
            $t->unsignedBigInteger('reviewed_by');
            $t->dateTime('created_at');
            $t->unique(['account_id', 'reference']);
        });
        Schema::create('collection_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('device_id')->constrained('collection_devices')->restrictOnDelete();
            $t->string('external_event_id', 128)->collation('utf8mb4_bin');
            $t->string('event_kind', 64);
            $t->string('source_kind', 16);
            $t->uuid('account_id')->nullable()->index();
            $t->unsignedBigInteger('receipt_id')->nullable()->index();
            $t->text('raw_body');
            $t->char('raw_sha256', 64);
            $t->text('normalized')->nullable();
            $t->string('parse_version', 64)->nullable();
            $t->string('state', 32)->default('unparsed');
            $t->string('quarantine_reason', 80)->nullable();
            $t->dateTime('sms_received_at')->nullable();
            $t->dateTime('ingested_at');
            $t->unique(['device_id', 'external_event_id'], 'collection_transport_identity');
        });
        Schema::create('collection_event_conflicts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('event_id');
            $t->char('raw_sha256', 64);
            $t->text('raw_body');
            $t->dateTime('created_at');
            $t->unique(['event_id', 'raw_sha256']);
        });
        Schema::create('collection_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 32);
            $t->string('subject_id', 128);
            $t->string('dedupe_key', 191)->unique();
            $t->dateTime('available_at');
            $t->dateTime('completed_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error_code', 80)->nullable();
            $t->index(['completed_at', 'available_at']);
        });
        Schema::create('collection_payer_links', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id')->index();
            $t->char('payer_fingerprint', 64)->index();
            $t->text('payer_phone');
            $t->unsignedBigInteger('client_id')->index();
            $t->unsignedBigInteger('wp_post_id');
            $t->unsignedBigInteger('wp_user_id');
            $t->boolean('auto_allowed')->default(false);
            $t->json('evidence_receipt_ids');
            $t->unsignedBigInteger('reviewed_by');
            $t->dateTime('reviewed_at');
            $t->dateTime('valid_until');
            $t->dateTime('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('collection_purchase_intents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id')->index();
            $t->uuid('payer_link_id')->nullable();
            $t->unsignedBigInteger('client_id')->index();
            $t->unsignedBigInteger('platform_id');
            $t->string('authority', 32);
            $t->string('provider_reference', 128)->nullable();
            $t->json('purchase_snapshot');
            $t->unsignedBigInteger('deal_id')->nullable();
            $t->unsignedBigInteger('payment_id')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('valid_from');
            $t->dateTime('valid_until');
            $t->dateTime('revoked_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('used_count')->default(0);
            $t->unsignedInteger('max_renewals')->default(1);
            $t->timestamps();
        });
        Schema::create('collection_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('receipt_id')->unique();
            $t->uuid('intent_id');
            $t->unsignedInteger('intent_version');
            $t->unsignedBigInteger('payment_id')->nullable()->unique();
            $t->uuid('operation_id')->nullable()->unique();
            $t->json('purchase_snapshot');
            $t->string('state', 32)->default('reserved');
            $t->string('decision_source', 32);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('collection_benefit_effects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('parent_operation_id')->index();
            $t->uuid('allocation_id')->index();
            $t->uuid('correction_id')->nullable()->index();
            $t->string('effect_kind', 32);
            $t->unsignedBigInteger('platform_id');
            $t->unsignedBigInteger('client_id')->index();
            $t->unsignedBigInteger('wp_post_id');
            $t->unsignedBigInteger('wp_user_id');
            $t->unsignedBigInteger('deal_id')->nullable();
            $t->json('before_state');
            $t->json('contribution');
            $t->json('target_state');
            $t->json('verified_state')->nullable();
            $t->string('state', 32)->default('prepared');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error_code', 80)->nullable();
            $t->dateTime('dispatch_started_at')->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->dateTime('superseded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('collection_profile_holds', function (Blueprint $t) {
            $t->unsignedBigInteger('client_id')->primary();
            $t->uuid('allocation_id');
            $t->uuid('operation_id');
            $t->dateTime('created_at');
        });
        Schema::create('collection_exposure_buckets', function (Blueprint $t) {
            $t->id();
            $t->uuid('account_id');
            $t->char('currency', 3);
            $t->string('bucket', 16);
            $t->string('timezone', 64);
            $t->bigInteger('daily_minor')->default(0);
            $t->bigInteger('unreviewed_minor')->default(0);
            $t->bigInteger('unverified_minor')->default(0);
            $t->unique(['account_id', 'currency', 'bucket'], 'collection_exposure_scope');
        });
        Schema::create('collection_exposure_reservations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('receipt_id')->unique();
            $t->uuid('account_id')->index();
            $t->uuid('allocation_id')->unique();
            $t->char('currency', 3);
            $t->bigInteger('amount_minor');
            $t->date('accounting_date');
            $t->string('timezone', 64);
            $t->unsignedInteger('policy_version');
            $t->boolean('review_released')->default(false);
            $t->boolean('verification_released')->default(false);
            $t->dateTime('created_at');
        });
        Schema::create('collection_corrections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('allocation_id')->index();
            $t->unsignedInteger('allocation_version');
            $t->string('action', 32);
            $t->uuid('target_intent_id')->nullable();
            $t->unsignedBigInteger('actor_id');
            $t->text('reason');
            $t->json('preview');
            $t->string('request_key', 128)->nullable()->unique();
            $t->string('state', 32)->default('previewed');
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('expires_at');
            $t->timestamps();
        });
        Schema::create('collection_audit', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('receipt_id')->nullable()->index();
            $t->uuid('account_id')->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 80);
            $t->json('context');
            $t->string('dedupe_key', 191)->nullable()->unique();
            $t->dateTime('created_at');
        });
        Schema::create('collection_settlement_entries', function (Blueprint $t) {
            $t->id();
            $t->uuid('account_id')->index();
            $t->unsignedBigInteger('receipt_id')->nullable();
            $t->char('currency', 3);
            $t->string('kind', 32);
            $t->bigInteger('amount_minor');
            $t->string('evidence_reference', 191);
            $t->char('artifact_sha256', 64)->nullable();
            $t->string('dedupe_key', 191)->unique();
            $t->unique(['account_id', 'kind', 'evidence_reference'], 'collection_settlement_evidence_once');
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('posted_at');
            $t->dateTime('created_at');
        });
        Schema::create('collection_read_cursors', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary();
            $t->unsignedBigInteger('last_receipt_id')->default(0);
            $t->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        // Financial evidence is deliberately retained. Restore a pre-migration backup
        // on an isolated copy; never erase the collection ledger as a rollback.
        throw new RuntimeException('Collection evidence is retained: disable gates instead of dropping financial tables.');
    }
};
