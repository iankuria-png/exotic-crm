<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycSetting extends Model
{
    use HasFactory;

    protected $table = 'kyc_settings';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'enabled_platform_ids',
        'required_document_kinds',
        'max_doc_bytes',
        'reject_reason_options',
        'search_boost_enabled',
        'active_storage_driver',
        's3_bucket',
        's3_region',
        's3_kms_key_arn',
        's3_endpoint_override',
        'exempt_plan_keys',
        'grace_days_default',
        'grace_days_per_platform',
        'email_warning_days',
        'escalation_rule_per_platform',
        'reverify_interval_days',
        'reverify_auto_sweep_enabled',
        'reverify_dispatch_pace_seconds',
        'fanout_queue_concurrency',
        'reviewer_notification_channels',
        'audit_retention_days',
        'updated_by',
    ];

    protected $casts = [
        'enabled_platform_ids' => 'array',
        'required_document_kinds' => 'array',
        'reject_reason_options' => 'array',
        'search_boost_enabled' => 'boolean',
        'exempt_plan_keys' => 'array',
        'grace_days_per_platform' => 'array',
        'email_warning_days' => 'array',
        'escalation_rule_per_platform' => 'array',
        'reverify_auto_sweep_enabled' => 'boolean',
        'reviewer_notification_channels' => 'array',
        'max_doc_bytes' => 'integer',
        'grace_days_default' => 'integer',
        'reverify_interval_days' => 'integer',
        'reverify_dispatch_pace_seconds' => 'integer',
        'fanout_queue_concurrency' => 'integer',
        'audit_retention_days' => 'integer',
    ];

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
