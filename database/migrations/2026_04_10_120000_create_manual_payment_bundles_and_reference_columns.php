<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('manual_payment_bundles')) {
            Schema::create('manual_payment_bundles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
                $table->string('reference_root', 255);
                $table->decimal('total_amount', 12, 2);
                $table->decimal('allocated_amount', 12, 2)->default(0);
                $table->decimal('unallocated_amount', 12, 2)->default(0);
                $table->string('currency', 10)->default('KES');
                $table->string('reason', 500)->nullable();
                $table->string('status', 50);
                $table->string('audit_state', 50);
                $table->string('idempotency_key', 191);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['platform_id', 'reference_root'], 'manual_payment_bundles_platform_reference_root_unique');
                $table->unique('idempotency_key', 'manual_payment_bundles_idempotency_key_unique');
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'manual_payment_bundle_id')) {
                $table->foreignId('manual_payment_bundle_id')
                    ->nullable()
                    ->after('escort_post_id')
                    ->constrained('manual_payment_bundles')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('payments', 'reference_root')) {
                $table->string('reference_root', 255)->nullable()->after('reference_number');
                $table->index(['platform_id', 'reference_root'], 'payments_platform_reference_root_idx');
            }

            if (!Schema::hasColumn('payments', 'reference_sequence')) {
                $table->unsignedInteger('reference_sequence')->nullable()->after('reference_root');
                $table->unique(
                    ['manual_payment_bundle_id', 'reference_sequence'],
                    'payments_bundle_reference_sequence_unique'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'reference_sequence')) {
                $table->dropUnique('payments_bundle_reference_sequence_unique');
                $table->dropColumn('reference_sequence');
            }

            if (Schema::hasColumn('payments', 'reference_root')) {
                $table->dropIndex('payments_platform_reference_root_idx');
                $table->dropColumn('reference_root');
            }

            if (Schema::hasColumn('payments', 'manual_payment_bundle_id')) {
                $table->dropConstrainedForeignId('manual_payment_bundle_id');
            }
        });

        Schema::dropIfExists('manual_payment_bundles');
    }
};
