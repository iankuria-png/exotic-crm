<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientActiveSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        'date',
        'platform_id',
        'count',
        'created_at',
    ];

    protected $casts = [
        'date' => 'date',
        'count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
