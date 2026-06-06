<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoOptimizeWriteReservation extends Model
{
    protected $table = 'auto_optimize_write_reservations';

    protected $fillable = [
        'item_id',
        'platform_id',
        'window_start',
        'operation',
        'reserved',
        'consumed',
        'expires_at',
        'released_at',
        'reservation_active_key',
    ];

    protected $casts = [
        'reserved' => 'integer',
        'consumed' => 'integer',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    // Driver-safe one-active-reservation-per-item guard.
    // reservation_active_key = item_id while released_at IS NULL, else NULL.
    protected static function booted(): void
    {
        static::saving(function (self $reservation) {
            $reservation->reservation_active_key = $reservation->released_at === null
                ? (int) $reservation->item_id
                : null;
        });
    }

    public function item()
    {
        return $this->belongsTo(AutoOptimizeItem::class, 'item_id');
    }
}
