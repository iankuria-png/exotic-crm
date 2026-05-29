<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSender extends Model
{
    public const STATUS_PAIRING = 'pairing';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_FAILED_PAIRING = 'failed_pairing';
    public const STATUS_BANNED = 'banned';
    public const STATUS_RETIRED = 'retired';

    public const WARMUP_DAY_1_3 = 'day_1_3';
    public const WARMUP_DAY_4_7 = 'day_4_7';
    public const WARMUP_DAY_8_14 = 'day_8_14';
    public const WARMUP_MATURE = 'mature';

    protected $table = 'whatsapp_senders';

    protected $fillable = [
        'provider_profile_id',
        'phone_e164',
        'active_phone_marker',
        'display_name',
        'auth_state_encrypted',
        'connection_status',
        'warmup_phase',
        'warmup_started_at',
        'daily_limit',
        'daily_sent_count',
        'daily_sent_resets_at',
        'quarantine_until',
        'last_message_at',
        'last_disconnect_reason',
        'consecutive_failures',
        'retired_at',
        'retired_reason',
    ];

    protected $casts = [
        'provider_profile_id' => 'integer',
        'auth_state_encrypted' => 'encrypted',
        'warmup_started_at' => 'datetime',
        'daily_limit' => 'integer',
        'daily_sent_count' => 'integer',
        'daily_sent_resets_at' => 'datetime',
        'quarantine_until' => 'datetime',
        'last_message_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'retired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (WhatsAppSender $sender): void {
            if ($sender->getConnection()->getDriverName() !== 'mysql') {
                $sender->active_phone_marker = $sender->retired_at ? null : $sender->phone_e164;
            }

            if (!$sender->connection_status) {
                $sender->connection_status = self::STATUS_PAIRING;
            }

            if (!$sender->warmup_phase) {
                $sender->warmup_phase = self::WARMUP_DAY_1_3;
            }

            if (!$sender->daily_limit) {
                $sender->daily_limit = self::limitForWarmupPhase((string) $sender->warmup_phase);
            }
        });
    }

    public function providerProfile()
    {
        return $this->belongsTo(WhatsAppProviderProfile::class, 'provider_profile_id');
    }

    public function attempts()
    {
        return $this->hasMany(WhatsAppMessageAttempt::class, 'sender_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('retired_at');
    }

    public function isConnected(): bool
    {
        return $this->connection_status === self::STATUS_CONNECTED && !$this->retired_at;
    }

    public function isQuarantined(): bool
    {
        return $this->quarantine_until && $this->quarantine_until->isFuture();
    }

    public function hasDailyCapacity(): bool
    {
        return (int) $this->daily_sent_count < (int) $this->daily_limit;
    }

    public function canSend(): bool
    {
        return $this->isConnected() && !$this->isQuarantined() && $this->hasDailyCapacity();
    }

    public function markConnected(): self
    {
        $this->forceFill([
            'connection_status' => self::STATUS_CONNECTED,
            'warmup_started_at' => $this->warmup_started_at ?: now(),
            'daily_limit' => $this->daily_limit ?: self::limitForWarmupPhase((string) ($this->warmup_phase ?: self::WARMUP_DAY_1_3)),
            'last_disconnect_reason' => null,
        ])->save();

        return $this->refresh();
    }

    public function markDisconnected(?string $reason = null): self
    {
        $this->forceFill([
            'connection_status' => self::STATUS_DISCONNECTED,
            'last_disconnect_reason' => $reason,
        ])->save();

        return $this->refresh();
    }

    public function markBanned(?string $reason = null): self
    {
        $this->forceFill([
            'connection_status' => self::STATUS_RETIRED,
            'retired_at' => now(),
            'retired_reason' => $reason ?: 'sender_banned',
            'last_disconnect_reason' => $reason ?: 'sender_banned',
        ])->save();

        return $this->refresh();
    }

    public function quarantine(?int $hours = 6): self
    {
        $this->forceFill([
            'quarantine_until' => now()->addHours($hours ?: 6),
        ])->save();

        return $this->refresh();
    }

    public static function limitForWarmupPhase(string $phase): int
    {
        return match ($phase) {
            self::WARMUP_DAY_4_7 => 50,
            self::WARMUP_DAY_8_14 => 100,
            self::WARMUP_MATURE => (int) config('services.whatsapp.baileys_mature_daily_limit', 500),
            default => 20,
        };
    }
}
