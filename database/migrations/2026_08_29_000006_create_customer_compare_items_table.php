<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members of the active compare set. Capped at four by the service, which is a
 * product rule rather than a schema one, so the fifth profile is rejected with
 * a message the tray can show instead of a constraint violation.
 *
 * `customer_account_id` and `platform_id` are denormalised from the set header
 * so the account-deletion cascade and any platform-scoped query can reach items
 * without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_compare_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compare_set_id')->constrained('customer_compare_sets')->cascadeOnDelete();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('object_type', 40)->default('profile');
            $table->unsignedBigInteger('object_ref');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('added_at');
            $table->timestamps();

            $table->unique(
                ['compare_set_id', 'object_type', 'object_ref'],
                'customer_compare_items_unique'
            );
            $table->index(['compare_set_id', 'position'], 'customer_compare_items_order_idx');
            $table->index(['customer_account_id'], 'customer_compare_items_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_compare_items');
    }
};
