<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycSubjectSite extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'platform_id',
        'wp_post_id',
        'wp_user_id',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
    ];

    protected $casts = [
        'wp_post_id' => 'integer',
        'wp_user_id' => 'integer',
        'platform_id' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function subject()
    {
        return $this->belongsTo(KycSubject::class, 'subject_id');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
