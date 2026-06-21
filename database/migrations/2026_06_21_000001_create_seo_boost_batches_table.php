<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_boost_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_price_id')->nullable()->constrained('product_prices')->nullOnDelete();
            $table->string('plan_type', 30)->nullable();
            $table->unsignedSmallInteger('duration_days');
            $table->string('borrow_mode', 20)->default('widen');
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('selected_count')->default(0);
            $table->unsignedInteger('activated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('expired_count')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
            $table->index(['created_by', 'created_at']);
        });

        Schema::create('seo_boost_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('seo_boost_batches')->cascadeOnDelete();
            $table->string('canonical_key', 160);
            $table->string('display_city', 160);
            $table->unsignedSmallInteger('target_count');
            $table->unsignedSmallInteger('selected_count')->default(0);
            $table->unsignedSmallInteger('activated_count')->default(0);
            $table->json('borrowed_from')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'canonical_key']);
        });

        Schema::create('seo_boost_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('seo_boost_batches')->cascadeOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('seo_boost_targets')->nullOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->string('source', 24)->default('in_region');
            $table->string('canonical_key', 160);
            $table->string('display_city', 160)->nullable();
            $table->unsignedSmallInteger('rank')->default(0);
            $table->unsignedSmallInteger('quality_score')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->string('status', 24)->default('selected');
            $table->text('failure_reason')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'client_id']);
            $table->index(['deal_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        Schema::table('deals', function (Blueprint $table) {
            if (!Schema::hasColumn('deals', 'seo_boost_batch_id')) {
                $table->foreignId('seo_boost_batch_id')
                    ->nullable()
                    ->after('origin')
                    ->constrained('seo_boost_batches')
                    ->nullOnDelete();
                $table->index(['seo_boost_batch_id', 'status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            if (Schema::hasColumn('deals', 'seo_boost_batch_id')) {
                $table->dropIndex(['seo_boost_batch_id', 'status']);
                $table->dropConstrainedForeignId('seo_boost_batch_id');
            }
        });

        Schema::dropIfExists('seo_boost_items');
        Schema::dropIfExists('seo_boost_targets');
        Schema::dropIfExists('seo_boost_batches');
    }
};
