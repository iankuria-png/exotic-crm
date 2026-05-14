<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('university_courses', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('visibility', 30)->default('all');
            $table->json('required_for_roles')->nullable();
            $table->foreignId('prerequisite_course_id')->nullable()->constrained('university_courses')->nullOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'visibility', 'order']);
        });

        Schema::create('university_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('university_courses')->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['course_id', 'slug']);
            $table->index(['course_id', 'order']);
        });

        Schema::create('university_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('university_modules')->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->longText('body')->nullable();
            $table->longText('body_draft')->nullable();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->unsignedInteger('order')->default(0);
            $table->string('status', 30)->default('draft');
            $table->timestamps();

            $table->unique(['module_id', 'slug']);
            $table->index(['module_id', 'status', 'order']);
        });

        Schema::create('university_lesson_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('university_lessons')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('disk_path')->nullable();
            $table->string('embed_url')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('caption')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['lesson_id', 'order']);
        });

        Schema::create('university_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('university_lessons')->cascadeOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->unsignedInteger('seconds_spent')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('scroll_y')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
            $table->index(['user_id', 'completed_at']);
        });

        Schema::create('university_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained('university_courses')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('pass_threshold')->default(80);
            $table->unsignedInteger('time_limit_minutes')->default(30);
            $table->unsignedInteger('question_count')->default(25);
            $table->unsignedInteger('max_attempts_per_window')->default(3);
            $table->unsignedInteger('attempt_window_days')->default(30);
            $table->unsignedInteger('validity_months')->default(12);
            $table->boolean('randomize_questions')->default(true);
            $table->boolean('randomize_options')->default(true);
            $table->boolean('show_explanations_on_fail')->default(false);
            $table->boolean('allow_review_before_submit')->default(true);
            $table->string('cert_template_id')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();

            $table->index(['course_id', 'status']);
        });

        Schema::create('university_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certification_id')->constrained('university_certifications')->cascadeOnDelete();
            $table->string('kind', 30)->default('mcq');
            $table->longText('prompt');
            $table->longText('scenario_context')->nullable();
            $table->longText('explanation')->nullable();
            $table->string('topic_tag')->nullable();
            $table->unsignedInteger('weight')->default(1);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['certification_id', 'topic_tag']);
        });

        Schema::create('university_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('university_questions')->cascadeOnDelete();
            $table->longText('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'order']);
        });

        Schema::create('university_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('certification_id')->constrained('university_certifications')->cascadeOnDelete();
            $table->json('question_order')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('score_pct', 5, 2)->nullable();
            $table->boolean('passed')->default(false);
            $table->json('per_topic_breakdown')->nullable();
            $table->unsignedInteger('time_spent_seconds')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'certification_id']);
            $table->index(['certification_id', 'submitted_at']);
        });

        Schema::create('university_attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('university_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('university_questions')->cascadeOnDelete();
            $table->foreignId('selected_option_id')->nullable()->constrained('university_question_options')->nullOnDelete();
            $table->boolean('is_correct')->default(false);
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('university_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('certification_id')->constrained('university_certifications')->cascadeOnDelete();
            $table->foreignId('attempt_id')->constrained('university_attempts')->cascadeOnDelete();
            $table->string('certificate_code')->unique();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'certification_id']);
            $table->index(['expires_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('university_certificates');
        Schema::dropIfExists('university_attempt_answers');
        Schema::dropIfExists('university_attempts');
        Schema::dropIfExists('university_question_options');
        Schema::dropIfExists('university_questions');
        Schema::dropIfExists('university_certifications');
        Schema::dropIfExists('university_lesson_progress');
        Schema::dropIfExists('university_lesson_media');
        Schema::dropIfExists('university_lessons');
        Schema::dropIfExists('university_modules');
        Schema::dropIfExists('university_courses');
    }
};
