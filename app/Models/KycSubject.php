<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycSubject extends Model
{
    use HasFactory;

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_INFO_REQUESTED = 'info_requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'client_id',
        'legal_name',
        'dob',
        'nationality',
        'status',
        'verified_at',
        'expires_at',
        'last_reviewer_id',
        'last_reason_user',
        'last_reason_internal',
        'last_info_request_at',
        'grace_started_at',
        'last_escalation_at',
        'last_escalation_rule',
    ];

    protected $casts = [
        'dob' => 'date',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_info_request_at' => 'datetime',
        'grace_started_at' => 'datetime',
        'last_escalation_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function documents()
    {
        return $this->hasMany(KycDocument::class, 'subject_id');
    }

    public function sites()
    {
        return $this->hasMany(KycSubjectSite::class, 'subject_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'last_reviewer_id');
    }
}
