<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpiryReconciliationRun extends Model
{
    use HasFactory;

    public const MODE_DRY = 'dry';
    public const MODE_LIVE = 'live';

    protected $fillable = [
        'mode',
        'platform_id',
        'initiated_by',
        'candidates',
        'processed',
        'failed',
        'breakdown',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'candidates' => 'integer',
        'processed' => 'integer',
        'failed' => 'integer',
        'breakdown' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
