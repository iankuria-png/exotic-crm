<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RenewalCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'trigger_days', 'channel',
        'template_id', 'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function runs()
    {
        return $this->hasMany(RenewalRun::class, 'campaign_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
