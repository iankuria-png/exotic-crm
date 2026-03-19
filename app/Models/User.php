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
        'password',
        'role',
        'assigned_market_ids',
        'status',
        'sb_agent_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'assigned_market_ids' => 'array',
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
}
