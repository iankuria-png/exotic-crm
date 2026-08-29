<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A profile report submitted by a signed-in member.
 *
 * The row records what was reported and a coarse review status. It carries no
 * free text from the member and no staff conclusion about the advertiser: a
 * report is a request for staff attention, never a published accusation, and
 * nothing here suppresses an advertiser automatically.
 */
class CustomerSafetyReport extends Model
{
    /** Categories a member can choose. Free text is emailed, never stored. */
    public const CATEGORY_PHOTOS_NOT_REAL = 'photos_not_real';
    public const CATEGORY_CONTACT_NOT_WORKING = 'contact_not_working';
    public const CATEGORY_PAYMENT_SCAM = 'payment_scam';
    public const CATEGORY_UNDERAGE_OR_COERCED = 'underage_or_coerced';
    public const CATEGORY_ABUSIVE_BEHAVIOUR = 'abusive_behaviour';
    public const CATEGORY_DUPLICATE_OR_STOLEN = 'duplicate_or_stolen';
    public const CATEGORY_OTHER = 'other';

    /** Coarse status. The member sees this; staff notes stay staff-only. */
    public const STATUS_RECEIVED = 'received';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_CLOSED = 'closed';

    public const SOURCE_MEMBER_PROFILE_REPORT = 'member_profile_report';

    /** Reports a single member can file in a rolling 24 hours. */
    public const MAX_PER_DAY = 10;

    /** Reports returned to the Safety Centre in one read. */
    public const HISTORY_PAGE = 60;

    /**
     * Reports keep their account link while support and moderation obligations
     * can still need them, then the link is dropped. This mirrors the signed
     * reachability-feedback policy: retain while needed, then anonymize.
     */
    public const ANONYMIZE_AFTER_DAYS = 730;

    protected $table = 'customer_safety_reports';

    protected $fillable = [
        'reference',
        'customer_account_id',
        'platform_id',
        'wp_post_id',
        'client_id',
        'category',
        'status',
        'source',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'review_note',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'wp_post_id' => 'integer',
        'client_id' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'reviewed_by' => 'integer',
    ];

    /** @return string[] */
    public static function categories(): array
    {
        return [
            self::CATEGORY_PHOTOS_NOT_REAL,
            self::CATEGORY_CONTACT_NOT_WORKING,
            self::CATEGORY_PAYMENT_SCAM,
            self::CATEGORY_UNDERAGE_OR_COERCED,
            self::CATEGORY_ABUSIVE_BEHAVIOUR,
            self::CATEGORY_DUPLICATE_OR_STOLEN,
            self::CATEGORY_OTHER,
        ];
    }

    /** A report is still open while staff could act on it. */
    public function isOpen(): bool
    {
        return in_array((string) $this->status, [self::STATUS_RECEIVED, self::STATUS_UNDER_REVIEW], true);
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
