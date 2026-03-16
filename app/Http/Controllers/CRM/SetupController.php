<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Models\Platform;
use App\Models\User;
use App\Services\ClientSyncService;
use App\Services\MarketAuthorizationService;
use App\Services\NotificationService;
use App\Services\WpSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SetupController extends Controller
{
    private const SETUP_COMPLETED_KEY = 'setup_completed';
    private const DATA_BASELINE_KEY = 'data_baseline_mode';
    private const HEARTBEAT_FILE = 'app/scheduler-heartbeat.json';

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly NotificationService $notificationService
    ) {
    }

    public function status()
    {
        $database = $this->databaseStatus();
        $isFirstRun = true;

        if ($database['connected']) {
            try {
                $isFirstRun = !$this->setupCompleted();
            } catch (QueryException) {
                $isFirstRun = true;
            }
        }

        return response()->json([
            'is_first_run' => $isFirstRun,
            'db_connected' => $database['connected'],
            'pending_migrations' => $database['pending_migrations'],
            'has_setup_token' => filled(config('app.setup_token')),
        ]);
    }

    public function checkEnv()
    {
        $checks = [
            'php_version' => [
                'label' => 'PHP version',
                'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'actual' => PHP_VERSION,
                'expected' => '8.1.0+',
            ],
            'extensions' => [
                'label' => 'Required PHP extensions',
                'ok' => empty($missingExtensions = $this->missingExtensions()),
                'missing' => $missingExtensions,
                'expected' => ['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json'],
            ],
            'storage_writable' => [
                'label' => 'Writable storage and cache directories',
                'ok' => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')),
                'paths' => [
                    'storage' => storage_path(),
                    'bootstrap_cache' => base_path('bootstrap/cache'),
                ],
            ],
            'app_key' => [
                'label' => 'Application key',
                'ok' => filled(config('app.key')),
            ],
        ];

        return response()->json([
            'ok' => collect($checks)->every(fn (array $check) => (bool) ($check['ok'] ?? false)),
            'checks' => $checks,
        ]);
    }

    public function checkDatabase()
    {
        return response()->json($this->databaseStatus());
    }

    public function runMigrations(Request $request)
    {
        $this->validateSetupToken($request);

        if ($this->setupCompleted()) {
            return response()->json([
                'message' => 'Setup has already been completed.',
            ], 409);
        }

        $database = $this->databaseStatus();
        if (!$database['connected']) {
            return response()->json([
                'message' => 'Database connection failed. Fix the credentials before running migrations.',
                'database' => $database,
            ], 422);
        }

        if ((int) ($database['pending_migrations'] ?? 0) < 1) {
            return response()->json([
                'message' => 'No pending migrations were found.',
                'database' => $database,
            ], 422);
        }

        Artisan::call('migrate', ['--force' => true]);

        return response()->json([
            'message' => 'Migrations completed successfully.',
            'output' => trim(Artisan::output()),
            'database' => $this->databaseStatus(),
        ]);
    }

    public function createAdmin(Request $request)
    {
        $this->validateSetupToken($request);

        if ($this->setupCompleted()) {
            return response()->json([
                'message' => 'Setup has already been completed.',
            ], 409);
        }

        if (User::query()->where('role', 'admin')->exists()) {
            return response()->json([
                'message' => 'A CRM admin already exists. Sign in instead of creating another setup admin.',
            ], 409);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:255',
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);

        $token = $user->createToken('crm-setup')->plainTextToken;

        return response()->json([
            'message' => 'Admin account created.',
            'token' => $token,
            'user' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status ?? 'active',
            ],
        ], 201);
    }

    public function checkPlatform(Request $request)
    {
        $platform = $this->resolveAccessiblePlatform($request);

        return response()->json($this->performPlatformCheck($platform));
    }

    public function runSync(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'per_page' => 'nullable|integer|min:20|max:200',
        ]);

        $platform = $this->resolveAccessiblePlatform($request, (int) $validated['platform_id']);

        if (!$this->platformHasWpCredentials($platform)) {
            return response()->json([
                'message' => 'WordPress sync credentials are incomplete for this market.',
            ], 422);
        }

        $perPage = (int) ($validated['per_page'] ?? 100);
        $syncResult = (new ClientSyncService($platform))->fullSync($perPage);
        $payload = [
            'scope' => 'clients',
            'mode' => 'full',
            'ran_at' => now()->toDateTimeString(),
            'clients' => $syncResult,
        ];

        $platform->forceFill([
            'sync_last_synced_at' => now(),
            'sync_last_scope' => 'clients',
            'sync_last_status' => 'success',
            'sync_last_error' => null,
            'sync_last_result' => $payload,
        ])->save();

        return response()->json([
            'status' => 'success',
            'result' => $payload,
            'platform' => $this->serializePlatformIntegration($platform->fresh()),
        ]);
    }

    public function runDiagnostics(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'sms_phone' => 'nullable|string|max:20',
            'sms_message' => 'nullable|string|max:500',
        ]);

        $platformDiagnostic = [
            'status' => 'skipped',
            'message' => 'No market selected for WordPress diagnostics.',
        ];

        if (!empty($validated['platform_id'])) {
            $platform = $this->resolveAccessiblePlatform($request, (int) $validated['platform_id']);
            $platformDiagnostic = $this->performPlatformCheck($platform);
        }

        $smsDiagnostic = $this->smsDiagnostic(
            $validated['sms_phone'] ?? null,
            $validated['sms_message'] ?? null
        );

        return response()->json([
            'platform' => $platformDiagnostic,
            'sms' => $smsDiagnostic,
            'payment_proxy' => $this->paymentProxyDiagnostic(),
            'scheduler' => $this->schedulerHeartbeat(),
            'data_baseline' => $this->currentDataBaseline(),
        ]);
    }

    public function complete(Request $request)
    {
        $validated = $request->validate([
            'data_baseline' => 'nullable|array',
            'data_baseline.mode' => 'required_with:data_baseline|in:include_legacy,fresh_start',
            'data_baseline.cutoff_date' => 'nullable|date',
        ]);

        $updatedBy = (int) $request->user()->id;
        $completedValue = [
            'completed' => true,
            'completed_at' => now()->toIso8601String(),
        ];

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SETUP_COMPLETED_KEY],
            [
                'value' => $completedValue,
                'updated_by' => $updatedBy,
            ]
        );

        $baseline = $validated['data_baseline'] ?? $this->currentDataBaseline();
        $baseline = [
            'mode' => $baseline['mode'] ?? 'fresh_start',
            'cutoff_date' => $baseline['cutoff_date'] ?? now()->toDateString(),
        ];

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::DATA_BASELINE_KEY],
            [
                'value' => $baseline,
                'updated_by' => $updatedBy,
            ]
        );

        return response()->json([
            'message' => 'Setup marked as complete.',
            'setup_completed' => $completedValue,
            'data_baseline' => $baseline,
        ]);
    }

    private function validateSetupToken(Request $request): void
    {
        $token = config('app.setup_token');
        if (empty($token) || $request->header('X-Setup-Token') !== $token) {
            Log::warning('Setup token validation failed', [
                'ip' => $request->ip(),
            ]);

            abort(403, 'Invalid setup token');
        }
    }

    private function databaseStatus(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'connected' => true,
                'pending_migrations' => $this->pendingMigrationCount(),
                'table_count' => $this->tableCount(),
                'connection' => config('database.default'),
            ];
        } catch (\Throwable $exception) {
            return [
                'connected' => false,
                'pending_migrations' => null,
                'table_count' => 0,
                'connection' => config('database.default'),
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function pendingMigrationCount(): int
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');
        $paths = array_merge([database_path('migrations')], $migrator->paths());
        $files = $migrator->getMigrationFiles($paths);

        if (!$migrator->repositoryExists()) {
            return count($files);
        }

        $ran = $migrator->getRepository()->getRan();

        return count(array_diff(array_keys($files), $ran));
    }

    private function tableCount(): int
    {
        try {
            return count(DB::select('SHOW TABLES'));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function missingExtensions(): array
    {
        $required = ['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json'];

        return array_values(array_filter($required, static fn (string $extension) => !extension_loaded($extension)));
    }

    private function setupCompleted(): bool
    {
        try {
            return IntegrationSetting::query()
                ->where('key', self::SETUP_COMPLETED_KEY)
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }

    private function resolveAccessiblePlatform(Request $request, ?int $platformId = null): Platform
    {
        $validated = $platformId
            ? ['platform_id' => $platformId]
            : $request->validate([
                'platform_id' => 'required|integer|exists:platforms,id',
            ]);

        $resolvedId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $resolvedId,
            'You do not have access to this market.'
        );

        return Platform::query()->findOrFail($resolvedId);
    }

    private function performPlatformCheck(Platform $platform): array
    {
        if (!$this->platformHasWpCredentials($platform)) {
            return [
                'status' => 'pending',
                'message' => 'WordPress sync credentials are incomplete for this market.',
                'platform' => $this->serializePlatformIntegration($platform),
            ];
        }

        try {
            $stats = (new WpSyncService($platform))->getStats();

            $platform->forceFill([
                'sync_last_checked_at' => now(),
                'sync_last_status' => 'healthy',
                'sync_last_error' => null,
            ])->save();

            return [
                'status' => 'healthy',
                'checked_at' => optional($platform->fresh()->sync_last_checked_at)->toDateTimeString(),
                'stats' => $stats,
                'platform' => $this->serializePlatformIntegration($platform->fresh()),
            ];
        } catch (\Throwable $exception) {
            $platform->forceFill([
                'sync_last_checked_at' => now(),
                'sync_last_status' => 'error',
                'sync_last_error' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();

            return [
                'status' => 'error',
                'message' => 'Connection test failed. Check credentials and API reachability.',
                'error' => $exception->getMessage(),
                'platform' => $this->serializePlatformIntegration($platform->fresh()),
            ];
        }
    }

    private function serializePlatformIntegration(Platform $platform): array
    {
        return app(SettingsController::class)->serializePlatformIntegration($platform);
    }

    private function platformHasWpCredentials(Platform $platform): bool
    {
        return filled($platform->wp_api_url)
            && filled($platform->wp_api_user)
            && filled($platform->wp_api_password);
    }

    private function smsDiagnostic(?string $phone, ?string $message): array
    {
        $config = $this->notificationService->currentSmsConfig(masked: true);
        $activeProvider = (string) ($config['active_provider'] ?? 'legacy_gateway');
        $ready = match ($activeProvider) {
            'africastalking' => filled($config['africastalking']['username'] ?? null)
                && (bool) ($config['africastalking']['api_key_configured'] ?? false),
            default => filled($config['legacy_gateway']['gateway_url'] ?? null)
                && filled($config['legacy_gateway']['org_code'] ?? null),
        };

        $status = $ready
            ? ((bool) ($config['enabled'] ?? false) ? 'connected' : 'configured_disabled')
            : 'pending';

        $diagnostic = [
            'status' => $status,
            'enabled' => (bool) ($config['enabled'] ?? false),
            'active_provider' => $activeProvider,
            'config' => $config,
        ];

        if (filled($phone) && filled($message)) {
            $dispatch = $this->notificationService->sendSms($phone, $message, [
                'purpose' => 'setup_diagnostic',
            ]);
            $diagnostic['dispatch'] = $dispatch;
            $diagnostic['status'] = ($dispatch['success'] ?? false)
                ? (($dispatch['status'] ?? 'success') === 'disabled' ? 'configured_disabled' : 'healthy')
                : 'error';
        }

        return $diagnostic;
    }

    private function paymentProxyDiagnostic(): array
    {
        $baseUrl = trim((string) config('services.django.base_url', ''));
        if ($baseUrl === '') {
            return [
                'status' => 'pending',
                'base_url' => null,
                'message' => 'Payment proxy URL is not configured.',
            ];
        }

        try {
            $response = Http::timeout(10)->get($baseUrl);

            return [
                'status' => $response->serverError() ? 'error' : 'healthy',
                'base_url' => $baseUrl,
                'http_status' => $response->status(),
                'message' => $response->serverError()
                    ? 'Payment proxy responded with a server error.'
                    : 'Payment proxy is reachable.',
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'base_url' => $baseUrl,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function schedulerHeartbeat(): array
    {
        $path = storage_path(self::HEARTBEAT_FILE);
        if (!is_file($path)) {
            return [
                'status' => 'missing',
                'last_ran_at' => null,
                'message' => 'No scheduler heartbeat has been recorded yet.',
                'cron_command' => '* * * * * cd /home/d9410/crm.exotic-online.com && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1',
            ];
        }

        $payload = json_decode((string) file_get_contents($path), true);
        $lastRanAt = is_array($payload) ? ($payload['ran_at'] ?? null) : null;
        $timestamp = $lastRanAt ? strtotime((string) $lastRanAt) : @filemtime($path);
        $ageSeconds = $timestamp ? max(0, time() - $timestamp) : null;

        return [
            'status' => $ageSeconds !== null && $ageSeconds <= 300 ? 'healthy' : 'stale',
            'last_ran_at' => $lastRanAt,
            'age_seconds' => $ageSeconds,
            'message' => $ageSeconds !== null && $ageSeconds <= 300
                ? 'Scheduler heartbeat is recent.'
                : 'Scheduler heartbeat is stale. Check the cron job.',
            'cron_command' => '* * * * * cd /home/d9410/crm.exotic-online.com && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1',
        ];
    }

    private function currentDataBaseline(): array
    {
        $default = [
            'mode' => 'fresh_start',
            'cutoff_date' => now()->toDateString(),
        ];

        try {
            $value = IntegrationSetting::query()
                ->where('key', self::DATA_BASELINE_KEY)
                ->value('value');
        } catch (QueryException) {
            $value = null;
        }

        if (!is_array($value)) {
            return $default;
        }

        return [
            'mode' => in_array(($value['mode'] ?? ''), ['include_legacy', 'fresh_start'], true)
                ? $value['mode']
                : $default['mode'],
            'cutoff_date' => filled($value['cutoff_date'] ?? null)
                ? $value['cutoff_date']
                : $default['cutoff_date'],
        ];
    }
}
