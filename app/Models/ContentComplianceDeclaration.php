<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentComplianceDeclaration extends Model
{
    use HasFactory;

    public const PARTICIPANT_SOLO = 'solo';

    public const PARTICIPANT_OTHER_PEOPLE = 'other_people_declared';

    public const PARTICIPANT_UNKNOWN_LEGACY = 'unknown_legacy';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_BLOCKED_PENDING_RELEASE = 'blocked_pending_release';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'client_id',
        'platform_id',
        'wp_user_id',
        'wp_post_id',
        'wp_attachment_id',
        'content_kind',
        'participant_status',
        'status',
        'declared_at',
        'ip_address',
        'user_agent',
        'wp_idempotency_key',
        'raw_payload',
    ];

    protected $casts = [
        'declared_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function releaseParticipants()
    {
        return $this->hasMany(ContentReleaseParticipant::class, 'content_declaration_id');
    }
}
