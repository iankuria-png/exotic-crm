<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class ClientSegmentService
{
    public const SEGMENT_ORDER = [
        'active',
        'suspended',
        'duplicate',
        'churned',
        'verification_pending',
        'never_paid',
        'abandoned_other',
    ];

    public static function keys(): array
    {
        return self::SEGMENT_ORDER;
    }

    public static function isValid(?string $segment): bool
    {
        return in_array((string) $segment, self::SEGMENT_ORDER, true);
    }

    public function applySegment(Builder $query, string $segment): Builder
    {
        $segment = trim($segment);

        if (!self::isValid($segment)) {
            throw new InvalidArgumentException("Unknown client segment [{$segment}].");
        }

        foreach (self::SEGMENT_ORDER as $higherSegment) {
            if ($higherSegment === $segment) {
                break;
            }

            $query->whereNot(function (Builder $builder) use ($higherSegment) {
                $this->applyBasePredicate($builder, $higherSegment);
            });
        }

        if ($segment === 'abandoned_other') {
            return $query;
        }

        return $this->applyBasePredicate($query, $segment);
    }

    public function segmentCounts(Builder $baseQuery): array
    {
        $counts = [];

        foreach (self::SEGMENT_ORDER as $segment) {
            $counts[$segment] = (int) $this->applySegment(clone $baseQuery, $segment)->count();
        }

        return $counts;
    }

    private function applyBasePredicate(Builder $query, string $segment): Builder
    {
        return match ($segment) {
            'active' => $query->active(),
            'suspended' => $query->highRisk(),
            'duplicate' => $query->whereNotNull('duplicate_of'),
            'churned' => ClientFunnelService::applyPaidHistory($query)
                ->whereNot(fn (Builder $builder) => $builder->active()),
            'verification_pending' => $query
                ->where('verified', false)
                ->where('kyc_required', true),
            'never_paid' => ClientFunnelService::applyNoPaidHistory($query),
            'abandoned_other' => $query,
            default => throw new InvalidArgumentException("Unknown client segment [{$segment}]."),
        };
    }
}
