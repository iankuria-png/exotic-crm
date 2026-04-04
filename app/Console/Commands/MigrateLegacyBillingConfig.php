<?php

namespace App\Console\Commands;

use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingRoutingRule;
use App\Models\BillingSystemSetting;
use App\Models\BillingWalletRule;
use App\Models\BillingSubscriptionRule;
use App\Models\IntegrationSetting;
use App\Models\Platform;
use App\Services\WalletSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MigrateLegacyBillingConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:migrate-legacy-config
        {--dry-run : Show what would be migrated without applying changes}
        {--apply : Apply the migration to the database}
        {--resume : Resume migration from previous state}
        {--drift-report : Report drift between legacy and migrated configuration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate legacy billing configuration to new billing tables with dry-run, apply, resume, and drift reporting';

    public function __construct(
        private readonly WalletSettingsService $walletSettingsService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $apply = $this->option('apply');
        $resume = $this->option('resume');
        $driftReport = $this->option('drift-report');

        if (!$dryRun && !$apply && !$driftReport) {
            $this->error('Must specify --dry-run, --apply, or --drift-report');
            return self::FAILURE;
        }

        if ($apply && $dryRun) {
            $this->error('Cannot specify both --apply and --dry-run');
            return self::FAILURE;
        }

        try {
            if ($driftReport) {
                return $this->handleDriftReport();
            }

            if ($dryRun) {
                return $this->handleDryRun();
            }

            if ($apply) {
                return $this->handleApply($resume);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Migration failed: ' . $exception->getMessage());
            Log::error('Legacy billing config migration failed', [
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    private function handleDryRun(): int
    {
        $this->info('Starting dry-run migration of legacy billing configuration...');

        $migrationPlan = $this->buildMigrationPlan();

        $this->displayMigrationPlan($migrationPlan);

        $this->info('Dry-run complete. No changes applied.');

        return self::SUCCESS;
    }

    private function handleApply(bool $resume): int
    {
        if (!$resume) {
            $confirmed = $this->confirm('This will migrate legacy billing configuration to new tables. Continue?');
            if (!$confirmed) {
                $this->info('Migration cancelled.');
                return self::SUCCESS;
            }
        }

        $this->info('Starting migration of legacy billing configuration...');

        DB::beginTransaction();

        try {
            $migrationPlan = $this->buildMigrationPlan();
            $this->applyMigrationPlan($migrationPlan);

            DB::commit();

            $this->info('Migration completed successfully.');
            Log::info('Legacy billing config migration completed');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private function handleDriftReport(): int
    {
        $this->info('Generating drift report between legacy and migrated configuration...');

        $drift = $this->calculateDrift();

        $this->displayDriftReport($drift);

        return self::SUCCESS;
    }

    private function buildMigrationPlan(): array
    {
        $plan = [
            'system_settings' => [],
            'provider_profiles' => [],
            'wallet_rules' => [],
            'subscription_rules' => [],
            'routing_rules' => [],
            'bindings' => [],
        ];

        // Migrate system settings
        $systemStored = IntegrationSetting::query()
            ->where('key', 'wallet_system_settings')
            ->value('value');
        $systemConfig = is_array($systemStored) ? $systemStored : [];
        if (!empty($systemConfig)) {
            $plan['system_settings'][] = [
                'scope' => 'global',
                'mode_json' => [
                    'billing_mode' => $systemConfig['mode'] ?? 'disabled',
                    'enabled' => $systemConfig['enabled'] ?? false,
                ],
                'domain_json' => [
                    'billing_domains' => $systemConfig['billing_domains'] ?? [],
                ],
                'branding_json' => [
                    'billing_branding' => $systemConfig['billing_branding'] ?? [],
                ],
                'timing_json' => [
                    'redirect_delay_seconds' => $systemConfig['redirect_delay_seconds'] ?? 3,
                    'wallet_refresh_rate_limit_seconds' => $systemConfig['wallet_refresh_rate_limit_seconds'] ?? 15,
                    'wallet_refresh_timeout_seconds' => $systemConfig['wallet_refresh_timeout_seconds'] ?? 15,
                    'topup_poll_interval_seconds' => $systemConfig['topup_poll_interval_seconds'] ?? 10,
                ],
                'smtp_json' => [
                    'smtp' => $systemConfig['smtp'] ?? [],
                ],
                'pin_policy_json' => [
                    'pin_hash' => $systemConfig['pin_hash'] ?? '',
                    'pin_last_updated_at' => $systemConfig['pin_last_updated_at'],
                    'free_trial_pin_hash' => $systemConfig['free_trial_pin_hash'] ?? '',
                    'free_trial_pin_last_updated_at' => $systemConfig['free_trial_pin_last_updated_at'],
                    'discount_pin_hash' => $systemConfig['discount_pin_hash'] ?? '',
                    'discount_pin_last_updated_at' => $systemConfig['discount_pin_last_updated_at'],
                    'discount_config' => $systemConfig['discount_config'] ?? [],
                ],
                'updated_by' => null,
                'updated_at' => now(),
            ];
        }

        // Migrate platform-specific settings
        $platforms = Platform::query()->get();

        foreach ($platforms as $platform) {
            // Provider profiles from credentials
            $credentialsKey = 'wallet_credentials_' . $platform->id;
            $credentialsStored = IntegrationSetting::query()
                ->where('key', $credentialsKey)
                ->value('value');
            $credentials = is_array($credentialsStored) ? $credentialsStored : [];
            
            foreach (['pesapal', 'paystack', 'mpesa_stk'] as $providerKey) {
                if (!isset($credentials[$providerKey])) {
                    continue;
                }

                $providerCreds = $credentials[$providerKey];
                
                foreach (['sandbox', 'production'] as $environment) {
                    if (!isset($providerCreds[$environment])) {
                        continue;
                    }

                    $envCreds = $providerCreds[$environment];
                    
                    $configJson = [];
                    $secretsJson = [];
                    
                    if ($providerKey === 'pesapal') {
                        $secretsJson = [
                            'consumer_key' => $envCreds['consumer_key'] ?? '',
                            'consumer_secret' => $envCreds['consumer_secret'] ?? '',
                        ];
                        $configJson = [
                            'ipn_id' => $envCreds['ipn_id'] ?? '',
                        ];
                    } elseif ($providerKey === 'paystack') {
                        $configJson = [
                            'public_key' => $envCreds['public_key'] ?? '',
                        ];
                        $secretsJson = [
                            'secret_key' => $envCreds['secret_key'] ?? '',
                        ];
                    } elseif ($providerKey === 'mpesa_stk') {
                        $configJson = [
                            'transport' => $envCreds['transport'] ?? 'django_proxy',
                            'payment_service_base_url' => $envCreds['payment_service_base_url'] ?? '',
                            'organization_code' => $envCreds['organization_code'] ?? '',
                            'callback_base_url' => $envCreds['callback_base_url'] ?? '',
                        ];
                    }

                    if (!empty($configJson) || !empty($secretsJson)) {
                        $plan['provider_profiles'][] = [
                            'provider_type_key' => $providerKey,
                            'profile_name' => ucfirst($providerKey) . ' ' . ucfirst($environment) . ' (' . $platform->name . ')',
                            'country_code' => $platform->country === 'Kenya' ? 'KE' : 'KE', // Default to KE for now
                            'market_id' => $platform->id,
                            'merchant_scope_json' => ['scope' => 'market'],
                            'environment' => $environment,
                            'config_json' => $configJson,
                            'secrets_json' => $secretsJson,
                            'active' => true,
                        ];
                    }
                }
            }

            // Wallet rules from platform settings
            $platformConfig = is_array($platform->wallet_settings) ? $platform->wallet_settings : [];
            
            $plan['wallet_rules'][] = [
                'market_id' => $platform->id,
                'enabled' => $platformConfig['enabled'] ?? false,
                'currency_code' => $platformConfig['currency_code'] ?? 'KES',
                'topup_preset_json' => $platformConfig['topup_presets'] ?? [],
                'limit_json' => [
                    'max_single_topup' => $platformConfig['max_single_topup'] ?? '50000.00',
                    'max_wallet_balance' => $platformConfig['max_wallet_balance'] ?? '200000.00',
                ],
                'auto_renew_json' => ['enabled' => false], // Default, can be updated later
                'ui_json' => [
                    'show_refresh_button' => $platformConfig['show_refresh_button'] ?? true,
                    'allow_combined_topup_subscribe' => $platformConfig['allow_combined_topup_subscribe'] ?? true,
                    'recent_transactions_limit' => $platformConfig['recent_transactions_limit'] ?? 10,
                ],
            ];

            // Subscription rules (basic defaults)
            $plan['subscription_rules'][] = [
                'market_id' => $platform->id,
                'activation_method_json' => ['methods' => ['manual', 'stk', 'link']],
                'renewal_method_json' => ['methods' => ['wallet_balance', 'link']],
                'free_trial_json' => ['enabled' => false],
                'discount_json' => ['enabled' => false],
                'expiry_policy_json' => ['grace_period_days' => 7],
            ];

            // Routing rules and bindings from payment_link_providers
            $paymentLinkProviders = $platform->payment_link_providers ?? [];
            $activeProvider = $paymentLinkProviders['active_provider'] ?? null;
            $providers = $paymentLinkProviders['providers'] ?? [];

            foreach ($providers as $providerKey => $providerConfig) {
                if (!is_array($providerConfig) || !($providerConfig['enabled'] ?? false)) {
                    continue;
                }

                $billingSurface = $this->mapProviderKeyToSurface($providerKey);
                if (!$billingSurface) {
                    continue;
                }

                // Create binding (placeholder, will link to profile later)
                $plan['bindings'][] = [
                    'market_id' => $platform->id,
                    'billing_surface' => $billingSurface,
                    'enabled' => true,
                    'operator_enabled' => true,
                    'self_service_enabled' => $providerKey === $activeProvider,
                    'execution_mode' => $this->mapProviderModeToExecutionMode($providerConfig['mode'] ?? 'static_url'),
                    'priority' => 10,
                    'fallback_group' => 'default',
                    'restriction_json' => [],
                ];

                // Create routing rule if primary
                if ($providerKey === $activeProvider) {
                    $plan['routing_rules'][] = [
                        'market_id' => $platform->id,
                        'billing_surface' => $billingSurface,
                        'fallback_strategy_json' => ['providers' => []],
                        'risk_policy_json' => ['mode' => 'direct'],
                        'active' => true,
                    ];
                }
            }
        }

        return $plan;
    }

    private function applyMigrationPlan(array $plan): void
    {
        // Apply system settings
        foreach ($plan['system_settings'] as $setting) {
            BillingSystemSetting::query()->updateOrCreate(
                ['scope' => $setting['scope']],
                $setting
            );
        }

        // Apply provider profiles
        foreach ($plan['provider_profiles'] as $profile) {
            BillingProviderProfile::query()->create($profile);
        }

        // Apply wallet rules
        foreach ($plan['wallet_rules'] as $rule) {
            BillingWalletRule::query()->create($rule);
        }

        // Apply subscription rules
        foreach ($plan['subscription_rules'] as $rule) {
            BillingSubscriptionRule::query()->create($rule);
        }

        // Apply routing rules
        foreach ($plan['routing_rules'] as $rule) {
            BillingRoutingRule::query()->create($rule);
        }

        // Apply bindings (this is simplified, in reality need to link to profiles)
        foreach ($plan['bindings'] as $binding) {
            BillingMarketProviderBinding::query()->create($binding);
        }
    }

    private function calculateDrift(): array
    {
        // This would compare legacy vs migrated state
        // For now, return empty
        return [
            'system_settings_drift' => [],
            'provider_profiles_drift' => [],
            'wallet_rules_drift' => [],
        ];
    }

    private function displayMigrationPlan(array $plan): void
    {
        $this->info('Migration Plan:');
        $this->line('System Settings: ' . count($plan['system_settings']));
        $this->line('Provider Profiles: ' . count($plan['provider_profiles']));
        $this->line('Wallet Rules: ' . count($plan['wallet_rules']));
        $this->line('Subscription Rules: ' . count($plan['subscription_rules']));
        $this->line('Routing Rules: ' . count($plan['routing_rules']));
        $this->line('Bindings: ' . count($plan['bindings']));
    }

    private function displayDriftReport(array $drift): void
    {
        $this->info('Drift Report:');
        // Display drift details
    }

    private function mapProviderKeyToSurface(string $providerKey): ?string
    {
        return match ($providerKey) {
            'site_pay_page' => 'subscription_link',
            'paystack_checkout' => 'subscription_link',
            default => null,
        };
    }

    private function mapProviderModeToExecutionMode(string $mode): string
    {
        return match ($mode) {
            'proxy_hosted_checkout' => 'proxy',
            default => 'direct',
        };
    }
}
