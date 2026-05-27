<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_user_id',
        'client_id',
        'deal_id',
        'type',
        'basis_amount',
        'rate',
        'amount',
        'currency',
        'status',
        'earned_at',
        'paid_at',
        'payout_id',
        'meta',
    ];

    protected $casts = [
        'basis_amount' => 'decimal:2',
        'rate' => 'decimal:4',
        'amount' => 'decimal:2',
        'earned_at' => 'datetime',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function payout()
    {
        return $this->belongsTo(CommissionPayout::class, 'payout_id');
    }
}
