<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingSystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope',
        'mode_json',
        'domain_json',
        'branding_json',
        'timing_json',
        'smtp_json',
        'pin_policy_json',
        'discount_policy_json',
        'updated_by',
    ];

    protected $casts = [
        'mode_json' => 'array',
        'domain_json' => 'array',
        'branding_json' => 'array',
        'timing_json' => 'array',
        'smtp_json' => 'array',
        'pin_policy_json' => 'array',
        'discount_policy_json' => 'array',
    ];

    public function updatedByUser()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
