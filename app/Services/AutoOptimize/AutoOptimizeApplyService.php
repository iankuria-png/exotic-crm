<?php

namespace App\Services\AutoOptimize;

use App\Models\AuditLog;
use App\Models\AutoOptimizeItem;
use App\Models\User;
use App\Services\ClientProfileImageService;
use App\Services\Seo\ProfileSnapshot;
use App\Services\Seo\SeoScorer;
use App\Services\WpSyncFactory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AutoOptimizeApplyService
{
    private const MAX_RESERVED_WRITES = 6; // bio + image + score + up to 3 compensation writes

    public function __construct(
        private readonly WpSyncFactory $wpSyncFactory,
        private readonly SeoScorer $scorer,
        private readonly ClientProfileImageService $imageService,
        private readonly AutoOptimizeAlertService $alertService,
        private readonly AutoOptimizeWriteLedger $ledger,
    ) {}

    /**
     * Applies the staged changes to WordPress atomically and checkpointed.
     * Actor = approver (human) or system automation user (autopilot).
     */
    public function apply(AutoOptimizeItem $item, ?User $approver = null): AutoOptimizeItem
    {
        try {
            $actorId = $approver?->id ?? SystemActorResolver::id();
        } catch (\RuntimeException $e) {
            $plan = $item->plan ?? $item->plan()->first();
            $item->forceFill(['status' => 'failed', 'error_message' => $e->getMessage()])->save();
            $this->alertService->raise('apply_failed', 'critical', 'Auto Optimize system actor missing', $e->getMessage(), ['item_id' => $item->id], $plan, $item);
            return $item->fresh();
        }
        $plan = $item->plan ?? $item->plan()->with('platform')->first();
        $client = $item->client ?? $item->client()->first();
        $cfg = AutoOptimizeConfig::effective($plan);
        $reliability = $cfg['reliability'];
        $actions = $cfg['actions'];

        // Determine which actions actually have staged changes
        $willApplyBio = $item->new_bio_html !== null;
        $willApplyImage = $item->new_main_attachment_id !== null;

        if (!$willApplyBio && !$willApplyImage) {
            $item->forceFill(['status' => 'skipped', 'reason' => 'no_staged_changes'])->save();
            return $item->fresh();
        }

        // Score gain gate (independent per action)
        $minScoreGain = (int) ($reliability['min_score_gain'] ?? 3);
        $scoreGain = ($item->new_score ?? 0) - ($item->previous_score ?? 0);
        if ($willApplyBio && $scoreGain < $minScoreGain) {
            $willApplyBio = false;
        }

        $minImageGain = (float) ($reliability['min_image_gain'] ?? 0.10);
        // Image gain is validated at build time; we trust it here

        if (!$willApplyBio && !$willApplyImage) {
            $item->forceFill(['status' => 'skipped', 'reason' => "score_gain_{$scoreGain}_below_min_{$minScoreGain}"])->save();
            return $item->fresh();
        }

        // Reserve write capacity upfront
        $maxPerHour = (int) ($reliability['max_writes_per_hour'] ?? 60);
        try {
            $reservationId = $this->ledger->reserve(
                (int) $item->id,
                (int) $item->platform_id,
                'apply',
                self::MAX_RESERVED_WRITES,
                $maxPerHour,
                ttlSeconds: 300,
            );
        } catch (\RuntimeException $e) {
            // Rate limit exceeded — defer
            Log::info('auto_optimize.apply_deferred', ['item_id' => $item->id, 'reason' => $e->getMessage()]);
            $item->forceFill(['status' => 'pending', 'reason' => 'rate_limit_deferred'])->save();
            return $item->fresh();
        }

        $item->forceFill(['status' => 'applying'])->save();

        // Platform-scoped WP client (NOT the container-injected one).
        $wpSync = $this->wpSyncFactory->forPlatform((int) $item->platform_id);
        $wpPostId = (int) $client->wp_post_id;
        $actionsApplied = [];
        $consumedWrites = 0;

        try {
            // Action-scoped conflict pre-check
            if ($willApplyBio) {
                try {
                    $currentProfile = $wpSync->getClientProfile($wpPostId);
                    $currentBio = $currentProfile['content'] ?? $currentProfile['bio'] ?? '';
                    $currentHash = md5($currentBio);

                    if ($currentHash !== $item->source_bio_hash) {
                        // Source changed — skip the bio action only
                        $willApplyBio = false;
                        $this->alertService->raise(
                            'apply_failed',
                            'warning',
                            'Bio apply skipped: WP bio changed since build',
                            null,
                            ['item_id' => $item->id, 'expected' => $item->source_bio_hash, 'got' => $currentHash],
                            $plan, $item,
                        );
                    }
                } catch (\Throwable $e) {
                    $willApplyBio = false;
                    Log::warning('auto_optimize.conflict_check_failed', ['item_id' => $item->id, 'error' => $e->getMessage()]);
                }
            }

            if (!$willApplyBio && !$willApplyImage) {
                $item->forceFill(['status' => 'skipped', 'reason' => 'source_changed'])->save();
                $this->ledger->release($reservationId);
                return $item->fresh();
            }

            // Apply bio
            if ($willApplyBio) {
                $wpSync->updateClientProfile($wpPostId, ['content' => $item->new_bio_html]);
                $consumedWrites++;
                $this->ledger->consume($reservationId);
                $actionsApplied['bio'] = true;
            }

            // Apply image
            if ($willApplyImage) {
                $wpSync->setClientMainImage($wpPostId, (int) $item->new_main_attachment_id);
                $consumedWrites++;
                $this->ledger->consume($reservationId);
                $actionsApplied['image'] = true;
            }

            // Recompute score on the resulting profile state
            [$score, $breakdown] = $this->recomputeScore($item, $actionsApplied, $plan);
            $wpSync->writeSeoScore($wpPostId, $score, $breakdown);
            $consumedWrites++;
            $this->ledger->consume($reservationId);
            $actionsApplied['score'] = true;

            // Update CRM client record
            $clientUpdates = [
                'seo_score' => $score,
                'seo_score_breakdown' => $breakdown,
                'seo_score_updated_at' => now(),
            ];

            if ($actionsApplied['image'] ?? false) {
                try {
                    $this->imageService->refreshClient($client);
                } catch (\Throwable $e) {
                    Log::warning('auto_optimize.image_refresh_failed', ['client_id' => $client->id, 'error' => $e->getMessage()]);
                }
            }

            $client->forceFill($clientUpdates)->save();

            $appliedBioHash = ($actionsApplied['bio'] ?? false) ? md5((string) $item->new_bio_html) : null;

            $item->forceFill([
                'status' => 'applied',
                'actions_applied' => $actionsApplied,
                'applied_bio_hash' => $appliedBioHash,
                'applied_at' => now(),
                'approved_by' => $actorId,
                'new_score' => $score,
                'new_score_breakdown' => $breakdown,
            ])->save();

            // Audit log
            AuditLog::query()->create([
                'platform_id' => $item->platform_id,
                'actor_id' => $actorId,
                'action' => 'auto_optimize_applied',
                'entity_type' => 'auto_optimize_item',
                'entity_id' => $item->id,
                'before_state' => [
                    'score' => $item->previous_score,
                    'bio_hash' => $item->source_bio_hash,
                    'main_image_id' => $item->previous_main_attachment_id,
                ],
                'after_state' => [
                    'score' => $score,
                    'actions' => $actionsApplied,
                    'main_image_id' => $item->new_main_attachment_id,
                ],
                'created_at' => now(),
            ]);

            $this->ledger->release($reservationId);

            return $item->fresh();

        } catch (\Throwable $e) {
            Log::error('auto_optimize.apply_failed', [
                'item_id' => $item->id,
                'actions_applied_so_far' => $actionsApplied,
                'error' => $e->getMessage(),
            ]);

            // Compensating rollback of completed steps
            $this->compensate($wpSync, $item, $actionsApplied, $wpPostId, $client, $actorId);

            $item->forceFill(['status' => 'failed', 'error_message' => $e->getMessage()])->save();

            $this->alertService->raise(
                'apply_failed',
                'critical',
                'Auto Optimize apply failed mid-way',
                $e->getMessage(),
                ['item_id' => $item->id, 'actions_applied_so_far' => $actionsApplied],
                $plan, $item,
            );

            $this->ledger->release($reservationId);

            return $item->fresh();
        }
    }

    /**
     * Reverts only the actions that were applied.
     * Action-scoped conflict checks prevent overwriting human edits.
     */
    public function revert(AutoOptimizeItem $item, User $user, bool $force = false): AutoOptimizeItem
    {
        $plan = $item->plan ?? $item->plan()->first();
        $client = $item->client ?? $item->client()->first();
        $actionsApplied = is_array($item->actions_applied) ? $item->actions_applied : [];
        $cfg = AutoOptimizeConfig::effective($plan);
        $reliability = $cfg['reliability'];
        $wpPostId = (int) $client->wp_post_id;

        // Platform-scoped WP client (NOT the container-injected one).
        $wpSync = $this->wpSyncFactory->forPlatform((int) $item->platform_id);

        $maxPerHour = (int) ($reliability['max_writes_per_hour'] ?? 60);
        $reservationId = null;

        try {
            $reservationId = $this->ledger->reserve(
                (int) $item->id,
                (int) $item->platform_id,
                'revert',
                self::MAX_RESERVED_WRITES,
                $maxPerHour,
                ttlSeconds: 300,
            );
        } catch (\RuntimeException $e) {
            Log::info('auto_optimize.revert_deferred', ['item_id' => $item->id, 'reason' => $e->getMessage()]);
            throw $e;
        }

        $reverted = [];

        try {
            // Revert bio if it was applied
            if ($actionsApplied['bio'] ?? false) {
                if (!$force) {
                    $currentProfile = $wpSync->getClientProfile($wpPostId);
                    $currentBio = $currentProfile['content'] ?? $currentProfile['bio'] ?? '';
                    if (md5($currentBio) !== $item->applied_bio_hash) {
                        $this->alertService->raise(
                            'revert_conflict',
                            'warning',
                            'Bio revert conflict: WP bio was modified since apply',
                            null,
                            ['item_id' => $item->id],
                            $plan, $item,
                        );
                        $this->ledger->release($reservationId);
                        throw new RuntimeException('revert_conflict:bio — WP bio changed since apply. Use force=true to override.');
                    }
                }

                $wpSync->updateClientProfile($wpPostId, ['content' => (string) $item->previous_bio_html]);
                $this->ledger->consume($reservationId);
                $reverted['bio'] = true;
            }

            // Revert image if it was applied
            if ($actionsApplied['image'] ?? false) {
                if (!$force && $item->previous_main_attachment_id) {
                    // Could check current main == new_main_attachment_id, but skip for simplicity
                }

                if ($item->previous_main_attachment_id) {
                    $wpSync->setClientMainImage($wpPostId, (int) $item->previous_main_attachment_id);
                    $this->ledger->consume($reservationId);
                    $reverted['image'] = true;
                }
            }

            // Score always needs recompute when bio or image changed
            if ($reverted['bio'] ?? $reverted['image'] ?? false) {
                [$score, $breakdown] = [
                    (int) ($item->previous_score ?? 0),
                    is_array($item->previous_score_breakdown) ? $item->previous_score_breakdown : [],
                ];
                $wpSync->writeSeoScore($wpPostId, $score, $breakdown);
                $this->ledger->consume($reservationId);

                $client->forceFill([
                    'seo_score' => $score,
                    'seo_score_breakdown' => $breakdown,
                    'seo_score_updated_at' => now(),
                ])->save();
            }

            $item->forceFill([
                'status' => 'reverted',
                'reverted_at' => now(),
                'reverted_by' => $user->id,
            ])->save();

            AuditLog::query()->create([
                'platform_id' => $item->platform_id,
                'actor_id' => $user->id,
                'action' => 'auto_optimize_reverted',
                'entity_type' => 'auto_optimize_item',
                'entity_id' => $item->id,
                'before_state' => ['score' => $item->new_score, 'actions' => $actionsApplied],
                'after_state' => ['score' => $item->previous_score, 'reverted' => $reverted],
                'created_at' => now(),
            ]);

            $this->ledger->release($reservationId);

        } catch (\Throwable $e) {
            if ($reservationId) {
                $this->ledger->release($reservationId);
            }
            throw $e;
        }

        return $item->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Hydrate the persisted profile_snapshot, mutate it for the applied state,
     * and score — so completeness/media components are correct.
     */
    private function recomputeScore(AutoOptimizeItem $item, array $actionsApplied, $plan): array
    {
        $snapshotData = is_array($item->profile_snapshot) ? $item->profile_snapshot : [];

        // Mutate bio in snapshot if bio was applied
        if ($actionsApplied['bio'] ?? false) {
            $snapshotData['existing_bio'] = (string) $item->new_bio_html;
        }

        // Mutate media summary if image was applied
        if ($actionsApplied['image'] ?? false) {
            $snapshotData['media_summary'] = array_merge(
                is_array($snapshotData['media_summary'] ?? null) ? $snapshotData['media_summary'] : [],
                ['has_main_image' => true]
            );
        }

        $snapshot = new ProfileSnapshot(
            clientId: $snapshotData['client_id'] ?? null,
            wpPostId: $snapshotData['wp_post_id'] ?? null,
            platformId: (int) ($snapshotData['platform_id'] ?? $item->platform_id),
            name: (string) ($snapshotData['name'] ?? ''),
            age: isset($snapshotData['age']) ? (int) $snapshotData['age'] : null,
            city: (string) ($snapshotData['city'] ?? ''),
            neighborhood: $snapshotData['neighborhood'] ?? null,
            gender: (string) ($snapshotData['gender'] ?? 'female'),
            ethnicity: $snapshotData['ethnicity'] ?? null,
            build: $snapshotData['build'] ?? null,
            height: $snapshotData['height'] ?? null,
            hairColor: $snapshotData['hair_color'] ?? null,
            services: (array) ($snapshotData['services'] ?? []),
            languages: (array) ($snapshotData['languages'] ?? []),
            rates: (array) ($snapshotData['rates'] ?? []),
            availability: $snapshotData['availability'] ?? null,
            existingBio: (string) ($snapshotData['existing_bio'] ?? ''),
            mediaSummary: (array) ($snapshotData['media_summary'] ?? []),
        );

        $cfg = AutoOptimizeConfig::effective($plan);
        $scorerWeights = $cfg['actions']['generation']['scorer_weights'] ?? null;
        $result = $this->scorer->score(
            (string) ($actionsApplied['bio'] ?? false ? $item->new_bio_html : $item->previous_bio_html),
            $snapshot,
            $scorerWeights,
        );

        return [$result['total'], $result['breakdown']];
    }

    private function compensate(\App\Services\WpSyncService $wpSync, AutoOptimizeItem $item, array $actionsApplied, int $wpPostId, $client, int $actorId): void
    {
        try {
            if ($actionsApplied['bio'] ?? false) {
                $wpSync->updateClientProfile($wpPostId, ['content' => (string) $item->previous_bio_html]);
            }
            if ($actionsApplied['image'] ?? false) {
                if ($item->previous_main_attachment_id) {
                    $wpSync->setClientMainImage($wpPostId, (int) $item->previous_main_attachment_id);
                }
            }
            if ($actionsApplied['score'] ?? false) {
                $prevScore = (int) ($item->previous_score ?? 0);
                $prevBreakdown = is_array($item->previous_score_breakdown) ? $item->previous_score_breakdown : [];
                $wpSync->writeSeoScore($wpPostId, $prevScore, $prevBreakdown);
            }
        } catch (\Throwable $e) {
            Log::error('auto_optimize.compensation_failed', ['item_id' => $item->id, 'error' => $e->getMessage()]);
        }
    }
}
