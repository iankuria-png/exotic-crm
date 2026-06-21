<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Platform extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'domain', 'country', 'is_active',
        'db_host', 'db_name', 'db_user', 'db_pass', 'db_prefix', 'product_id',
        'wp_api_url', 'wp_api_user', 'wp_api_password',
        'phone_prefix', 'timezone', 'currency_code', 'wp_currency_id',
        'field_activation_deposit_minor', 'field_trial_duration_days', 'field_trial_product_id',
        'field_activation_commission_rate', 'field_renewal_commission_rate', 'field_renewal_commission_months',
        'supported_currencies', 'multi_currency_wallet_enabled',
        'sync_last_checked_at', 'sync_last_synced_at',
        'sync_last_scope', 'sync_last_status',
        'sync_last_error', 'sync_last_result', 'payment_link_providers', 'support_chat_url',
        'support_board_api_url', 'support_board_token', 'support_board_sender_id',
        'wallet_settings',
        'client_sync_checkpoint_at', 'client_sync_checkpoint_post_id',
        'client_sync_tombstone_checkpoint_at', 'client_sync_tombstone_checkpoint_post_id',
        'client_sync_protocol', 'client_sync_contract_version',
        'client_sync_capability_checked_at', 'client_sync_capability_status',
        'client_sync_last_reconciled_at',
        'health_status', 'health_checked_at', 'health_error',
        'health_latency_ms', 'health_consecutive_failures',
        'health_down_since_at', 'health_last_down_notified_at',
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
        'client_sync_checkpoint_at' => 'datetime',
        'client_sync_checkpoint_post_id' => 'integer',
        'client_sync_tombstone_checkpoint_at' => 'datetime',
        'client_sync_tombstone_checkpoint_post_id' => 'integer',
        'client_sync_capability_checked_at' => 'datetime',
        'client_sync_last_reconciled_at' => 'datetime',
        'health_checked_at' => 'datetime',
        'health_latency_ms' => 'integer',
        'health_consecutive_failures' => 'integer',
        'health_down_since_at' => 'datetime',
        'health_last_down_notified_at' => 'datetime',
        'payment_link_providers' => 'array',
        'supported_currencies' => 'array',
        'multi_currency_wallet_enabled' => 'boolean',
        'support_board_token' => 'encrypted',
        'wallet_settings' => 'array',
        'field_activation_deposit_minor' => 'integer',
        'field_trial_duration_days' => 'integer',
        'field_trial_product_id' => 'integer',
        'wp_currency_id' => 'integer',
        'field_activation_commission_rate' => 'decimal:4',
        'field_renewal_commission_rate' => 'decimal:4',
        'field_renewal_commission_months' => 'integer',
    ];

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

    public function clientSyncRuns()
    {
        return $this->hasMany(ClientSyncRun::class);
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

    public function primaryCurrency(): string
    {
        $currency = strtoupper(trim((string) $this->currency_code));

        return $currency !== '' ? $currency : 'KES';
    }

    public function supportedCurrencies(): array
    {
        $configured = is_array($this->supported_currencies) ? $this->supported_currencies : [];
        $currencies = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtoupper(trim((string) $value)),
            array_merge([$this->primaryCurrency()], $configured)
        ))));

        return $currencies === [] ? [$this->primaryCurrency()] : $currencies;
    }

    public function isMultiCurrencyWalletEnabled(): bool
    {
        return (bool) $this->multi_currency_wallet_enabled;
    }

    public function effectiveCurrencies(): array
    {
        return $this->isMultiCurrencyWalletEnabled()
            ? $this->supportedCurrencies()
            : [$this->primaryCurrency()];
    }
}
