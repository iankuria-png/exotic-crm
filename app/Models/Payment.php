<?php

namespace App\Models;

use App\Services\ClientRetentionInsightService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;


class Payment extends Model
{
    use HasFactory;

    private static array $schemaSupportCache = [];

    protected static function booted(): void
    {
        $refresh = static function (Payment $payment): void {
            $clientId = $payment->client_id
                ? (int) $payment->client_id
                : (int) (Deal::query()->whereKey($payment->deal_id)->value('client_id') ?: 0);

            ClientRetentionInsightService::scheduleRefreshForClientId($clientId > 0 ? $clientId : null);
        };

        static::saved($refresh);
        static::deleted($refresh);
    }

    public const SUCCESSFUL_STATUSES = ['completed', 'expired'];
    public const ACTIVE_SUBSCRIPTION_STATUSES = ['completed', 'activated'];
    public const RESENDABLE_LINK_STATUSES = ['initiated'];
    public const REPLACEMENT_REQUIRED_STATUSES = ['failed'];
    public const RECORD_CLASSIFICATION_LIVE = 'live';
    public const RECORD_CLASSIFICATION_TEST = 'test';
    public const RESOLUTION_REVERSED = 'reversed';
    public const RESOLUTION_INVALID_REFERENCE = 'invalid_reference';

    protected $fillable = [
        'user_id',
        'product_id',
        'platform_id',
        'escort_post_id',
        'manual_payment_bundle_id',
        'deal_id',
        'client_id',
        'match_confidence',
        'confirmed_by',
        'confirmed_at',
        'phone',
        'amount',
        'currency',
        'transaction_uuid',
        'transaction_reference',
        'reference_number',
        'reference_root',
        'reference_sequence',
        'status',
        'purpose',
        'failure_reason',
        'completed_at',
        'source',
        'wallet_transaction_id',
        'provider_key',
        'provider_environment',
        'record_classification',
        'resolution_code',
        'resolution_meta_json',
        'test_reason',
        'test_marked_at',
        'test_marked_by',
        'import_batch_id',
        'import_legacy_hash',
        'reconciliation_confidence',
        'reconciliation_state',
        'raw_payload',
        'payment_data',
        'duration',
        'start_date',
        'end_date'
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'payment_data' => 'array',
        'resolution_meta_json' => 'array',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'test_marked_at' => 'datetime',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];
    
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
    public static function hasActiveSubscription($userId)
    {
        return Payment::where('user_id', $userId)
            ->whereIn('status', self::ACTIVE_SUBSCRIPTION_STATUSES)
            ->where('end_date', '>', now())
            ->exists();
    }
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function manualPaymentBundle()
    {
        return $this->belongsTo(ManualPaymentBundle::class, 'manual_payment_bundle_id');
    }

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function testMarkedBy()
    {
        return $this->belongsTo(User::class, 'test_marked_by');
    }

    public function attempts()
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function routingDecisions()
    {
        return $this->hasMany(BillingRoutingDecision::class);
    }

    public function providerTransactions()
    {
        return $this->hasMany(BillingProviderTransaction::class);
    }

    public function proxySessions()
    {
        return $this->hasMany(BillingProxySession::class);
    }

    public function importBatch()
    {
        return $this->belongsTo(PaymentImportBatch::class, 'import_batch_id');
    }

    public function manualSubmission()
    {
        return $this->hasOne(PaymentManualSubmission::class);
    }

    public function scopeExcludingWalletTopups($query)
    {
        return $query->where(function ($builder) {
            $builder->whereNull('purpose')
                ->orWhere('purpose', '!=', 'wallet_topup');
        });
    }

    public function scopeWalletTopups($query)
    {
        return $query->where('purpose', 'wallet_topup');
    }

    public function scopeSandboxTest(Builder $query): Builder
    {
        $providerEnvironmentColumn = self::qualifiedColumn($query, 'provider_environment');
        $paymentDataTestModeColumn = self::qualifiedJsonColumn($query, 'payment_data', 'test_mode');

        return $query->where(function (Builder $builder) use ($providerEnvironmentColumn, $paymentDataTestModeColumn) {
            $builder->whereRaw("LOWER(COALESCE({$providerEnvironmentColumn}, '')) = ?", ['sandbox'])
                ->orWhere($paymentDataTestModeColumn, true);
        });
    }

    public function scopeExplicitTests(Builder $query): Builder
    {
        return $query->where(self::qualifiedColumn($query, 'record_classification'), self::RECORD_CLASSIFICATION_TEST);
    }

    public function scopeWorkspaceVisible(Builder $query, string $testVisibility = 'hide'): Builder
    {
        return match ($testVisibility) {
            'include' => $this->applyNonBusinessBundleVisibilityGuard($query),
            'only' => $query->testsOnly(),
            default => $query->businessVisible(),
        };
    }

    public function scopeBusinessVisible(Builder $query): Builder
    {
        $recordClassificationColumn = self::qualifiedColumn($query, 'record_classification');
        $providerEnvironmentColumn = self::qualifiedColumn($query, 'provider_environment');
        $paymentDataTestModeColumn = self::qualifiedJsonColumn($query, 'payment_data', 'test_mode');

        $query = $query
            ->where(function (Builder $builder) use ($recordClassificationColumn) {
                $builder->whereNull($recordClassificationColumn)
                    ->orWhere($recordClassificationColumn, '!=', self::RECORD_CLASSIFICATION_TEST);
            })
            ->where(function (Builder $builder) use ($providerEnvironmentColumn) {
                $builder->whereNull($providerEnvironmentColumn)
                    ->orWhereRaw("LOWER({$providerEnvironmentColumn}) != ?", ['sandbox']);
            })
            ->where(function (Builder $builder) use ($paymentDataTestModeColumn) {
                $builder->whereNull($paymentDataTestModeColumn)
                    ->orWhere($paymentDataTestModeColumn, false);
            });

        return $this->applyNonBusinessBundleVisibilityGuard($query);
    }

    public function scopeTestsOnly(Builder $query): Builder
    {
        $recordClassificationColumn = self::qualifiedColumn($query, 'record_classification');
        $providerEnvironmentColumn = self::qualifiedColumn($query, 'provider_environment');
        $paymentDataTestModeColumn = self::qualifiedJsonColumn($query, 'payment_data', 'test_mode');

        $query = $query->where(function (Builder $builder) use ($recordClassificationColumn, $providerEnvironmentColumn, $paymentDataTestModeColumn) {
            $builder->where($recordClassificationColumn, self::RECORD_CLASSIFICATION_TEST)
                ->orWhere(function (Builder $legacy) use ($providerEnvironmentColumn, $paymentDataTestModeColumn) {
                    $legacy->whereRaw("LOWER(COALESCE({$providerEnvironmentColumn}, '')) = ?", ['sandbox'])
                        ->orWhere($paymentDataTestModeColumn, true);
                });
        });

        return $this->applyNonBusinessBundleVisibilityGuard($query);
    }

    public function scopeReportableSuccessful(Builder $query): Builder
    {
        $statusColumn = self::qualifiedColumn($query, 'status');
        $reconciliationStateColumn = self::qualifiedColumn($query, 'reconciliation_state');
        $resolutionCodeColumn = self::qualifiedColumn($query, 'resolution_code');

        $query = $query
            ->businessVisible()
            ->whereIn($statusColumn, self::SUCCESSFUL_STATUSES)
            ->where(function (Builder $builder) use ($reconciliationStateColumn) {
                $builder->whereNull($reconciliationStateColumn)
                    ->orWhere($reconciliationStateColumn, '!=', 'manual_review');
            });

        if (self::supportsResolutionCode()) {
            $query->where(function (Builder $builder) use ($resolutionCodeColumn) {
                $builder->whereNull($resolutionCodeColumn)
                    ->orWhereNotIn($resolutionCodeColumn, ['reversed', 'invalid_reference']);
            });
        }

        return $query;
    }

    public function scopeOperationalQueueVisible(Builder $query): Builder
    {
        return $query
            ->businessVisible()
            ->whereIn(self::qualifiedColumn($query, 'reconciliation_state'), ['open', 'manual_review']);
    }

    public function scopeLiveOnly(Builder $query): Builder
    {
        return $query->businessVisible();
    }

    public function isSandboxTest(): bool
    {
        return strtolower(trim((string) $this->provider_environment)) === 'sandbox'
            || (bool) data_get($this->payment_data, 'test_mode', false);
    }

    public function isClassifiedTest(): bool
    {
        return (string) $this->record_classification === self::RECORD_CLASSIFICATION_TEST;
    }

    private function applyNonBusinessBundleVisibilityGuard(Builder $query): Builder
    {
        if (!self::supportsBundleVisibilityGuard()) {
            return $query;
        }

        return $query->where(function (Builder $builder) {
            $builder->whereNull('manual_payment_bundle_id')
                ->orWhereNotExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('manual_payment_bundles')
                        ->whereColumn('manual_payment_bundles.id', 'payments.manual_payment_bundle_id')
                        ->whereIn('manual_payment_bundles.status', ['committing', 'compensation_failed', 'voided']);
                });
        });
    }

    private static function supportsBundleVisibilityGuard(): bool
    {
        return self::schemaSupport('bundle_visibility_guard', static function (): bool {
            return Schema::hasColumn('payments', 'manual_payment_bundle_id')
                && Schema::hasTable('manual_payment_bundles');
        });
    }

    private static function supportsResolutionCode(): bool
    {
        return self::schemaSupport('resolution_code', static fn (): bool => Schema::hasColumn('payments', 'resolution_code'));
    }

    private static function schemaSupport(string $key, callable $resolver): bool
    {
        if (!array_key_exists($key, self::$schemaSupportCache)) {
            self::$schemaSupportCache[$key] = (bool) $resolver();
        }

        return self::$schemaSupportCache[$key];
    }

    private static function qualifiedColumn(Builder $query, string $column): string
    {
        return $query->getModel()->qualifyColumn($column);
    }

    private static function qualifiedJsonColumn(Builder $query, string $column, string $path): string
    {
        return self::qualifiedColumn($query, $column) . '->' . $path;
    }
}
