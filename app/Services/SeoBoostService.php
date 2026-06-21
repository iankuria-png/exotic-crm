<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SeoBoostBatch;
use App\Models\SeoBoostItem;
use App\Models\SeoBoostTarget;
use App\Support\CityNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SeoBoostService
{
    public function __construct(
        private readonly DealPaymentService $dealPaymentService,
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     * @param array<int, int> $preferredClientIds
     * @return array<string, mixed>
     */
    public function preview(int $platformId, array $targets, int $limit = 80, array $preferredClientIds = []): array
    {
        $normalizedTargets = $this->normalizeTargets($targets);
        $targetKeys = array_column($normalizedTargets, 'canonical_key');
        $targetMap = collect($normalizedTargets)->keyBy('canonical_key');
        $targetTotal = (int) array_sum(array_column($normalizedTargets, 'target_count'));
        $effectiveLimit = min(max($limit, $targetTotal, 1), 200);

        $eligible = $this->eligibleCandidates($platformId)
            ->filter(fn (Client $client): bool => in_array(CityNormalizer::canonicalKey($client->city), $targetKeys, true))
            ->map(function (Client $client) use ($targetMap): array {
                $canonicalKey = CityNormalizer::canonicalKey($client->city) ?: '';
                $target = $targetMap->get($canonicalKey);

                return $this->candidatePayload($client, $canonicalKey, $target['display_city'] ?? $client->city);
            })
            ->values();

        $ranked = $this->rankCandidates($eligible, $preferredClientIds);
        $selectedByCity = [];
        $candidatesByCity = $ranked->groupBy('canonical_key');
        $summaryTargets = [];

        foreach ($normalizedTargets as $target) {
            $cityRows = $candidatesByCity->get($target['canonical_key'], collect())->values();
            $selected = $cityRows->take((int) $target['target_count'])->values();
            $selectedByCity = array_merge($selectedByCity, $selected->all());

            $summaryTargets[] = [
                ...$target,
                'eligible_count' => $cityRows->count(),
                'selected_count' => $selected->count(),
                'shortfall' => max(0, (int) $target['target_count'] - $selected->count()),
            ];
        }

        $selectedIds = collect($selectedByCity)->pluck('client_id')->all();
        $candidates = $ranked
            ->take($effectiveLimit)
            ->values()
            ->map(function (array $candidate, int $index) use ($selectedIds): array {
                $candidate['rank'] = $index + 1;
                $candidate['selected'] = in_array((int) $candidate['client_id'], $selectedIds, true);

                return $candidate;
            })
            ->all();

        return [
            'platform_id' => $platformId,
            'targets' => $summaryTargets,
            'target_count' => $targetTotal,
            'eligible_count' => $ranked->count(),
            'selected_count' => count($selectedByCity),
            'candidates' => $candidates,
            'selected_client_ids' => $selectedIds,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     * @param array<int, int> $selectedClientIds
     * @return array<string, mixed>
     */
    public function createBatch(
        int $platformId,
        int $actorId,
        int $productId,
        ?int $productPriceId,
        int $durationDays,
        array $targets,
        array $selectedClientIds,
        ?string $notes = null
    ): array {
        $platform = Platform::query()->findOrFail($platformId);
        $product = $this->resolveProduct($platformId, $productId);
        $price = $productPriceId ? $this->resolvePrice($product, $productPriceId) : null;
        $normalizedTargets = $this->normalizeTargets($targets);
        $preview = $this->preview($platformId, $normalizedTargets, 200, $selectedClientIds);
        $allowedIds = collect($preview['candidates'])->pluck('client_id')->map(fn ($id) => (int) $id)->all();
        $orderedIds = array_values(array_unique(array_map('intval', $selectedClientIds ?: $preview['selected_client_ids'])));
        $orderedIds = array_values(array_filter($orderedIds, fn (int $id): bool => in_array($id, $allowedIds, true)));

        if (empty($orderedIds)) {
            throw ValidationException::withMessages([
                'selected_client_ids' => 'Select at least one eligible profile for this SEO Boost batch.',
            ]);
        }

        $candidateMap = collect($preview['candidates'])->keyBy('client_id');
        $targetByKey = collect($normalizedTargets)->keyBy('canonical_key');
        $targetCount = (int) array_sum(array_column($normalizedTargets, 'target_count'));

        $batch = DB::transaction(function () use (
            $platform,
            $actorId,
            $product,
            $price,
            $durationDays,
            $normalizedTargets,
            $targetCount,
            $orderedIds,
            $candidateMap,
            $targetByKey,
            $notes
        ): SeoBoostBatch {
            $batch = SeoBoostBatch::create([
                'platform_id' => (int) $platform->id,
                'created_by' => $actorId,
                'product_id' => (int) $product->id,
                'product_price_id' => $price?->id,
                'plan_type' => $this->planType($product),
                'duration_days' => $durationDays,
                'borrow_mode' => 'widen',
                'status' => 'activating',
                'target_count' => $targetCount,
                'selected_count' => count($orderedIds),
                'notes' => $notes,
                'settings' => [
                    'ranking' => ['seo_score', 'verified', 'display_image', 'last_online_at'],
                    'relocate_mode' => false,
                ],
            ]);

            $targetModels = [];
            foreach ($normalizedTargets as $target) {
                $targetModels[$target['canonical_key']] = SeoBoostTarget::create([
                    'batch_id' => (int) $batch->id,
                    'canonical_key' => $target['canonical_key'],
                    'display_city' => $target['display_city'],
                    'target_count' => (int) $target['target_count'],
                    'selected_count' => 0,
                    'activated_count' => 0,
                ]);
            }

            foreach ($orderedIds as $index => $clientId) {
                $candidate = $candidateMap->get($clientId);
                if (!$candidate) {
                    continue;
                }

                $target = $targetModels[$candidate['canonical_key']]
                    ?? $targetModels[$targetByKey->keys()->first()]
                    ?? null;

                SeoBoostItem::create([
                    'batch_id' => (int) $batch->id,
                    'target_id' => $target?->id,
                    'client_id' => $clientId,
                    'source' => 'in_region',
                    'canonical_key' => (string) $candidate['canonical_key'],
                    'display_city' => (string) $candidate['display_city'],
                    'rank' => $index + 1,
                    'quality_score' => $candidate['seo_score'],
                    'score_breakdown' => $candidate['seo_score_breakdown'],
                    'status' => 'selected',
                ]);
            }

            $this->refreshBatchCounts($batch);

            return $batch->fresh(['items.client', 'targets', 'product', 'productPrice']);
        });

        $this->activateBatchItems($batch, $actorId, $price);

        return $this->show($batch->fresh()->id);
    }

    public function show(int $batchId): array
    {
        $batch = SeoBoostBatch::query()
            ->with([
                'platform:id,name',
                'creator:id,name,email,role',
                'product:id,name,display_name,tier',
                'productPrice:id,duration_label,duration_days,price,currency',
                'targets',
                'items.client:id,name,city,display_image_url,main_image_url,verified,seo_score,last_online_at,wp_profile_permalink',
                'items.deal:id,status,activated_at,expires_at,origin,seo_boost_batch_id',
            ])
            ->findOrFail($batchId);

        return [
            'batch' => $batch,
            'summary' => $this->summary($batch),
        ];
    }

    /**
     * @param array<int, int> $dealIds
     */
    public function markExpiredDeals(array $dealIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $dealIds))));
        if (empty($ids)) {
            return;
        }

        $items = SeoBoostItem::query()
            ->whereIn('deal_id', $ids)
            ->whereIn('status', ['active', 'selected', 'activating'])
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        foreach ($items as $item) {
            $item->forceFill([
                'status' => 'expired',
                'expired_at' => now(),
            ])->save();
        }

        foreach ($items->pluck('batch_id')->unique() as $batchId) {
            $batch = SeoBoostBatch::query()->find((int) $batchId);
            if ($batch) {
                $this->refreshBatchCounts($batch);
            }
        }
    }

    private function activateBatchItems(SeoBoostBatch $batch, int $actorId, ?ProductPrice $price): void
    {
        $batch->loadMissing(['items.client.platform', 'product', 'productPrice']);

        foreach ($batch->items()->with('client.platform')->orderBy('rank')->get() as $item) {
            $item->forceFill(['status' => 'activating'])->save();

            try {
                $client = $item->client;
                if (!$client) {
                    throw new \RuntimeException('Client no longer exists.');
                }

                $deal = $this->dealPaymentService->createPendingDealFromCatalog(
                    $client,
                    (int) $batch->product_id,
                    $price?->id,
                    null,
                    $actorId,
                    null
                );

                $catalogAmount = (float) $deal->amount;
                $deal->forceFill([
                    'origin' => 'seo_boost',
                    'seo_boost_batch_id' => (int) $batch->id,
                    'amount' => 0,
                    'original_amount' => $catalogAmount,
                    'discount_source' => 'seo_boost',
                    'duration_days' => (int) $batch->duration_days,
                ])->save();

                $payment = Payment::create([
                    'platform_id' => (int) $deal->platform_id,
                    'product_id' => (int) $deal->product_id,
                    'deal_id' => (int) $deal->id,
                    'client_id' => (int) $client->id,
                    'phone' => $client->phone_normalized,
                    'amount' => 0,
                    'currency' => $deal->currency ?: ($client->platform?->currency_code ?: 'KES'),
                    'transaction_uuid' => sprintf('seo_boost_%d_%d_%s', $batch->id, $item->id, Str::lower(Str::random(8))),
                    'transaction_reference' => sprintf('SEO-BOOST-%d-%d', $batch->id, $item->id),
                    'status' => 'completed',
                    'duration' => $deal->duration,
                    'raw_payload' => [
                        'source' => 'seo_boost',
                        'batch_id' => (int) $batch->id,
                        'item_id' => (int) $item->id,
                    ],
                    'match_confidence' => 'manual',
                    'confirmed_by' => $actorId,
                    'confirmed_at' => now(),
                ]);

                $activated = $this->subscriptionProvisioningService->activateDeal($deal, [
                    'payment' => $payment,
                    'payment_method' => 'free_trial',
                    'duration_days' => (int) $batch->duration_days,
                    'payment_reference' => $payment->transaction_reference,
                    'is_free_trial' => true,
                    'free_trial_approved_by' => 'SEO Boost',
                    'actor_id' => $actorId,
                    'timeline_context' => [
                        'seo_boost_batch_id' => (int) $batch->id,
                        'seo_boost_item_id' => (int) $item->id,
                    ],
                ]);

                $item->forceFill([
                    'deal_id' => (int) $activated->id,
                    'status' => 'active',
                    'activated_at' => $activated->activated_at ?: now(),
                    'expires_at' => $activated->expires_at,
                    'failure_reason' => null,
                ])->save();
            } catch (Throwable $exception) {
                $item->forceFill([
                    'status' => 'failed',
                    'failure_reason' => Str::limit($exception->getMessage(), 1000, ''),
                ])->save();
            }
        }

        $this->refreshBatchCounts($batch->fresh());
    }

    private function eligibleCandidates(int $platformId): Collection
    {
        return Client::query()
            ->with(['activeDeal'])
            ->where('platform_id', $platformId)
            ->notClosed()
            ->whereNull('duplicate_of')
            ->where(function ($query) {
                $query->whereNull('is_high_risk')->orWhere('is_high_risk', false);
            })
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->where(function ($query) {
                $query->where('profile_status', '!=', 'publish')
                    ->orWhere('needs_payment', true)
                    ->orWhere('notactive', true);
            })
            ->where(function ($query) {
                $query->whereNull('source_presence_status')
                    ->orWhere('source_presence_status', 'present');
            })
            ->whereDoesntHave('deals', function ($query) {
                $query->whereIn('status', ['active', 'paid', 'awaiting_payment']);
            })
            ->get();
    }

    private function candidatePayload(Client $client, string $canonicalKey, ?string $displayCity): array
    {
        $hasImage = filled($client->display_image_url) || filled($client->main_image_url);
        $lastOnline = $client->last_online_at ? Carbon::createFromTimestamp((int) $client->last_online_at) : null;
        $score = $client->seo_score !== null ? (int) $client->seo_score : null;
        $reasons = [];

        if ($score !== null) {
            $reasons[] = "SEO {$score}/100";
        } else {
            $reasons[] = 'No SEO score';
        }
        if ((bool) $client->verified) {
            $reasons[] = 'Verified';
        }
        if ($hasImage) {
            $reasons[] = 'Has image';
        }
        if ($lastOnline) {
            $reasons[] = 'Recently seen ' . $lastOnline->diffForHumans(null, true) . ' ago';
        }

        return [
            'client_id' => (int) $client->id,
            'name' => (string) $client->name,
            'city' => (string) $client->city,
            'canonical_key' => $canonicalKey,
            'display_city' => $displayCity ?: $client->city,
            'profile_url' => $client->wp_profile_permalink,
            'display_image_url' => $client->display_image_url ?: $client->main_image_url,
            'verified' => (bool) $client->verified,
            'seo_score' => $score,
            'seo_score_breakdown' => $client->seo_score_breakdown,
            'last_online_at' => $client->last_online_at,
            'quality_rank_score' => $this->qualityRankScore($client),
            'reasons' => $reasons,
        ];
    }

    private function rankCandidates(Collection $candidates, array $preferredClientIds = []): Collection
    {
        $preferred = array_values(array_unique(array_map('intval', $preferredClientIds)));
        $preferredPosition = array_flip($preferred);

        return $candidates
            ->sort(function (array $left, array $right) use ($preferredPosition): int {
                $leftPreferred = $preferredPosition[(int) $left['client_id']] ?? null;
                $rightPreferred = $preferredPosition[(int) $right['client_id']] ?? null;
                if ($leftPreferred !== null || $rightPreferred !== null) {
                    return ($leftPreferred ?? PHP_INT_MAX) <=> ($rightPreferred ?? PHP_INT_MAX);
                }

                return $right['quality_rank_score'] <=> $left['quality_rank_score']
                    ?: strcmp($left['name'], $right['name']);
            })
            ->values();
    }

    private function qualityRankScore(Client $client): int
    {
        $score = $client->seo_score !== null ? ((int) $client->seo_score * 100) : 0;
        $score += (bool) $client->verified ? 500 : 0;
        $score += (filled($client->display_image_url) || filled($client->main_image_url)) ? 300 : 0;
        if ($client->last_online_at) {
            $days = Carbon::createFromTimestamp((int) $client->last_online_at)->diffInDays(now());
            $score += max(0, 180 - min(180, $days * 6));
        }

        return $score;
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     * @return array<int, array{canonical_key: string, display_city: string, target_count: int}>
     */
    private function normalizeTargets(array $targets): array
    {
        $normalized = [];
        foreach ($targets as $target) {
            $displayCity = CityNormalizer::normalizeLabel($target['display_city'] ?? $target['city'] ?? $target['canonical_key'] ?? null, 160);
            $canonical = CityNormalizer::canonicalKey($target['canonical_key'] ?? $displayCity, 160);
            $targetCount = max(1, min(100, (int) ($target['target_count'] ?? 1)));

            if (!$canonical || !$displayCity) {
                continue;
            }

            if (!isset($normalized[$canonical])) {
                $normalized[$canonical] = [
                    'canonical_key' => $canonical,
                    'display_city' => $displayCity,
                    'target_count' => 0,
                ];
            }

            $normalized[$canonical]['target_count'] += $targetCount;
        }

        if (empty($normalized)) {
            throw ValidationException::withMessages([
                'targets' => 'Add at least one city target.',
            ]);
        }

        return array_values($normalized);
    }

    private function resolveProduct(int $platformId, int $productId): Product
    {
        return Product::query()
            ->where('platform_id', $platformId)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->findOrFail($productId);
    }

    private function resolvePrice(Product $product, int $productPriceId): ProductPrice
    {
        return ProductPrice::query()
            ->where('product_id', (int) $product->id)
            ->where('is_active', true)
            ->findOrFail($productPriceId);
    }

    private function planType(Product $product): string
    {
        $candidate = strtolower((string) ($product->tier ?: $product->name));
        if (str_contains($candidate, 'vip')) {
            return 'vip';
        }
        if (str_contains($candidate, 'premium')) {
            return 'premium';
        }

        return 'basic';
    }

    private function refreshBatchCounts(?SeoBoostBatch $batch): void
    {
        if (!$batch) {
            return;
        }

        $items = $batch->items()->get(['status']);
        $activated = $items->whereIn('status', ['active', 'expired'])->count();
        $failed = $items->where('status', 'failed')->count();
        $expired = $items->where('status', 'expired')->count();
        $selected = $items->count();
        $terminal = $selected > 0 && ($failed + $expired) >= $selected;
        $status = $terminal ? 'completed' : ($activated > 0 || $failed > 0 ? 'active' : $batch->status);

        $batch->forceFill([
            'selected_count' => $selected,
            'activated_count' => $activated,
            'failed_count' => $failed,
            'expired_count' => $expired,
            'status' => $status,
            'activated_at' => $batch->activated_at ?: ($activated > 0 ? now() : null),
            'completed_at' => $terminal ? ($batch->completed_at ?: now()) : null,
        ])->save();

        $batch->loadMissing('targets');
        foreach ($batch->targets as $target) {
            $targetItems = $batch->items()->where('target_id', $target->id)->get(['status']);
            $target->forceFill([
                'selected_count' => $targetItems->count(),
                'activated_count' => $targetItems->whereIn('status', ['active', 'expired'])->count(),
            ])->save();
        }
    }

    private function summary(SeoBoostBatch $batch): array
    {
        return [
            'selected' => (int) $batch->selected_count,
            'activated' => (int) $batch->activated_count,
            'failed' => (int) $batch->failed_count,
            'expired' => (int) $batch->expired_count,
            'status' => (string) $batch->status,
        ];
    }
}
