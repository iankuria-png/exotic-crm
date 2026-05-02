<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('crm_page')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['crm_page', 'position']);
        });

        Schema::create('faq_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('faq_categories')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->longText('body_draft')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('view_count')->default(0);
            $table->unsignedBigInteger('helpful_count')->default(0);
            $table->unsignedBigInteger('unhelpful_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'status', 'position']);
            $table->index(['status', 'published_at']);

            if (DB::connection()->getDriverName() === 'mysql') {
                $table->fullText(['title', 'summary', 'body']);
            }
        });

        Schema::create('faq_article_ctas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('faq_articles')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('kind', 30);
            $table->string('label');
            $table->string('target_path')->nullable();
            $table->json('prefill_payload')->nullable();
            $table->string('walkthrough_id')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'position']);
        });

        Schema::create('faq_article_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('faq_articles')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('disk_path');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('caption')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['article_id', 'position']);
        });

        Schema::create('faq_walkthroughs', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->json('steps');
            $table->timestamps();
        });

        Schema::create('faq_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained('faq_articles')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->boolean('helpful')->nullable();
            $table->string('title')->nullable();
            $table->text('comment')->nullable();
            $table->string('severity', 20)->nullable();
            $table->string('context_path')->nullable();
            $table->json('context_meta')->nullable();
            $table->string('screenshot_disk_path')->nullable();
            $table->string('status', 30)->default('new');
            $table->foreignId('duplicate_of_id')->nullable()->constrained('faq_feedback')->nullOnDelete();
            $table->longText('admin_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('status_history')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['kind', 'status']);
            $table->index(['article_id', 'status']);
            $table->index(['status_changed_at']);
        });

        Schema::create('faq_feedback_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->constrained('faq_feedback')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['feedback_id', 'user_id']);
            $table->index('feedback_id');
        });

        Schema::create('faq_feedback_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->constrained('faq_feedback')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index('feedback_id');
        });

        Schema::create('faq_search_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('query');
            $table->unsignedInteger('result_count')->default(0);
            $table->foreignId('clicked_article_id')->nullable()->constrained('faq_articles')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('clicked_article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_search_log');
        Schema::dropIfExists('faq_feedback_comments');
        Schema::dropIfExists('faq_feedback_votes');
        Schema::dropIfExists('faq_feedback');
        Schema::dropIfExists('faq_walkthroughs');
        Schema::dropIfExists('faq_article_media');
        Schema::dropIfExists('faq_article_ctas');
        Schema::dropIfExists('faq_articles');
        Schema::dropIfExists('faq_categories');
    }
};
