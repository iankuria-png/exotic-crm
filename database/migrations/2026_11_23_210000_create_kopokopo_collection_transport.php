<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_provider_feeds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('account_id')->unique()->constrained('collection_accounts')->restrictOnDelete();
            $t->foreignId('provider_profile_id')->constrained('billing_provider_profiles')->restrictOnDelete();
            $t->string('till_number', 32);
            $t->boolean('enabled')->default(true);
            $t->dateTime('cutover_at');
            $t->json('subscriptions')->nullable();
            $t->dateTime('last_delivery_at')->nullable();
            $t->dateTime('reconciled_until')->nullable();
            $t->dateTime('last_poll_completed_at')->nullable();
            $t->string('last_error_code', 100)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['provider_profile_id', 'till_number']);
        });
        Schema::create('collection_provider_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('feed_id')->constrained('collection_provider_feeds')->restrictOnDelete();
            $t->foreignId('receipt_id')->nullable()->constrained('collection_receipts')->restrictOnDelete();
            $t->string('event_key', 191);
            $t->string('source_kind', 24);
            $t->string('topic', 80);
            $t->string('state', 24)->default('accepted');
            $t->longText('raw_body');
            $t->char('raw_sha256', 64);
            $t->text('observation')->nullable();
            $t->string('quarantine_reason', 100)->nullable();
            $t->dateTime('received_at');
            $t->unique(['feed_id', 'event_key']);
            $t->index(['feed_id', 'state']);
        });
        Schema::create('collection_provider_resources', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('feed_id')->constrained('collection_provider_feeds')->restrictOnDelete();
            $t->foreignId('receipt_id')->unique()->constrained('collection_receipts')->restrictOnDelete();
            $t->string('reference_norm', 128);
            $t->string('provider_resource_id', 128)->nullable();
            $t->text('sender_hash')->nullable();
            $t->string('identity_kind', 16)->default('absent');
            $t->unique(['feed_id', 'reference_norm']);
            $t->unique(['feed_id', 'provider_resource_id'], 'collection_provider_resource_identity_unique');
        });
        Schema::create('collection_provider_polls', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('feed_id')->constrained('collection_provider_feeds')->restrictOnDelete();
            $t->dateTime('from_at');
            $t->dateTime('until_at');
            $t->string('provider_id', 128)->nullable();
            $t->string('location', 512)->nullable();
            $t->string('state', 24)->default('requesting');
            $t->boolean('historical_only')->default(false);
            $t->char('result_sha256', 64)->nullable();
            $t->longText('pending_result')->nullable();
            $t->string('last_error_code', 100)->nullable();
            $t->timestamps();
            $t->index(['feed_id', 'state']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('collection_provider_feeds') && DB::table('collection_provider_feeds')->exists()) {
            throw new RuntimeException('Provider evidence must be retained. Disable KOPOKOPO_COLLECTION_ENABLED and restore application code; do not remove transport tables.');
        }
        foreach (['collection_provider_polls', 'collection_provider_resources', 'collection_provider_events', 'collection_provider_feeds'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
