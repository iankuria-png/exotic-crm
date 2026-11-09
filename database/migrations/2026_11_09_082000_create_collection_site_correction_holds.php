<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_site_correction_holds', function (Blueprint $t) {
            $t->id();
            $t->uuid('correction_id');
            $t->unsignedBigInteger('platform_id');
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('wp_post_id');
            $t->uuid('source_operation_id')->nullable();
            $t->unsignedInteger('generation')->nullable();
            $t->string('expected_fingerprint', 64);
            $t->string('state', 24)->default('reserved');
            $t->timestamps();
            $t->unique(['correction_id', 'platform_id', 'wp_post_id'], 'collection_correction_site_identity');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain correction ownership journals and recover saved owners before changing code.');
    }
};
