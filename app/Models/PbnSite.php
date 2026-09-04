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
            'seo_fields' => 'copy',
            'duplicate_policy' => 'skip',
            'update_policy' => 'snapshot',

            // Share of the batch that receives each badge. Allocated by count
            // from a shuffled item list, not rolled per profile, so a 10% VIP
            // setting on 90 profiles is exactly 9 and never 3 or 17.
            // `verified` asserts a KYC check the seeded profile has not been
            // through, so it defaults to nobody and must be set deliberately.
            'badges' => [
                'featured_pct' => 10,
                'premium_pct' => 25,
                'verified_pct' => 0,
            ],

            // Duplicate bios across a PBN are the main content risk a batch
            // carries. `on_failure` decides what happens when every AI provider
            // fails for one profile. The default is the SEO engine's own
            // deterministic template: free, instant, and unlike copying the
            // source it still produces different text. It is English-only, so a
            // non-English market degrades past it automatically.
            'bio' => [
                'mode' => 'rewrite',
                'on_failure' => 'template',
            ],

            // Rotating away from the source's lead photo costs nothing and
            // removes the most obvious visual duplicate signal.
            'main_image' => [
                'mode' => 'rotate',
            ],

            // A window spreads expiry across a range so a whole batch does not
            // disappear on one day.
            'expiry' => [
                'mode' => 'window',
                'min_days' => 30,
                'max_days' => 90,
            ],

            // Trickle. Items carry a release_at and the batch job provisions
            // only what is due, then re-queues itself for the next release —
            // never sleeping and never holding a worker open.
            'release' => [
                'mode' => 'immediate',
                'per_period' => 10,
            ],
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
