<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingWalletRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_id',
        'enabled',
        'currency_code',
        'topup_preset_json',
        'limit_json',
        'auto_renew_json',
        'ui_json',
        'fx_override_json',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'topup_preset_json' => 'array',
        'limit_json' => 'array',
        'auto_renew_json' => 'array',
        'ui_json' => 'array',
        'fx_override_json' => 'array',
    ];

    public function market()
    {
        return $this->belongsTo(Platform::class, 'market_id');
    }

    public function platform()
    {
        return $this->market();
    }
}
