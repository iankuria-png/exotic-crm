<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class Platform extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'domain', 'country', 'is_active',
        'db_host', 'db_name', 'db_user', 'db_pass', 'db_prefix', 'product_id',
        'wp_api_url', 'wp_api_user', 'wp_api_password',
        'phone_prefix', 'timezone', 'currency_code',
        'sync_last_checked_at', 'sync_last_synced_at',
        'sync_last_scope', 'sync_last_status',
        'sync_last_error', 'sync_last_result', 'payment_link_providers', 'support_chat_url',
        'support_board_api_url', 'support_board_token', 'support_board_sender_id',
        'wallet_settings',
    ];

    protected $hidden = [
        'support_board_token',
        'wp_api_password',
        'db_pass',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sync_last_checked_at' => 'datetime',
        'sync_last_synced_at' => 'datetime',
        'sync_last_result' => 'array',
        'payment_link_providers' => 'array',
        'wallet_settings' => 'array',
    ];

    public function getSupportBoardTokenAttribute($value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (\Throwable $exception) {
            Log::warning('Unable to decrypt platform support_board_token.', [
                'platform_id' => $this->attributes['id'] ?? null,
                'exception' => $exception::class,
            ]);

            return '';
        }
    }

    public function setSupportBoardTokenAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['support_board_token'] = null;
            return;
        }

        $this->attributes['support_board_token'] = Crypt::encryptString((string) $value);
    }
    
    public function getConnectionConfig()
    {
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
    
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
    // Add relationship to users
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_platforms');
    }

    public function billingProviderProfiles()
    {
        return $this->hasMany(BillingProviderProfile::class, 'market_id');
    }

    public function billingMarketProviderBindings()
    {
        return $this->hasMany(BillingMarketProviderBinding::class, 'market_id');
    }

    public function billingRoutingRules()
    {
        return $this->hasMany(BillingRoutingRule::class, 'market_id');
    }

    public function billingWalletRule()
    {
        return $this->hasOne(BillingWalletRule::class, 'market_id');
    }

    public function billingSubscriptionRule()
    {
        return $this->hasOne(BillingSubscriptionRule::class, 'market_id');
    }

    public function billingManualPaymentMethods()
    {
        return $this->hasMany(BillingManualPaymentMethod::class, 'market_id');
    }

    public function billingRoutingDecisions()
    {
        return $this->hasMany(BillingRoutingDecision::class, 'market_id');
    }
}
