<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComplianceEvidenceExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'platform_id',
        'requested_by_user_id',
        'storage_disk',
        'storage_path',
        'manifest_json',
        'reason',
        'expires_at',
    ];

    protected $casts = [
        'manifest_json' => 'array',
        'expires_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
