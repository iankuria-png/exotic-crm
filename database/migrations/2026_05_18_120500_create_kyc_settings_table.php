<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->json('enabled_platform_ids')->nullable();
            $table->json('required_document_kinds')->nullable();
            $table->unsignedInteger('max_doc_bytes')->default(20 * 1024 * 1024);
            $table->json('reject_reason_options')->nullable();
            $table->boolean('search_boost_enabled')->default(true);
            $table->enum('active_storage_driver', ['db', 's3'])->default('db');
            $table->string('s3_bucket')->nullable();
            $table->string('s3_region')->nullable();
            $table->text('s3_kms_key_arn')->nullable();
            $table->string('s3_endpoint_override')->nullable();
            $table->json('exempt_plan_keys')->nullable();
            $table->unsignedInteger('grace_days_default')->default(30);
            $table->json('grace_days_per_platform')->nullable();
            $table->json('email_warning_days')->nullable();
            $table->json('escalation_rule_per_platform')->nullable();
            $table->unsignedInteger('reverify_interval_days')->default(365);
            $table->boolean('reverify_auto_sweep_enabled')->default(true);
            $table->unsignedInteger('reverify_dispatch_pace_seconds')->default(5);
            $table->unsignedInteger('fanout_queue_concurrency')->default(4);
            $table->json('reviewer_notification_channels')->nullable();
            $table->unsignedInteger('audit_retention_days')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('kyc_settings')->insert([
            'id' => 1,
            'enabled_platform_ids' => json_encode([]),
            'required_document_kinds' => json_encode(['id_front', 'selfie']),
            'max_doc_bytes' => 20 * 1024 * 1024,
            'reject_reason_options' => json_encode([
                ['key' => 'blurry_id', 'label' => 'ID photo is blurry — please retake in good lighting'],
                ['key' => 'name_mismatch', 'label' => "The name on your ID doesn't match your profile name"],
                ['key' => 'selfie_mismatch', 'label' => 'Selfie does not match the person on the ID'],
                ['key' => 'other', 'label' => 'Other'],
            ]),
            'search_boost_enabled' => true,
            'active_storage_driver' => 'db',
            'exempt_plan_keys' => json_encode(['forever']),
            'grace_days_default' => 30,
            'grace_days_per_platform' => json_encode((object) []),
            'email_warning_days' => json_encode([0, 7, 14, 21, 29]),
            'escalation_rule_per_platform' => json_encode((object) []),
            'reverify_interval_days' => 365,
            'reverify_auto_sweep_enabled' => true,
            'reverify_dispatch_pace_seconds' => 5,
            'fanout_queue_concurrency' => 4,
            'reviewer_notification_channels' => json_encode(['in_app_badge']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_settings');
    }
};
