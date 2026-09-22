<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_staff_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('agent_alias', 16)->unique();
            $table->boolean('active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mcp_oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_id')->unique();
            $table->string('client_name', 120);
            $table->json('redirect_uris');
            $table->json('grant_abilities')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('mcp_oauth_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->foreignId('client_id')->constrained('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('redirect_uri', 2048);
            $table->string('code_challenge', 128);
            $table->string('code_challenge_method', 10)->default('S256');
            $table->json('abilities');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_oauth_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('client_id')->constrained('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->json('abilities');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_token_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('token_id')->unique();
            $table->unsignedInteger('grant_version')->default(1);
            $table->json('tools')->nullable();
            $table->json('resources')->nullable();
            $table->json('prompts')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_token_grants');
        Schema::dropIfExists('mcp_oauth_refresh_tokens');
        Schema::dropIfExists('mcp_oauth_authorization_codes');
        Schema::dropIfExists('mcp_oauth_clients');
        Schema::dropIfExists('mcp_staff_aliases');
    }
};
