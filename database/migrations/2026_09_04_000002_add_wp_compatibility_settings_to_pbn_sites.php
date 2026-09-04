<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors platforms.wp_compatibility_settings so a PBN site can declare the
     * same WordPress quirks a market can.
     *
     * The first setting is legacy_self_upload_secret_option. The parent theme's
     * photo and video uploaders resolve a profile solely through an option
     * named by that profile's `secret` post meta; without the row they die with
     * "We couldn't find a profile" before any authorship or nonce check runs.
     * Seeded profiles never got the row, so they could not accept media.
     *
     * It defaults to on for PBN sites, which is the opposite of the platform
     * default: a market may run a newer uploader, but a PBN whose profiles
     * cannot take photos has no reason to exist.
     */
    public function up(): void
    {
        Schema::table('pbn_sites', function (Blueprint $table) {
            $table->json('wp_compatibility_settings')->nullable()->after('copy_policy');
        });
    }

    public function down(): void
    {
        Schema::table('pbn_sites', function (Blueprint $table) {
            $table->dropColumn('wp_compatibility_settings');
        });
    }
};
