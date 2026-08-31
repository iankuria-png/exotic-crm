<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PbnSeedTarget extends Model
{
    protected $fillable = [
        'batch_id',
        'target_region_id',
        'target_city_id',
        'region_name',
        'city_name',
        'target_count',
        'selected_count',
        'created_count',
    ];

    protected $casts = [
        'target_region_id' => 'integer',
        'target_city_id' => 'integer',
        'target_count' => 'integer',
        'selected_count' => 'integer',
        'created_count' => 'integer',
    ];

    public function batch()
    {
        return $this->belongsTo(PbnSeedBatch::class, 'batch_id');
    }

    public function items()
    {
        return $this->hasMany(PbnSeedItem::class, 'target_id');
    }
}
