<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScraperProfilePreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain',
        'platform_id',
        'name_selector',
        'age_selector',
        'city_selector',
        'phone_selector',
        'image_selector',
        'name_regex',
        'age_regex',
        'test_url',
        'test_result',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'test_result' => 'array',
        'is_active' => 'boolean',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForDomain($query, string $domain)
    {
        return $query->whereRaw('LOWER(domain) = ?', [strtolower(trim($domain))]);
    }
}
