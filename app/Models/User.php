<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'google_sub',
        'google_linked_at',
        'password',
        'role',
        'is_ceo',
        'assigned_market_ids',
        'status',
        'phone',
        'notification_prefs',
        'sb_agent_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'google_linked_at' => 'datetime',
        'assigned_market_ids' => 'array',
        'is_ceo' => 'boolean',
        'notification_prefs' => 'array',
    ];
    
    // Add relationship to platforms
    public function platforms()
    {
        return $this->belongsToMany(Platform::class, 'user_platforms');
    }
    
    // Helper method to check if user has access to a platform
    public function hasPlatformAccess($platformId)
    {
        return $this->platforms()->where('platform_id', $platformId)->exists();
    }

    public function assignedMarketIds(): array
    {
        $assigned = $this->assigned_market_ids;

        if (is_array($assigned)) {
            return array_values(array_unique(array_map('intval', $assigned)));
        }

        if (is_string($assigned) && trim($assigned) !== '') {
            $decoded = json_decode($assigned, true);
            if (is_array($decoded)) {
                return array_values(array_unique(array_map('intval', $decoded)));
            }
        }

        return [];
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    public function paymentFailureSmsEnabled(): bool
    {
        $prefs = $this->notification_prefs;
        $default = in_array($this->role, ['sales', 'field_sales'], true);

        if (!is_array($prefs)) {
            return $default;
        }

        return (bool) data_get($prefs, 'payment_failure_sms.enabled', $default);
    }

    public function paymentFailureSmsMarketIds(): ?array
    {
        $ids = data_get($this->notification_prefs ?? [], 'payment_failure_sms.market_ids');

        if ($ids === null) {
            return null;
        }

        return array_values(array_unique(array_filter(
            array_map('intval', (array) $ids),
            static fn (int $id): bool => $id > 0
        )));
    }

    public function paymentFailureSmsState(): string
    {
        if (!in_array($this->role, ['admin', 'sub_admin', 'sales', 'field_sales'], true)) {
            return 'not_eligible';
        }

        return $this->paymentFailureSmsEnabled() ? 'enabled' : 'disabled';
    }
}
