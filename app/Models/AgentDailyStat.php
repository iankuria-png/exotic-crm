<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentDailyStat extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'platform_id',
        'date',
        'profiles_created',
        'subs_activated',
        'subs_renewed',
        'payments_matched',
        'subscriptions_created',
        'leads_contacted',
        'leads_converted',
        'chats_replied',
        'sms_sent',
        'credentials_sent',
        'revenue',
        'revenue_currency',
        'free_trials_given',
        'discounts_given',
        'avg_lead_response_secs',
        'total_actions',
    ];

    protected $casts = [
        'date' => 'date',
        'profiles_created' => 'integer',
        'subs_activated' => 'integer',
        'subs_renewed' => 'integer',
        'payments_matched' => 'integer',
        'subscriptions_created' => 'integer',
        'leads_contacted' => 'integer',
        'leads_converted' => 'integer',
        'chats_replied' => 'integer',
        'sms_sent' => 'integer',
        'credentials_sent' => 'integer',
        'revenue' => 'decimal:2',
        'free_trials_given' => 'integer',
        'discounts_given' => 'integer',
        'avg_lead_response_secs' => 'integer',
        'total_actions' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
