<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingRoutingDecision extends Model
{
    use HasFactory;

    public $timestamps = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id',
        'market_id',
        'billing_surface',
        'chosen_binding_id',
        'provider_profile_id',
        'provider_type_key',
        'execution_mode',
        'environment',
        'fallback_taken',
        'decision_version',
        'shadow_diff_json',
        'surface_cutover_flag',
        'snapshot_json',
        'immutable_until_terminal_state',
        'decision_json',
        'created_at',
    ];

    protected $casts = [
        'fallback_taken' => 'boolean',
        'decision_version' => 'integer',
        'shadow_diff_json' => 'array',
        'snapshot_json' => 'array',
        'decision_json' => 'array',
        'immutable_until_terminal_state' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function market()
    {
        return $this->belongsTo(Platform::class, 'market_id');
    }

    public function platform()
    {
        return $this->market();
    }

    public function chosenBinding()
    {
        return $this->belongsTo(BillingMarketProviderBinding::class, 'chosen_binding_id');
    }

    public function providerProfile()
    {
        return $this->belongsTo(BillingProviderProfile::class, 'provider_profile_id');
    }
}
