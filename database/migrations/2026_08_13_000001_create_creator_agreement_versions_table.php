<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_agreement_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version_key', 80)->unique();
            $table->string('title');
            $table->longText('body_html')->nullable();
            $table->string('body_sha256', 64);
            $table->string('source_url', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['published_at', 'retired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_agreement_versions');
    }
};
