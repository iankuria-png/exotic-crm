<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPreferenceSignal extends Model
{
    public const RETENTION_DAYS = 180;

    public const OBJECT_PROFILE = 'profile';
    public const OBJECT_LOCATION = 'location';
    public const OBJECT_SAVED_SEARCH = 'saved_search';

    public const SIGNAL_ONBOARDING_LIKE = 'onboarding.like';
    public const SIGNAL_ONBOARDING_SKIP = 'onboarding.skip';
    public const SIGNAL_NOT_MY_TYPE = 'preference.not_my_type';
    public const SIGNAL_MORE_LIKE_THIS = 'preference.more_like_this';
    public const SIGNAL_RECOMMENDATION_DISMISSED = 'recommendation.dismissed';
    public const SIGNAL_RECOMMENDATION_OPENED = 'recommendation.opened';
    public const SIGNAL_BEHAVIOR_SAVED = 'behavior.saved';
    public const SIGNAL_BEHAVIOR_CONTACT_STARTED = 'behavior.contact_started';
    public const SIGNAL_BEHAVIOR_REVISITED = 'behavior.revisited';
    public const SIGNAL_BEHAVIOR_FOLLOWED = 'behavior.followed';
    public const SIGNAL_BEHAVIOR_SAVED_SEARCH = 'behavior.saved_search';

    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'signal_type',
        'object_type',
        'object_ref',
        'weight',
        'fact_tokens_json',
        'context_json',
        'source_surface',
        'occurred_at',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'object_ref' => 'integer',
        'weight' => 'integer',
        'fact_tokens_json' => 'array',
        'context_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public static function signalWeights(): array
    {
        return [
            self::SIGNAL_ONBOARDING_LIKE => 5,
            self::SIGNAL_ONBOARDING_SKIP => -1,
            self::SIGNAL_NOT_MY_TYPE => -6,
            self::SIGNAL_MORE_LIKE_THIS => 8,
            self::SIGNAL_RECOMMENDATION_DISMISSED => -4,
            self::SIGNAL_RECOMMENDATION_OPENED => 3,
            self::SIGNAL_BEHAVIOR_SAVED => 7,
            self::SIGNAL_BEHAVIOR_CONTACT_STARTED => 10,
            self::SIGNAL_BEHAVIOR_REVISITED => 1,
            self::SIGNAL_BEHAVIOR_FOLLOWED => 4,
            self::SIGNAL_BEHAVIOR_SAVED_SEARCH => 3,
        ];
    }

    public static function objectTypes(): array
    {
        return [self::OBJECT_PROFILE, self::OBJECT_LOCATION, self::OBJECT_SAVED_SEARCH];
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
