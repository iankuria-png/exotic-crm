<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->foreignId('uploaded_by_user_id')->nullable()->after('subject_id')->constrained('users')->nullOnDelete();
            $table->string('upload_origin', 32)->default('advertiser_wp')->after('original_filename');
            $table->string('upload_source_channel', 32)->nullable()->after('upload_origin');
            $table->text('upload_note')->nullable()->after('upload_source_channel');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by_user_id');
            $table->dropColumn(['upload_origin', 'upload_source_channel', 'upload_note']);
        });
    }
};
