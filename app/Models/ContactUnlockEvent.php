<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactUnlockEvent extends Model
{
    public const TYPE_ELIGIBLE_VIEW = 'eligible_view';
    public const TYPE_CTA_CLICK = 'cta_click';
    public const TYPE_CHECKOUT_START = 'checkout_start';
    public const TYPE_PAYMENT_PENDING = 'payment_pending';
    public const TYPE_PAYMENT_COMPLETED = 'payment_completed';
    public const TYPE_UNLOCK_REVEAL = 'unlock_reveal';
    public const TYPE_UPSELL_IMPRESSION = 'upsell_impression';
    public const TYPE_UPSELL_CLICK = 'upsell_click';

    public const TYPES = [
        self::TYPE_ELIGIBLE_VIEW,
        self::TYPE_CTA_CLICK,
        self::TYPE_CHECKOUT_START,
        self::TYPE_PAYMENT_PENDING,
        self::TYPE_PAYMENT_COMPLETED,
        self::TYPE_UNLOCK_REVEAL,
        self::TYPE_UPSELL_IMPRESSION,
        self::TYPE_UPSELL_CLICK,
    ];

    protected $fillable = [
        'platform_id',
        'client_id',
        'wp_post_id',
        'visitor_contact_unlock_id',
        'event_type',
        'scope',
        'session_hash',
        'pageview_id',
        'visitor_phone_hash',
        'event_id_hash',
        'referrer_host',
        'traffic_source',
        'local_hour',
        'occurred_at',
        'metadata_json',
    ];

    protected $casts = [
        'local_hour' => 'integer',
        'occurred_at' => 'datetime',
        'metadata_json' => 'array',
    ];
}
