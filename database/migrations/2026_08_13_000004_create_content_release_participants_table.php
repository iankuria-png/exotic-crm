<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_release_participants')) {
            Schema::table('content_release_participants', function (Blueprint $table) {
                if (! $this->indexExists('content_release_participants', [
                    'content_release_participants_content_declaration_id_release_status_index',
                    'release_participants_declaration_status_idx',
                ])) {
                    $table->index(['content_declaration_id', 'release_status'], 'release_participants_declaration_status_idx');
                }
            });

            return;
        }

        Schema::create('content_release_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_declaration_id')->constrained('content_compliance_declarations')->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->string('release_status', 80)->default('pending');
            $table->foreignId('id_document_id')->nullable()->constrained('kyc_documents')->nullOnDelete();
            $table->foreignId('release_document_id')->nullable()->constrained('kyc_documents')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['content_declaration_id', 'release_status'], 'release_participants_declaration_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_release_participants');
    }

    private function indexExists(string $table, array $names): bool
    {
        $quotedNames = collect($names)
            ->map(fn (string $name): string => DB::getPdo()->quote($name))
            ->implode(',');

        return ! empty(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name IN ({$quotedNames})"));
    }
};
