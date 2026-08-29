<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReachabilityFeedback extends Model
{
    public const OUTCOME_REACHED = 'reached';
    public const OUTCOME_NO_ANSWER = 'no_answer';
    public const OUTCOME_WRONG_NUMBER = 'wrong_number';
    public const OUTCOME_WHATSAPP_FAILED = 'whatsapp_failed';

    public const STATUS_RECORDED = 'recorded';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_DISMISSED = 'dismissed';

    public const REVIEW_REPEATED_NEGATIVE = 'repeated_negative_reachability';

    protected $table = 'customer_reachability_feedback';

    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'customer_unlock_claim_id',
        'visitor_contact_unlock_id',
        'wp_post_id',
        'client_id',
        'outcome',
        'status',
        'review_reason',
        'note',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'customer_unlock_claim_id' => 'integer',
        'visitor_contact_unlock_id' => 'integer',
        'wp_post_id' => 'integer',
        'client_id' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'reviewed_by' => 'integer',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(CustomerUnlockClaim::class, 'customer_unlock_claim_id');
    }

    public function visitorUnlock(): BelongsTo
    {
        return $this->belongsTo(VisitorContactUnlock::class, 'visitor_contact_unlock_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
