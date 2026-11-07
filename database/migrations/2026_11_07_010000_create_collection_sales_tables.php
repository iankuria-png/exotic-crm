<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_sales_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('receipt_id')->unique()->constrained('collection_receipts')->restrictOnDelete();
            $t->foreignUuid('account_id')->constrained('collection_accounts')->restrictOnDelete();
            $t->foreignId('platform_id')->constrained('platforms')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('bundle_id')->nullable()->unique()->constrained('manual_payment_bundles')->restrictOnDelete();
            $t->uuid('active_item_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('receipt_version');
            $t->string('state', 32)->default('draft')->index();
            $t->char('currency', 3);
            $t->bigInteger('principal_minor');
            $t->bigInteger('allocated_minor');
            $t->bigInteger('remainder_minor')->default(0);
            $t->text('remainder_reason')->nullable();
            $t->uuid('request_key')->nullable()->unique();
            $t->char('payload_hash', 64)->nullable();
            $t->string('last_error_code', 80)->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('collection_sales_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('session_id')->constrained('collection_sales_sessions')->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $t->foreignId('deal_id')->nullable()->unique()->constrained('deals')->restrictOnDelete();
            $t->foreignId('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $t->uuid('operation_key')->unique();
            $t->bigInteger('amount_minor');
            $t->unsignedInteger('version')->default(1);
            $t->string('state', 32)->default('draft')->index();
            $t->json('purchase');
            $t->text('subsidiary')->nullable();
            $t->json('expected_site')->nullable();
            $t->json('operation_result')->nullable();
            $t->timestamp('dispatch_started_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('last_error_code', 80)->nullable();
            $t->timestamps();
            $t->unique(['session_id', 'client_id']);
            $t->unique(['session_id', 'position']);
        });
        Schema::create('collection_sales_profile_holds', function (Blueprint $t) {
            $t->foreignId('client_id')->primary()->constrained('clients')->restrictOnDelete();
            $t->foreignUuid('session_id')->constrained('collection_sales_sessions')->restrictOnDelete();
            $t->foreignUuid('item_id')->constrained('collection_sales_items')->restrictOnDelete();
            $t->uuid('operation_key');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('collection_sales_sessions') && \Illuminate\Support\Facades\DB::table('collection_sales_sessions')->where('state', '!=', 'draft')->exists()) {
            throw new \RuntimeException('Receipt sale records must be retained after preparation. Disable dispatch instead of dropping ownership.');
        }
        Schema::dropIfExists('collection_sales_profile_holds');
        Schema::dropIfExists('collection_sales_items');
        Schema::dropIfExists('collection_sales_sessions');
    }
};
