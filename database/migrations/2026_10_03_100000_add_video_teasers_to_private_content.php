<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Resumable like the earlier monetization migrations: each column is added only if missing. */
    public function up(): void
    {
        $columns = [
            'premium_content_assets' => [
                'teaser_url' => fn (Blueprint $t) => $t->text('teaser_url')->nullable(),
                'teaser_strength' => fn (Blueprint $t) => $t->string('teaser_strength', 16)->nullable(),
                'teaser_generated_at' => fn (Blueprint $t) => $t->timestamp('teaser_generated_at')->nullable(),
            ],
            'content_monetization_settings' => [
                'teaser_policy_json' => fn (Blueprint $t) => $t->json('teaser_policy_json')->nullable(),
            ],
            'monetization_automation_items' => [
                'asset_id' => fn (Blueprint $t) => $t->unsignedBigInteger('asset_id')->nullable()->index('monetize_items_asset'),
            ],
        ];
        foreach ($columns as $table => $defines) {
            foreach ($defines as $column => $define) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, $define);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['teaser_url', 'teaser_strength', 'teaser_generated_at'] as $column) {
            Schema::table('premium_content_assets', fn (Blueprint $t) => $t->dropColumn($column));
        }
        Schema::table('content_monetization_settings', fn (Blueprint $t) => $t->dropColumn('teaser_policy_json'));
        Schema::table('monetization_automation_items', fn (Blueprint $t) => $t->dropIndex('monetize_items_asset'));
        Schema::table('monetization_automation_items', fn (Blueprint $t) => $t->dropColumn('asset_id'));
    }
};
