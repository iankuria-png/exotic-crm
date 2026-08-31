<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PbnSiteSource extends Model
{
    protected $fillable = [
        'pbn_site_id',
        'platform_id',
        'is_default',
        'weight',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'weight' => 'integer',
    ];

    public function pbnSite()
    {
        return $this->belongsTo(PbnSite::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
