<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_semantic_releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('ontology_version', 32);
            $table->char('ontology_sha256', 64);
            $table->unsignedBigInteger('knowledge_version_id')->nullable()->index();
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->unsignedBigInteger('activated_by')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_knowledge_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 80)->unique();
            $table->enum('status', ['staging', 'validating', 'ready', 'rejected', 'failed'])->default('staging')->index();
            $table->char('manifest_sha256', 64);
            $table->char('content_sha256', 64);
            $table->string('ontology_version', 32);
            $table->char('ontology_sha256', 64);
            $table->json('validation_report')->nullable();
            $table->unsignedBigInteger('promoted_by')->nullable()->index();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('mcp_knowledge_versions')->cascadeOnDelete();
            $table->string('canonical_uri', 255)->index();
            $table->string('source_url', 1024);
            $table->string('title', 255);
            $table->text('summary')->nullable();
            $table->json('audiences')->nullable();
            $table->json('lifecycle_stages')->nullable();
            $table->json('departments')->nullable();
            $table->char('content_sha256', 64);
            $table->string('classification_key', 160);
            $table->timestamps();
            $table->unique(['version_id', 'canonical_uri']);
        });

        Schema::create('mcp_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('mcp_knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->string('heading_path', 500);
            $table->text('body');
            $table->unsignedInteger('token_estimate');
            $table->text('search_text');
            $table->timestamps();
            $table->unique(['document_id', 'ordinal']);
            // SQLite powers the feature suite; it uses the deterministic
            // heading/phrase fallback while MySQL/MariaDB gets FULLTEXT.
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->fullText('search_text');
            }
        });

        Schema::create('mcp_knowledge_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->enum('mode', ['check', 'stage']);
            $table->enum('status', ['queued', 'running', 'complete', 'failed'])->default('queued')->index();
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->unsignedInteger('discovered')->default(0);
            $table->unsignedInteger('eligible')->default(0);
            $table->unsignedInteger('changed')->default(0);
            $table->unsignedInteger('quarantined')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_schema_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('ontology_version', 32);
            $table->char('ontology_sha256', 64);
            $table->char('schema_fingerprint', 64);
            $table->enum('status', ['pass', 'drift', 'failed'])->index();
            $table->json('affected_capabilities')->nullable();
            $table->json('rule_ids')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_daily_usages', function (Blueprint $table) {
            $table->id();
            $table->date('usage_date');
            $table->enum('scope_type', ['server', 'token']);
            $table->unsignedBigInteger('scope_id');
            $table->unsignedBigInteger('rows_out')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->timestamps();
            $table->unique(['usage_date', 'scope_type', 'scope_id']);
        });

        Schema::create('mcp_token_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('token_id')->unique();
            $table->unsignedBigInteger('daily_rows');
            $table->unsignedBigInteger('daily_bytes');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_token_limits');
        Schema::dropIfExists('mcp_daily_usages');
        Schema::dropIfExists('mcp_schema_audits');
        Schema::dropIfExists('mcp_knowledge_sync_runs');
        Schema::dropIfExists('mcp_knowledge_chunks');
        Schema::dropIfExists('mcp_knowledge_documents');
        Schema::dropIfExists('mcp_knowledge_versions');
        Schema::dropIfExists('mcp_semantic_releases');
    }
};
