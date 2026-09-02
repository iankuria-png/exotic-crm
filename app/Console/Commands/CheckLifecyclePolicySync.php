<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Services\WpSyncService;
use App\Support\LifecyclePolicy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reports markets where the CRM believes the SEO lifecycle policy is on but the
 * WordPress site has never been told.
 *
 * `platforms.lifecycle_policy_enabled` is a statement of intent. What actually
 * stops the theme's legacy 5-minute `check_expired()` sweep from privatising
 * lapsed profiles — and deleting their `escort_expire` — is the
 * `exotic_crm_lifecycle_policy_enabled` wp_option on that site.
 *
 * That option is pushed from exactly one place: the market's lifecycle toggle in
 * CRM settings (SettingsController). The column's migration default is false, so
 * any market switched on by SQL, tinker or a seeder carries the CRM flag while
 * its WordPress site still runs the destructive sweep. Nothing read the option
 * back, so the divergence was invisible — a market could look protected in the
 * CRM for months while WordPress kept expiring profiles on a raw timestamp with
 * no end-of-day grace.
 *
 * Read-only: this command performs GET requests and writes nothing, anywhere.
 * Use `crm:sync-lifecycle-policy` or the market's settings toggle to correct a
 * divergence.
 */
class CheckLifecyclePolicySync extends Command
{
    protected $signature = 'crm:check-lifecycle-policy-sync
        {--platform= : Restrict to a single platform id}
        {--include-inactive : Also check platforms flagged inactive}';

    protected $description = 'Report markets whose WordPress site disagrees with the CRM about the lifecycle policy (read-only).';

    private const STATE_OK = 'ok';
    private const STATE_ARMED = 'sweep_armed';
    private const STATE_UNEXPECTED_STANDDOWN = 'unexpected_standdown';
    private const STATE_UNREACHABLE = 'unreachable';

    public function handle(): int
    {
        $platformId = $this->option('platform') !== null ? (int) $this->option('platform') : null;

        $platforms = Platform::query()
            ->when($platformId, fn ($query, $id) => $query->where('id', (int) $id))
            ->when(
                ! $platformId && ! $this->option('include-inactive'),
                fn ($query) => $query->where('is_active', true)
            )
            ->orderBy('id')
            ->get();

        if ($platforms->isEmpty()) {
            $this->warn('No matching platforms.');

            return self::SUCCESS;
        }

        $masterEnabled = LifecyclePolicy::masterEnabled();
        $this->info(sprintf(
            'Lifecycle master switch: %s. Checking %d market(s)…',
            $masterEnabled ? 'ON' : 'OFF (every market behaves as legacy regardless of its flag)',
            $platforms->count()
        ));
        $this->newLine();

        $rows = [];
        $summary = [self::STATE_OK => 0, self::STATE_ARMED => 0, self::STATE_UNEXPECTED_STANDDOWN => 0, self::STATE_UNREACHABLE => 0];

        foreach ($platforms as $platform) {
            $row = $this->inspect($platform, $masterEnabled);
            $summary[$row['state']]++;
            $rows[] = [
                $platform->id,
                $platform->name,
                $row['crm'],
                $row['wp'],
                $row['verdict'],
            ];
        }

        $this->table(['ID', 'Market', 'CRM flag', 'WordPress option', 'Verdict'], $rows);

        $this->newLine();
        $this->info(sprintf(
            'ok=%d sweep_armed=%d unexpected_standdown=%d unreachable=%d',
            $summary[self::STATE_OK],
            $summary[self::STATE_ARMED],
            $summary[self::STATE_UNEXPECTED_STANDDOWN],
            $summary[self::STATE_UNREACHABLE]
        ));

        if ($summary[self::STATE_ARMED] > 0) {
            $this->newLine();
            $this->error(sprintf(
                '%d market(s) still run the legacy WordPress expiry sweep despite the CRM believing the lifecycle policy is on.',
                $summary[self::STATE_ARMED]
            ));
            $this->line('That sweep privatises lapsed profiles and DELETES escort_expire, with no market-timezone end-of-day grace.');
            $this->line('Fix each by re-saving its lifecycle toggle in CRM settings, which pushes the option to WordPress.');
        }

        // Non-zero only for an armed sweep: that is the state that loses paid access.
        // Unreachable markets are reported but must not fail a scheduled check.
        return $summary[self::STATE_ARMED] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{state:string, crm:string, wp:string, verdict:string}
     */
    private function inspect(Platform $platform, bool $masterEnabled): array
    {
        $crmEnabled = $platform->lifecycleEnabled();
        $crmLabel = $crmEnabled
            ? 'on'
            : ((bool) $platform->lifecycle_policy_enabled && ! $masterEnabled ? 'on (master off)' : 'off');

        if (trim((string) $platform->wp_api_url) === '') {
            return [
                'state' => self::STATE_UNREACHABLE,
                'crm' => $crmLabel,
                'wp' => '—',
                'verdict' => 'no wp_api_url configured',
            ];
        }

        try {
            $response = WpSyncService::forPlatform((int) $platform->id)->getLifecyclePolicy();
        } catch (Throwable $e) {
            return [
                'state' => self::STATE_UNREACHABLE,
                'crm' => $crmLabel,
                'wp' => '?',
                'verdict' => 'unreachable: '.$this->summariseError($e),
            ];
        }

        // A build predating the endpoint returns no `enabled` key at all. Treat that
        // as "the option cannot be honoured here", which is the dangerous direction.
        if (! array_key_exists('enabled', $response)) {
            return [
                'state' => $crmEnabled ? self::STATE_ARMED : self::STATE_OK,
                'crm' => $crmLabel,
                'wp' => 'not reported',
                'verdict' => $crmEnabled
                    ? 'SWEEP ARMED — plugin too old to report the option'
                    : 'legacy on both sides',
            ];
        }

        $wpEnabled = filter_var($response['enabled'], FILTER_VALIDATE_BOOLEAN);

        if ($crmEnabled && ! $wpEnabled) {
            return [
                'state' => self::STATE_ARMED,
                'crm' => $crmLabel,
                'wp' => 'off',
                'verdict' => 'SWEEP ARMED — WordPress never received the option',
            ];
        }

        if (! $crmEnabled && $wpEnabled) {
            return [
                'state' => self::STATE_UNEXPECTED_STANDDOWN,
                'crm' => $crmLabel,
                'wp' => 'on',
                'verdict' => 'WP stood down but CRM expects legacy — expiry may be owned by nobody',
            ];
        }

        return [
            'state' => self::STATE_OK,
            'crm' => $crmLabel,
            'wp' => $wpEnabled ? 'on' : 'off',
            'verdict' => $wpEnabled ? 'in sync (lifecycle)' : 'in sync (legacy)',
        ];
    }

    private function summariseError(Throwable $e): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $e->getMessage()));

        return mb_strimwidth($message, 0, 70, '…');
    }
}
