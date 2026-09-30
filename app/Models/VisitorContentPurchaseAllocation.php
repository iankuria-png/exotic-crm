<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorContentPurchaseAllocation extends Model
{
    protected $guarded = [];

    protected $casts = ['amount_minor' => 'integer', 'share_numerator' => 'integer', 'share_denominator' => 'integer'];

    public function purchase()
    {
        return $this->belongsTo(VisitorContentPurchase::class, 'purchase_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function amount(): string
    {
        return number_format($this->amount_minor / 100, 2, '.', '');
    }
}
