<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bio contact-scrub bookkeeping. When a profile becomes lifecycle-restricted
     * the CRM redacts contact details from its WordPress bio; the untouched
     * original is kept here so a renewal restores it verbatim.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->longText('bio_original_html')->nullable()->after('lifecycle_archived_at');
            $table->timestamp('bio_scrubbed_at')->nullable()->after('bio_original_html');
            $table->unsignedSmallInteger('bio_redactions')->nullable()->after('bio_scrubbed_at');
            $table->index('bio_scrubbed_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['bio_scrubbed_at']);
            $table->dropColumn(['bio_original_html', 'bio_scrubbed_at', 'bio_redactions']);
        });
    }
};
