<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_log_groups', function (Blueprint $table) {
            $table->id();
            $table->char('signature', 40)->unique();
            $table->enum('level', ['error', 'critical', 'alert', 'emergency'])->default('error');
            $table->string('exception_class', 255)->nullable();
            $table->text('message');
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            // 'client' (browser-origin errors) is widened onto already-deployed
            // databases by a later migration; it is included here so fresh
            // installs (and the SQLite test DB, where enum compiles to a CHECK
            // constraint) accept it from the start.
            $table->enum('source', ['exception', 'log', 'queue_job', 'client'])->default('exception');
            $table->unsignedBigInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('last_occurrence_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
            $table->index(['level', 'last_seen_at']);
            $table->index('resolved_at');
            $table->index('source');
        });

        Schema::create('error_log_occurrences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->timestamp('occurred_at');
            $table->longText('trace')->nullable();
            $table->json('context')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->unsignedBigInteger('platform_id')->nullable();

            $table->index(['group_id', 'occurred_at']);
            $table->index('occurred_at');

            $table->foreign('group_id')->references('id')->on('error_log_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_log_occurrences');
        Schema::dropIfExists('error_log_groups');
    }
};
