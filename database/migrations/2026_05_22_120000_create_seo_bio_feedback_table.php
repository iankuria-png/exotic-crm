<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback log for generated bios.
 *
 * Each row records what the user thought of one specific bio draft:
 *   - rating (-1 thumbs down / 0 neutral / 1 thumbs up)
 *   - tag (one of: too_long, too_short, too_generic, off_tone, repetitive, missing_contact, perfect, other)
 *   - comment (free text)
 *   - accepted (did the user click "Use this bio"?)
 *   - provider_used, generation_options, bio_html (so we can replay context if we want to analyse later)
 *
 * Recent feedback per platform is injected into the system prompt as
 * "Editor preferences learned from past feedback" so the LLM iterates toward
 * what the editors actually like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_bio_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider_used', 40)->nullable();
            $table->tinyInteger('rating')->default(0); // -1, 0, 1
            $table->string('tag', 40)->nullable();
            $table->text('comment')->nullable();
            $table->boolean('accepted')->default(false);
            $table->unsignedSmallInteger('score')->nullable();
            $table->json('generation_options')->nullable();
            $table->mediumText('bio_html')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'created_at']);
            $table->index(['client_id', 'created_at']);
            $table->index(['rating', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_bio_feedback');
    }
};
