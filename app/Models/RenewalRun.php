<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RenewalRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'campaign_id', 'run_at', 'total_targeted',
        'sent_count', 'failed_count', 'skipped_count',
        'run_by', 'status', 'currency',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(RenewalCampaign::class, 'campaign_id');
    }

    public function runner()
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
