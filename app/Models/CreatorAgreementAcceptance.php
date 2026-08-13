<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreatorAgreementAcceptance extends Model
{
    use HasFactory;

    protected $fillable = [
        'agreement_version_id',
        'client_id',
        'platform_id',
        'wp_user_id',
        'wp_post_id',
        'actor_wp_user_id',
        'source_context',
        'accepted_at',
        'ip_address',
        'user_agent',
        'wp_idempotency_key',
        'raw_payload',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function version()
    {
        return $this->belongsTo(CreatorAgreementVersion::class, 'agreement_version_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
