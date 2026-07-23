<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RenewalCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id', 'product_id', 'trigger_days', 'channel',
        'template_id', 'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

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

    /**
     * Global-default campaigns (no market scope). Used as the fallback cadence for
     * any market that has not defined its own campaigns.
     */
    public function scopeGlobalDefault($query)
    {
        return $query->whereNull('platform_id');
    }

    public function scopeForPlatform($query, int $platformId)
    {
        return $query->where('platform_id', $platformId);
    }

    public function isGlobalDefault(): bool
    {
        return $this->platform_id === null;
    }
}
