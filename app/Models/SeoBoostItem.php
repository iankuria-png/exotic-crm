<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoBoostItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'target_id',
        'client_id',
        'deal_id',
        'source',
        'canonical_key',
        'display_city',
        'rank',
        'quality_score',
        'score_breakdown',
        'status',
        'failure_reason',
        'activated_at',
        'expires_at',
        'expired_at',
    ];

    protected $casts = [
        'rank' => 'integer',
        'quality_score' => 'integer',
        'score_breakdown' => 'array',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(SeoBoostBatch::class, 'batch_id');
    }

    public function target()
    {
        return $this->belongsTo(SeoBoostTarget::class, 'target_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }
}
