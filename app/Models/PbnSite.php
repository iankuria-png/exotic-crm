<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PbnSite extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'default_source_platform_id',
        'is_active',
        'country',
        'timezone',
        'currency_code',
        'phone_prefix',
        'wp_api_url',
        'wp_api_user',
        'wp_api_password',
        'db_host',
        'db_name',
        'db_user',
        'db_pass',
        'db_prefix',
        'copy_policy',
        'last_checked_at',
        'last_status',
        'last_error',
    ];

    protected $hidden = [
        'wp_api_password',
        'db_pass',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'wp_api_password' => 'encrypted',
        'db_pass' => 'encrypted',
        'copy_policy' => 'array',
        'last_checked_at' => 'datetime',
    ];

    public function defaultSourcePlatform()
    {
        return $this->belongsTo(Platform::class, 'default_source_platform_id');
    }

    public function sourceRows()
    {
        return $this->hasMany(PbnSiteSource::class);
    }

    public function sourcePlatforms()
    {
        return $this->belongsToMany(Platform::class, 'pbn_site_sources')
            ->withPivot(['is_default', 'weight'])
            ->withTimestamps();
    }

    public function seedBatches()
    {
        return $this->hasMany(PbnSeedBatch::class);
    }

    public function seedItems()
    {
        return $this->hasMany(PbnSeedItem::class);
    }

    public function seedEvents()
    {
        return $this->hasMany(PbnSeedEvent::class);
    }

    public function getConnectionConfig(): array
    {
        if (strtolower((string) $this->db_host) === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => $this->db_name,
                'prefix' => $this->db_prefix ?? '',
                'foreign_key_constraints' => false,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => $this->db_host,
            'port' => 3306,
            'database' => $this->db_name,
            'username' => $this->db_user,
            'password' => $this->db_pass,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $this->db_prefix ?? '',
        ];
    }

    public function effectiveCopyPolicy(array $overrides = []): array
    {
        return array_replace_recursive(self::defaultCopyPolicy(), $this->copy_policy ?: [], $overrides);
    }

    public static function defaultCopyPolicy(): array
    {
        return [
            'post_status' => 'publish',
            'phone' => 'copy',
            'media' => 'two_stage',
            'vip_flags' => 'strip',
            'verification' => 'strip',
            'seo_fields' => 'copy',
            'duplicate_policy' => 'skip',
            'update_policy' => 'snapshot',
        ];
    }

    public function credentialsReady(): bool
    {
        return filled($this->wp_api_url)
            && filled($this->wp_api_user)
            && filled($this->wp_api_password)
            && $this->databaseCredentialsReady();
    }

    public function databaseCredentialsReady(): bool
    {
        return filled($this->db_host)
            && filled($this->db_name)
            && filled($this->db_user)
            && filled($this->db_pass)
            && filled($this->db_prefix);
    }
}
