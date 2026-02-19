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
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
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
}