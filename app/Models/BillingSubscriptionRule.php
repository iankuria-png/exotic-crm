<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingSubscriptionRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_id',
        'activation_method_json',
        'renewal_method_json',
        'free_trial_json',
        'discount_json',
        'expiry_policy_json',
    ];

    protected $casts = [
        'activation_method_json' => 'array',
        'renewal_method_json' => 'array',
        'free_trial_json' => 'array',
        'discount_json' => 'array',
        'expiry_policy_json' => 'array',
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
