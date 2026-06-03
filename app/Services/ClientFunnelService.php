<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class ClientFunnelService
{
    public const PROFILE_COMPLETE_SEO_FLOOR = 1;
    public const PAID_DEAL_STATUSES = ['active', 'paid', 'expired', 'renewed'];

    public function build(Builder $baseQuery): array
    {
        $stageQueries = [
            'new' => clone $baseQuery,
            'signed_up' => self::applySignedUp(clone $baseQuery),
            'profile_completed' => self::applyProfileCompleted(clone $baseQuery),
            'paid' => self::applyPaidHistory(self::applyProfileCompleted(clone $baseQuery)),
            'retained' => self::applyPaidHistory(self::applyProfileCompleted(clone $baseQuery))->active(),
        ];

        $labels = [
            'new' => 'New',
            'signed_up' => 'Signed up',
            'profile_completed' => 'Profile completed',
            'paid' => 'Paid',
            'retained' => 'Retained',
        ];

        $counts = [];
        foreach ($stageQueries as $key => $query) {
            $counts[$key] = (int) $query->count();
        }

        $stages = $this->formatStages($counts, $labels);

        return [
            'stages' => $stages,
            'totals' => [
                'total' => $counts['new'],
                'paid' => $counts['paid'],
                'retained' => $counts['retained'],
            ],
            'annotations' => [
                'paid_offpath' => (int) self::applyPaidHistory(clone $baseQuery)
                    ->whereNot(fn (Builder $query) => self::applyProfileCompleted($query))
                    ->count(),
                'active_unpaid' => (int) self::applyNoPaidHistory((clone $baseQuery)->active())->count(),
                'payment_failed_only' => (int) self::applyNoPaidHistory(
                    (clone $baseQuery)->whereHas('payments')
                )->count(),
                'churned' => (int) self::applyPaidHistory(clone $baseQuery)
                    ->whereNot(fn (Builder $query) => $query->active())
                    ->count(),
            ],
        ];
    }

    public static function applySignedUp(Builder $query): Builder
    {
        return $query
            ->whereNotNull('wp_post_id')
            ->whereIn('profile_status', ['publish', 'private', 'pending']);
    }

    public static function applyProfileCompleted(Builder $query): Builder
    {
        return self::applySignedUp($query)
            ->where(function (Builder $builder) {
                $builder->whereNotNull('display_image_url')
                    ->orWhereNotNull('main_image_url');
            })
            ->where('seo_score', '>=', self::PROFILE_COMPLETE_SEO_FLOOR);
    }

    public static function applyPaidHistory(Builder $query): Builder
    {
        return $query->where(function (Builder $paid) {
            $paid->whereHas('payments', fn (Builder $paymentQuery) => $paymentQuery->reportableSuccessful())
                ->orWhereHas('deals', function (Builder $dealQuery) {
                    $dealQuery->whereIn('status', self::PAID_DEAL_STATUSES);
                });
        });
    }

    public static function applyNoPaidHistory(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('payments', fn (Builder $paymentQuery) => $paymentQuery->reportableSuccessful())
            ->whereDoesntHave('deals', function (Builder $dealQuery) {
                $dealQuery->whereIn('status', self::PAID_DEAL_STATUSES);
            });
    }

    private function formatStages(array $counts, array $labels): array
    {
        $total = (int) ($counts['new'] ?? 0);
        $previousStageCount = null;
        $stages = [];

        foreach ($labels as $key => $label) {
            $count = (int) ($counts[$key] ?? 0);
            $conversionFromPrevious = null;
            $dropoffFromPrevious = null;

            if ($previousStageCount !== null && $previousStageCount > 0) {
                $conversionFromPrevious = round(($count / $previousStageCount) * 100, 1);
                $dropoffFromPrevious = max(0, round(100 - $conversionFromPrevious, 1));
            }

            $stages[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'share_of_total' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                'conversion_from_previous' => $conversionFromPrevious,
                'dropoff_from_previous' => $dropoffFromPrevious,
            ];

            $previousStageCount = $count;
        }

        return $stages;
    }
}
