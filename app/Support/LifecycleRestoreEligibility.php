<?php

namespace App\Support;

use App\Models\Client;
use App\Services\ClientFunnelService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Configurable eligibility for SEO Recovery.
 *
 * Which offline profiles get republished is a business judgement that changes
 * per market and per run, so nothing here is hardcoded: the whole filter set is
 * a value object built from the run's `filters` JSON, which means every batch
 * records exactly what it selected on and can be replayed or audited later.
 *
 * The base clause is not configurable — it is what "was taken offline by the
 * legacy sweep" means, and relaxing it would target profiles this feature has
 * no business touching.
 */
final class LifecycleRestoreEligibility
{
    /** Only profiles that were genuinely live and paid for. Recovers real assets. */
    public const HISTORY_PAID = 'paid_history';

    /** Anything with evidence of ever having been live, including lapsed free trials. */
    public const HISTORY_PREVIOUSLY_PUBLISHED = 'previously_published';

    /** Everything with a WP profile — including profiles that were never live. */
    public const HISTORY_ANY = 'any_wp_profile';

    public const HISTORY_MODES = [
        self::HISTORY_PAID,
        self::HISTORY_PREVIOUSLY_PUBLISHED,
        self::HISTORY_ANY,
    ];

    /**
     * Close reasons that must never be republished: these profiles were closed
     * because the content or the contact was a problem, not because a
     * subscription lapsed.
     */
    public const BAD_CLOSE_REASONS = [
        CrmClientCloseReason::INAPPROPRIATE,
        CrmClientCloseReason::INVALID_CONTACT,
        CrmClientCloseReason::DUPLICATE,
        CrmClientCloseReason::PAYMENT_ISSUE,
    ];

    public string $historyMode;
    public bool $excludeHighRisk;
    public bool $excludeDuplicates;
    public bool $excludeBadCloseReasons;
    public bool $requireVerified;
    public bool $requireImage;
    public ?int $minSeoScore;
    public ?int $expiredWithinMonths;

    public function __construct(array $filters = [])
    {
        $mode = (string) ($filters['history_mode'] ?? self::HISTORY_PAID);
        $this->historyMode = in_array($mode, self::HISTORY_MODES, true) ? $mode : self::HISTORY_PAID;

        // Safety toggles default ON: the safe batch is the default batch.
        $this->excludeHighRisk = self::boolOption($filters, 'exclude_high_risk', true);
        $this->excludeDuplicates = self::boolOption($filters, 'exclude_duplicates', true);
        $this->excludeBadCloseReasons = self::boolOption($filters, 'exclude_bad_close_reasons', true);

        // Quality filters default OFF. `require_verified` in particular would
        // zero most cohorts — legacy profiles predate KYC entirely.
        $this->requireVerified = self::boolOption($filters, 'require_verified', false);
        $this->requireImage = self::boolOption($filters, 'require_image', false);
        $this->minSeoScore = self::intOption($filters, 'min_seo_score');
        $this->expiredWithinMonths = self::intOption($filters, 'expired_within_months');
    }

    public static function fromRun(?array $filters): self
    {
        return new self($filters ?? []);
    }

    /**
     * Candidates for one market. Always scoped to a single platform: a run
     * targets one WordPress site and republishing is per-site work.
     */
    public function query(int $platformId): Builder
    {
        $query = Client::query()
            ->where('platform_id', $platformId)
            // The legacy sweep's fingerprint: taken offline, not deleted.
            ->where('profile_status', 'private')
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            // An open case is still being worked; closed cases are the backlog.
            ->whereNull('closed_at')
            // Never touch anything already carrying a lifecycle state — those
            // are handled by the live lifecycle, not the backfill.
            ->where(function (Builder $builder) {
                $builder->whereNull('lifecycle_state')
                    ->orWhere('lifecycle_state', ClientLifecycleState::ACTIVE);
            })
            // Idempotency: a profile restored by an earlier run stays restored.
            ->whereNull('lifecycle_restored_at');

        $this->applyHistoryMode($query);
        $this->applySafetyToggles($query);
        $this->applyQualityFilters($query);

        return $query;
    }

    private function applyHistoryMode(Builder $query): void
    {
        match ($this->historyMode) {
            self::HISTORY_PAID => ClientFunnelService::applyPaidHistory($query),

            // "Reached publish once" is not recorded anywhere as a historical
            // fact, so we use the evidence that a profile was once live:
            // it was activated, it has a deal, or it churned.
            self::HISTORY_PREVIOUSLY_PUBLISHED => $query->where(function (Builder $builder) {
                $builder->whereNotNull('first_activated_at')
                    ->orWhereNotNull('churned_at')
                    ->orWhereHas('deals');
            }),

            // No additional constraint — the base clause is the whole filter.
            self::HISTORY_ANY => $query,
        };
    }

    private function applySafetyToggles(Builder $query): void
    {
        if ($this->excludeHighRisk) {
            $query->where(function (Builder $builder) {
                $builder->whereNull('is_high_risk')->orWhere('is_high_risk', false);
            });
        }

        if ($this->excludeDuplicates) {
            $query->whereNull('duplicate_of');
        }

        if ($this->excludeBadCloseReasons) {
            $query->where(function (Builder $builder) {
                $builder->whereNull('close_reason_code')
                    ->orWhereNotIn('close_reason_code', self::BAD_CLOSE_REASONS);
            });
        }
    }

    private function applyQualityFilters(Builder $query): void
    {
        if ($this->requireVerified) {
            $query->where('verified', true);
        }

        if ($this->requireImage) {
            $query->where(function (Builder $builder) {
                $builder->whereNotNull('main_image_url')
                    ->orWhereNotNull('display_image_url');
            });
        }

        if ($this->minSeoScore !== null) {
            $query->where('seo_score', '>=', $this->minSeoScore);
        }

        if ($this->expiredWithinMonths !== null && $this->expiredWithinMonths > 0) {
            $cutoff = now()->subMonths($this->expiredWithinMonths);

            // Mirrors the dating rule's sources so "expired within N months"
            // means the same thing here as it does when the landing state is
            // resolved. `updated_at` is the same last-resort fallback.
            $query->where(function (Builder $builder) use ($cutoff) {
                $builder->whereHas('deals', fn (Builder $deal) => $deal->where('expires_at', '>=', $cutoff))
                    ->orWhereHas('payments', fn (Builder $payment) => $payment->where('end_date', '>=', $cutoff))
                    ->orWhere('churned_at', '>=', $cutoff)
                    ->orWhere(function (Builder $fallback) use ($cutoff) {
                        $fallback->whereNull('churned_at')
                            ->whereDoesntHave('deals')
                            ->whereDoesntHave('payments')
                            ->where('updated_at', '>=', $cutoff);
                    });
            });
        }
    }

    /** Normalised config, stored on the run so a batch is auditable and repeatable. */
    public function toArray(): array
    {
        return [
            'history_mode' => $this->historyMode,
            'exclude_high_risk' => $this->excludeHighRisk,
            'exclude_duplicates' => $this->excludeDuplicates,
            'exclude_bad_close_reasons' => $this->excludeBadCloseReasons,
            'require_verified' => $this->requireVerified,
            'require_image' => $this->requireImage,
            'min_seo_score' => $this->minSeoScore,
            'expired_within_months' => $this->expiredWithinMonths,
        ];
    }

    private static function boolOption(array $filters, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $filters)) {
            return $default;
        }

        return filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function intOption(array $filters, string $key): ?int
    {
        if (! array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
            return null;
        }

        return (int) $filters[$key];
    }
}
