<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('kyc_required')->default(true)->after('verified');
            $table->enum('verified_source', ['kyc', 'manual_wp', 'manual_crm_emergency'])->nullable()->after('kyc_required');
            $table->timestamp('verified_source_at')->nullable()->after('verified_source');
            $table->foreignId('verified_source_actor_id')->nullable()->after('verified_source_at')->constrained('users')->nullOnDelete();
            $table->text('verified_source_reason')->nullable()->after('verified_source_actor_id');

            $table->index(['kyc_required', 'verified']);
            $table->index(['verified_source']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_source_actor_id');
            $table->dropIndex(['kyc_required', 'verified']);
            $table->dropIndex(['verified_source']);
            $table->dropColumn([
                'kyc_required',
                'verified_source',
                'verified_source_at',
                'verified_source_reason',
            ]);
        });
    }
};
