<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PbnSeedEvent extends Model
{
    protected $fillable = [
        'pbn_site_id',
        'batch_id',
        'item_id',
        'actor_id',
        'type',
        'level',
        'message',
        'context',
    ];

    protected $casts = [
        'pbn_site_id' => 'integer',
        'batch_id' => 'integer',
        'item_id' => 'integer',
        'actor_id' => 'integer',
        'context' => 'array',
    ];

    public function pbnSite()
    {
        return $this->belongsTo(PbnSite::class);
    }

    public function batch()
    {
        return $this->belongsTo(PbnSeedBatch::class, 'batch_id');
    }

    public function item()
    {
        return $this->belongsTo(PbnSeedItem::class, 'item_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
