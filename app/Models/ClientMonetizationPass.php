<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientMonetizationPass extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'eligibility_snapshot_json' => 'array', 'is_sandbox' => 'boolean', 'list_amount' => 'decimal:2', 'subsidy_amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public const COMPLIMENTARY_SOURCES = ['staff_comp', 'policy_new_subscription', 'campaign_active_subscription', 'campaign_selected'];

    public function isComplimentary(): bool
    {
        return in_array($this->grant_source, self::COMPLIMENTARY_SOURCES, true) || (float) $this->paid_amount === 0.0 && (bool) data_get($this->eligibility_snapshot_json, 'staff_comp');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
