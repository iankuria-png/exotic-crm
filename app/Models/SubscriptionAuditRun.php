<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionAuditRun extends Model
{
    protected $guarded = [];

    protected $casts = ['from_at' => 'immutable_datetime', 'to_at' => 'immutable_datetime', 'as_of' => 'immutable_datetime', 'coverage' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->getOriginal('status') === 'completed') {
                throw new \LogicException('Completed subscription audit snapshots are immutable.');
            }
        });
        static::deleting(function (self $run): void {
            throw new \LogicException('Use the bounded reporting-snapshot retention command.');
        });
    }
}
