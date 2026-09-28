<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientMonetizationPass extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'eligibility_snapshot_json' => 'array', 'is_sandbox' => 'boolean', 'list_amount' => 'decimal:2', 'subsidy_amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
