<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoBoostTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'canonical_key',
        'display_city',
        'target_count',
        'selected_count',
        'activated_count',
        'borrowed_from',
    ];

    protected $casts = [
        'target_count' => 'integer',
        'selected_count' => 'integer',
        'activated_count' => 'integer',
        'borrowed_from' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(SeoBoostBatch::class, 'batch_id');
    }

    public function items()
    {
        return $this->hasMany(SeoBoostItem::class, 'target_id');
    }
}
