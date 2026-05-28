<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketRevenueTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'period',
        'target',
        'target_currency',
        'set_by',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'target' => 'decimal:2',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function setter()
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
