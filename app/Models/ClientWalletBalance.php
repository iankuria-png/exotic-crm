<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientWalletBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'currency',
        'balance',
        'last_synced_at',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'last_synced_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
