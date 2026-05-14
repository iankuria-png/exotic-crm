<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('university_courses', function (Blueprint $table) {
            $table->string('difficulty', 30)->nullable()->after('summary');
            $table->json('learning_outcomes')->nullable()->after('difficulty');
            $table->string('instructor_name')->nullable()->after('learning_outcomes');
            $table->string('accent_color', 30)->nullable()->after('instructor_name');
            $table->unsignedInteger('estimated_minutes')->nullable()->after('accent_color');
        });

        Schema::table('university_lessons', function (Blueprint $table) {
            $table->string('playbook_url')->nullable()->after('body_draft');
            $table->longText('quick_reference')->nullable()->after('playbook_url');
            $table->string('kind', 30)->default('lesson')->after('quick_reference');
            $table->string('subtitle')->nullable()->after('title');
        });

        Schema::create('university_glossary_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term');
            $table->string('slug')->unique();
            $table->longText('definition');
            $table->json('aliases')->nullable();
            $table->string('topic_tag')->nullable();
            $table->string('playbook_url')->nullable();
            $table->timestamps();

            $table->index('term');
        });

        Schema::create('university_lesson_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('university_lessons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->tinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'user_id']);
            $table->index(['lesson_id', 'rating']);
        });

        Schema::create('university_badges', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('color', 30)->nullable();
            $table->string('criteria_kind', 60);
            $table->json('criteria_config')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->timestamps();
        });

        Schema::create('university_user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('university_badges')->cascadeOnDelete();
            $table->timestamp('earned_at');
            $table->timestamps();

            $table->unique(['user_id', 'badge_id']);
            $table->index(['user_id', 'earned_at']);
        });

        Schema::create('university_streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_active_on')->nullable();
            $table->timestamps();
        });

        Schema::create('university_daily_drills', function (Blueprint $table) {
            $table->id();
            $table->longText('prompt');
            $table->longText('scenario_context')->nullable();
            $table->longText('explanation')->nullable();
            $table->json('options');
            $table->unsignedTinyInteger('correct_index');
            $table->string('topic_tag')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('university_drill_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('drill_id')->constrained('university_daily_drills')->cascadeOnDelete();
            $table->date('completed_on');
            $table->boolean('correct')->default(false);
            $table->unsignedTinyInteger('selected_index')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'completed_on']);
            $table->index(['user_id', 'completed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('university_drill_completions');
        Schema::dropIfExists('university_daily_drills');
        Schema::dropIfExists('university_streaks');
        Schema::dropIfExists('university_user_badges');
        Schema::dropIfExists('university_badges');
        Schema::dropIfExists('university_lesson_feedback');
        Schema::dropIfExists('university_glossary_terms');

        Schema::table('university_lessons', function (Blueprint $table) {
            $table->dropColumn(['playbook_url', 'quick_reference', 'kind', 'subtitle']);
        });

        Schema::table('university_courses', function (Blueprint $table) {
            $table->dropColumn(['difficulty', 'learning_outcomes', 'instructor_name', 'accent_color', 'estimated_minutes']);
        });
    }
};
