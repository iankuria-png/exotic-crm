<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionAuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'occurred_at' => 'immutable_datetime', 'exception' => 'boolean'];

    protected $hidden = ['client_id', 'deal_id', 'payment_id', 'actor_id', 'approver_id', 'source_id'];

    public function run(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SubscriptionAuditRun::class, 'run_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('Subscription audit event snapshots are immutable.');
        });
        static::deleting(function (): void {
            throw new \LogicException('Use the bounded reporting-snapshot retention command.');
        });
    }
}
