<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingProviderType extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'capability_json',
        'status',
    ];

    protected $casts = [
        'capability_json' => 'array',
    ];
}
