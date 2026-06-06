<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoOptimizeItem extends Model
{
    // Statuses where active_client_key must be set (= client_id)
    public const ACTIVE_STATUSES = ['queued', 'building', 'pending', 'applying'];

    // Terminal statuses where active_client_key must be NULL
    public const TERMINAL_STATUSES = ['applied', 'skipped', 'reverted', 'failed'];

    protected $fillable = [
        'auto_optimize_plan_id',
        'auto_optimize_run_id',
        'platform_id',
        'client_id',
        'status',
        'reason',
        'profile_snapshot',
        'previous_bio_html',
        'new_bio_html',
        'previous_score',
        'new_score',
        'previous_score_breakdown',
        'new_score_breakdown',
        'previous_main_attachment_id',
        'previous_main_image_url',
        'new_main_attachment_id',
        'new_main_image_url',
        'source_bio_hash',
        'source_main_attachment_id',
        'applied_bio_hash',
        'bio_simhash',
        'active_client_key',
        'actions_applied',
        'provider_used',
        'language_used',
        'ai_cost_usd',
        'impact_before',
        'impact_after',
        'impact',
        'impact_checked_at',
        'applied_at',
        'approved_by',
        'reverted_at',
        'reverted_by',
        'error_message',
    ];

    protected $casts = [
        'profile_snapshot' => 'array',
        'previous_score_breakdown' => 'array',
        'new_score_breakdown' => 'array',
        'actions_applied' => 'array',
        'impact_before' => 'array',
        'impact_after' => 'array',
        'impact' => 'array',
        'impact_checked_at' => 'datetime',
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
        'ai_cost_usd' => 'decimal:6',
    ];

    // Driver-safe cross-run idempotency guard.
    // active_client_key = client_id while status is active, NULL at terminal states.
    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->active_client_key = in_array($item->status, self::ACTIVE_STATUSES, true)
                ? (int) $item->client_id
                : null;
        });
    }

    public function plan()
    {
        return $this->belongsTo(AutoOptimizePlan::class, 'auto_optimize_plan_id');
    }

    public function run()
    {
        return $this->belongsTo(AutoOptimizeRun::class, 'auto_optimize_run_id');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reverter()
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
