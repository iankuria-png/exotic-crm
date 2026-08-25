<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_contact_unlocks', function (Blueprint $table): void {
            $table->decimal('gross_amount', 12, 2)->nullable()->after('status');
            $table->decimal('credit_amount', 12, 2)->default(0)->after('gross_amount');
            $table->decimal('amount_due', 12, 2)->nullable()->after('credit_amount');
            $table->foreignId('upgraded_from_unlock_id')
                ->nullable()
                ->after('public_token_hash')
                ->constrained('visitor_contact_unlocks')
                ->nullOnDelete();
            $table->foreignId('credit_reserved_for_unlock_id')
                ->nullable()
                ->after('upgraded_from_unlock_id')
                ->constrained('visitor_contact_unlocks')
                ->nullOnDelete();
            $table->timestamp('credit_reserved_until')->nullable()->after('credit_reserved_for_unlock_id');
            $table->foreignId('credited_to_upgrade_unlock_id')
                ->nullable()
                ->after('credit_reserved_until')
                ->constrained('visitor_contact_unlocks')
                ->nullOnDelete();
            $table->timestamp('credit_applied_at')->nullable()->after('credited_to_upgrade_unlock_id');

            $table->index(['platform_id', 'scope', 'status', 'created_at'], 'visitor_unlocks_platform_scope_status_created_idx');
            $table->index(['visitor_phone_hash', 'status', 'created_at'], 'visitor_unlocks_phone_status_created_idx');
            $table->index(['credit_reserved_until'], 'visitor_unlocks_credit_reserved_until_idx');
            $table->index(['credited_to_upgrade_unlock_id'], 'visitor_unlocks_credited_to_upgrade_idx');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_contact_unlocks', function (Blueprint $table): void {
            $table->dropIndex('visitor_unlocks_platform_scope_status_created_idx');
            $table->dropIndex('visitor_unlocks_phone_status_created_idx');
            $table->dropIndex('visitor_unlocks_credit_reserved_until_idx');
            $table->dropIndex('visitor_unlocks_credited_to_upgrade_idx');

            $table->dropConstrainedForeignId('upgraded_from_unlock_id');
            $table->dropConstrainedForeignId('credit_reserved_for_unlock_id');
            $table->dropConstrainedForeignId('credited_to_upgrade_unlock_id');
            $table->dropColumn([
                'gross_amount',
                'credit_amount',
                'amount_due',
                'credit_reserved_until',
                'credit_applied_at',
            ]);
        });
    }
};
