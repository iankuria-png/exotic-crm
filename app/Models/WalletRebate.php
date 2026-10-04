<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletRebate extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array', 'base_amount' => 'decimal:2', 'rate_percent' => 'decimal:2', 'calculated_amount' => 'decimal:2', 'amount' => 'decimal:2', 'reversal_shortfall' => 'decimal:2'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }
}
