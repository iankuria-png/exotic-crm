<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'platform_id',
        'provider',
        'status',
        'total_items',
        'sent_count',
        'failed_count',
        'scheduled_at',
        'executed_at',
        'completed_at',
        'confirmed_at',
        'created_by',
        'upload_batch_id',
        'source_filename',
    ];

    protected $casts = [
        'total_items' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'scheduled_at' => 'datetime',
        'executed_at' => 'datetime',
        'completed_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PushCampaignItem::class, 'campaign_id');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForPlatform($query, $platformIds)
    {
        if (is_null($platformIds)) {
            return $query;
        }

        if (!is_array($platformIds)) {
            $platformIds = [$platformIds];
        }

        $normalized = array_values(array_filter(array_map('intval', $platformIds), static fn ($id) => $id > 0));

        if (empty($normalized)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('platform_id', $normalized);
    }
}
