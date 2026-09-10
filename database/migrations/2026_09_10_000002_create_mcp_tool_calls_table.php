<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('tool', 64)->index();
            $table->json('argument_summary')->nullable();
            $table->char('generated_sql_sha256', 64)->nullable();
            $table->text('generated_sql_redacted')->nullable();
            $table->json('platform_scope')->nullable();
            $table->string('status', 16)->index();
            $table->string('refusal_reason', 64)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('bytes_out')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
    }
};
