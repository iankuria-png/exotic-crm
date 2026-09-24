<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_slug_alias_repair_runs', function (Blueprint $table) {
            // The URLs an admin chose ({post_type, slug}); null repairs the whole market.
            $table->json('targets')->nullable()->after('audit_summary');
        });
    }

    public function down(): void
    {
        Schema::table('profile_slug_alias_repair_runs', function (Blueprint $table) {
            $table->dropColumn('targets');
        });
    }
};
