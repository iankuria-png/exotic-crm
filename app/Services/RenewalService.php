<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Deal;
use App\Models\RenewalCampaign;
use App\Models\RenewalRun;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RenewalService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly TemplateService $templateService,
        private readonly AuditService $auditService
    ) {
    }

    public function buildOverview(array $filters = [], int $perPage = 50, ?User $viewer = null): array
    {
        // 1. Build the unified query for Subscriptions (Deals + Virtual Deals from Clients)
        // We include ALL private status clients to scale visibility to the full 4k+ records.
        $query = Client::query()
            ->with(['platform', 'assignedAgent', 'activeDeal.product'])
            ->leftJoin('deals', function ($join) {
                // Join to the latest active/expired deal if one exists
                $join->on('clients.id', '=', 'deals.client_id')
                    ->whereIn('deals.status', ['active', 'expired'])
                    ->whereRaw('deals.id = (SELECT id FROM deals d2 WHERE d2.client_id = clients.id ORDER BY d2.created_at DESC LIMIT 1)');
            })
            ->select('clients.*', 'deals.id as deal_id', 'deals.status as deal_status', 'deals.expires_at as deal_expires_at', 'deals.product_id', 'deals.amount', 'deals.currency')
            ->where(function ($q) {
                $q->whereNotNull('deals.id')
                    ->orWhereNotNull('clients.escort_expire')
                    ->orWhere('clients.profile_status', 'private');
            });

        // Add telemetry counters (reminders)
        $query->selectSub(function ($builder) {
            $builder->from('timeline_events')
                ->selectRaw('COUNT(*)')
                ->where(function ($sub) {
                    $sub->where(function ($sq) {
                        $sq->where('entity_type', 'deal')->whereColumn('entity_id', 'deals.id');
                    })->orWhere(function ($sq) {
                        $sq->where('entity_type', 'client')->whereColumn('entity_id', 'clients.id');
                    });
                })
                ->where('event_type', 'renewal_sms_sent');
        }, 'reminders_sent_count')
            ->selectSub(function ($builder) {
                $builder->from('timeline_events')
                    ->selectRaw('COUNT(*)')
                    ->where(function ($sub) {
                        $sub->where(function ($sq) {
                            $sq->where('entity_type', 'deal')->whereColumn('entity_id', 'deals.id');
                        })->orWhere(function ($sq) {
                            $sq->where('entity_type', 'client')->whereColumn('entity_id', 'clients.id');
                        });
                    })
                    ->where('event_type', 'renewal_sms_failed');
            }, 'reminders_failed_count')
            ->selectSub(function ($builder) {
                $builder->from('timeline_events')
                    ->select('created_at')
                    ->where(function ($sub) {
                        $sub->where(function ($sq) {
                            $sq->where('entity_type', 'deal')->whereColumn('entity_id', 'deals.id');
                        })->orWhere(function ($sq) {
                            $sq->where('entity_type', 'client')->whereColumn('entity_id', 'clients.id');
                        });
                    })
                    ->whereIn('event_type', ['renewal_sms_sent', 'renewal_sms_failed'])
                    ->orderByDesc('created_at')
                    ->limit(1);
            }, 'last_renewal_reminder_at');

        // Apply shared filters
        if (!empty($filters['platform_ids']) && is_array($filters['platform_ids'])) {
            $query->whereIn('clients.platform_id', $filters['platform_ids']);
        } elseif (!empty($filters['platform_id'])) {
            $query->where('clients.platform_id', (int) $filters['platform_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('clients.name', 'like', "%{$search}%")
                    ->orWhere('clients.phone_normalized', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['bucket'])) {
            $this->applyBucketFilter($query, (string) $filters['bucket']);
        }

        /** @var \Illuminate\Pagination\LengthAwarePaginator $targets */
        $targets = $query
            ->orderByRaw('COALESCE(deals.expires_at, clients.escort_expire) DESC')
            ->paginate($perPage)
            ->through(function (Client $client) {
                // If a real deal exists, we use it for primary data
                $expiryValue = $client->deal_expires_at ?: $client->escort_expire;
                $expiryDate = $expiryValue ? \Carbon\Carbon::parse($expiryValue) : null;
                $daysLeft = $this->daysUntil($expiryDate);

                // For virtual renewals (no deal), we consider them "active" if not expired
                $status = $client->deal_status ?: ($daysLeft !== null && $daysLeft < 0 ? 'expired' : 'active');

                // Paused status only really applies to deals for now
                $remindersPaused = $client->activeDeal ? $this->isReminderPaused($client->activeDeal) : false;

                $renewalBucket = $remindersPaused
                    ? 'paused'
                    : ($daysLeft !== null && $daysLeft < 0
                        ? $this->bucketForDaysExpired($daysLeft)
                        : $this->bucketForDays($daysLeft));

                // Catch-all: If private but no date, it is 'lapsed'
                if (!$expiryDate && $client->profile_status === 'private') {
                    $renewalBucket = 'lapsed';
                    $status = 'expired';
                }

                $originType = $client->deal_id ? 'modern' : 'legacy';
                $paymentStatus = 'unlinked';

                if ($client->deal_id) {
                    $paymentExists = \App\Models\Payment::where('deal_id', $client->deal_id)
                        ->whereIn('status', ['completed', 'success'])
                        ->exists();
                    $paymentStatus = $paymentExists ? 'verified' : 'unlinked';
                }

                $record = $client->toArray();
                return array_merge($record, [
                    'id' => $client->deal_id, // frontend expects deal ID or null
                    'client_id' => $client->id,
                    'client' => $record, // NESTED CLIENT to fix 'Unknown' rendering
                    'is_virtual' => !$client->deal_id,
                    'origin_type' => $originType,
                    'payment_status' => $paymentStatus,
                    'expires_at' => $expiryDate ? $expiryDate->toDateTimeString() : null,
                    'status' => $status,
                    'days_left' => $daysLeft,
                    'renewal_bucket' => $renewalBucket,
                    'reminders_sent_count' => (int) ($client->reminders_sent_count ?? 0),
                    'reminders_failed_count' => (int) ($client->reminders_failed_count ?? 0),
                    'last_renewal_reminder_at' => $client->last_renewal_reminder_at,
                    'reminders_paused' => $remindersPaused,
                    'renewal_paused_until' => $client->activeDeal ? optional($client->activeDeal->renewal_paused_until)->toDateTimeString() : null,
                    'renewal_pause_reason' => $client->activeDeal ? $client->activeDeal->renewal_pause_reason : null,
                ]);
            });

        // 2. Build Summary Counts (Global Stats based on current filters)
        $summaryBase = Client::query()
            ->leftJoin('deals', function ($join) {
                $join->on('clients.id', '=', 'deals.client_id')
                    ->whereIn('deals.status', ['active', 'expired'])
                    ->whereRaw('deals.id = (SELECT id FROM deals d2 WHERE d2.client_id = clients.id ORDER BY d2.created_at DESC LIMIT 1)');
            })
            ->where(function ($q) {
                $q->whereNotNull('deals.id')
                    ->orWhereNotNull('clients.escort_expire')
                    ->orWhere('clients.profile_status', 'private');
            });

        // Apply shared filters to summary for "Global Stats"
        if (!empty($filters['platform_ids']) && is_array($filters['platform_ids'])) {
            $summaryBase->whereIn('clients.platform_id', $filters['platform_ids']);
        } elseif (!empty($filters['platform_id'])) {
            $summaryBase->where('clients.platform_id', (int) $filters['platform_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $summaryBase->where(function ($q) use ($search) {
                $q->where('clients.name', 'like', "%{$search}%")
                    ->orWhere('clients.phone_normalized', 'like', "%{$search}%");
            });
        }

        $nowTs = now()->timestamp;
        $dateExpr = 'COALESCE(UNIX_TIMESTAMP(deals.expires_at), clients.escort_expire)';

        $summary = [
            'active_deals' => (int) (clone $summaryBase)
                ->where(function ($q) use ($nowTs, $dateExpr) {
                    $q->where('deals.status', 'active')
                        ->orWhere(function ($sq) use ($nowTs, $dateExpr) {
                            $sq->whereNull('deals.id')->where(DB::raw($dateExpr), '>=', $nowTs);
                        });
                })
                ->count(),
            'modern_active_count' => (int) (clone $summaryBase)
                ->where('deals.status', 'active')
                ->whereNotNull('deals.id')
                ->count(),
            'risk' => (int) (clone $summaryBase)
                ->whereBetween(DB::raw($dateExpr), [$nowTs, $nowTs + (3 * 86400)])
                ->count(),
            'pending' => (int) (clone $summaryBase)
                ->whereBetween(DB::raw($dateExpr), [$nowTs + (4 * 86400), $nowTs + (14 * 86400)])
                ->count(),
            'renewed_this_month' => (int) Deal::query()
                ->whereNotNull('activated_at')
                ->where('activated_at', '>=', now()->startOfMonth())
                ->when($filters['platform_id'] ?? null, fn($q) => $q->where('platform_id', $filters['platform_id']))
                ->count(),
            'paused_reminders' => (int) (clone $summaryBase)->where('deals.renewal_reminders_paused', true)->count(),
            'expired_deals' => (int) (clone $summaryBase)
                ->whereBetween(DB::raw($dateExpr), [$nowTs - (14 * 86400), $nowTs - 1])
                ->count(),
            'lapsed_deals' => (int) (clone $summaryBase)
                ->where(function ($q) use ($nowTs, $dateExpr) {
                    $q->where(DB::raw($dateExpr), '<', $nowTs - (14 * 86400))
                        ->orWhere(function ($sq) {
                            $sq->whereNull('deals.id')
                                ->where('clients.profile_status', 'private')
                                ->whereNull('clients.escort_expire');
                        });
                })
                ->count(),
            'pipeline_value' => (float) (clone $summaryBase)
                ->whereIn('deals.status', ['pending', 'awaiting_payment', 'paid', 'active'])
                ->sum('deals.amount'),
            'verified_revenue' => (float) (clone $summaryBase)
                ->where('deals.status', 'active')
                ->whereNotNull('deals.payment_id')
                ->sum('deals.amount'),
        ];

        $campaigns = RenewalCampaign::query()
            ->with('template:id,title,channel,status')
            ->orderBy('trigger_days')
            ->get();

        $recentRuns = RenewalRun::query()
            ->with(['campaign.template:id,title', 'runner:id,name'])
            ->when(
                $viewer && $viewer->role !== MarketAuthorizationService::ROLE_ADMIN,
                fn(Builder $builder) => $builder->where('run_by', $viewer->id)
            )
            ->orderByDesc('run_at')
            ->limit(10)
            ->get();

        return [
            'summary' => $summary,
            'targets' => $targets,
            'campaigns' => $campaigns,
            'recent_runs' => $recentRuns,
        ];
    }

    public function bulkRemind(array $selection, bool $selectAll = false, array $filters = [], ?int $templateId = null, ?int $actorId = null): array
    {
        $targets = [];

        if ($selectAll) {
            // Rebuild query with filters to get ALL matching targets
            $query = Client::query()
                ->leftJoin('deals', function ($join) {
                    $join->on('clients.id', '=', 'deals.client_id')
                        ->whereIn('deals.status', ['active', 'expired'])
                        ->whereRaw('deals.id = (SELECT id FROM deals d2 WHERE d2.client_id = clients.id ORDER BY d2.created_at DESC LIMIT 1)');
                })
                ->select('clients.id as client_id', 'deals.id as deal_id', 'deals.expires_at as deal_expires_at', 'clients.escort_expire')
                ->where(function ($q) {
                    $q->whereNotNull('deals.id')
                        ->orWhereNotNull('clients.escort_expire')
                        ->orWhere('clients.profile_status', 'private');
                });

            if (!empty($filters['platform_ids']) && is_array($filters['platform_ids'])) {
                $query->whereIn('clients.platform_id', $filters['platform_ids']);
            } elseif (!empty($filters['platform_id'])) {
                $query->where('clients.platform_id', (int) $filters['platform_id']);
            }

            if (!empty($filters['search'])) {
                $search = trim((string) $filters['search']);
                $query->where(function ($q) use ($search) {
                    $q->where('clients.name', 'like', "%{$search}%")
                        ->orWhere('clients.phone_normalized', 'like', "%{$search}%");
                });
            }

            if (!empty($filters['bucket'])) {
                $this->applyBucketFilter($query, (string) $filters['bucket']);
            }

            $targets = $query->get()->map(function ($row) {
                return [
                    'deal_id' => $row->deal_id,
                    'client_id' => $row->client_id,
                    'is_virtual' => !$row->deal_id,
                    'expires_at' => $row->deal_expires_at ?: $row->escort_expire
                ];
            })->toArray();
        } else {
            $targets = $selection;
        }

        $sent = 0;
        $failed = 0;

        foreach ($targets as $target) {
            try {
                if (!empty($target['deal_id'])) {
                    $deal = Deal::query()->with('client.platform')->findOrFail((int) $target['deal_id']);
                } else {
                    $client = Client::query()->with('platform')->findOrFail((int) $target['client_id']);
                    $deal = new Deal();
                    $deal->client_id = $client->id;
                    $deal->platform_id = $client->platform_id;
                    $deal->client = $client;
                    $deal->expires_at = $target['expires_at'] ?? $client->escort_expire;
                }

                $res = $this->sendManualReminder($deal, $templateId, $actorId);
                if (!empty($res['success'])) {
                    $sent++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        return [
            'total' => count($targets),
            'success' => $sent,
            'failed' => $failed,
        ];
    }

    public function runCampaigns(?int $campaignId = null, ?int $actorId = null, ?array $platformIds = null): array
    {
        $campaigns = RenewalCampaign::query()
            ->with('template')
            ->where('enabled', true)
            ->when($campaignId, fn(Builder $builder) => $builder->where('id', $campaignId))
            ->orderBy('trigger_days')
            ->get();

        if ($campaigns->isEmpty()) {
            return [
                'campaigns' => [],
                'totals' => ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'targeted' => 0],
            ];
        }

        $results = [];
        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'targeted' => 0];

        foreach ($campaigns as $campaign) {
            $result = $this->runSingleCampaign($campaign, $actorId, $platformIds);
            $results[] = $result;

            $totals['sent'] += $result['sent_count'];
            $totals['failed'] += $result['failed_count'];
            $totals['skipped'] += $result['skipped_count'];
            $totals['targeted'] += $result['total_targeted'];
        }

        return [
            'campaigns' => $results,
            'totals' => $totals,
        ];
    }

    public function sendManualReminder(Deal $deal, ?int $templateId = null, ?int $actorId = null): array
    {
        $deal->loadMissing(['client.platform', 'product']);

        if ($this->isReminderPaused($deal)) {
            $resumeOn = $deal->renewal_paused_until ? Carbon::parse($deal->renewal_paused_until)->toDateTimeString() : 'manual resume';

            return [
                'success' => false,
                'status' => 'paused',
                'reason' => 'Renewal reminders are paused for this subscription until ' . $resumeOn . '.',
            ];
        }

        if (!$deal->client) {
            return [
                'success' => false,
                'status' => 'failed',
                'reason' => 'Deal does not have a linked client.',
            ];
        }

        $template = $templateId
            ? Template::query()->where('id', $templateId)->where('channel', 'sms')->first()
            : $this->resolveDefaultRenewalTemplate($deal);

        if (!$template) {
            return [
                'success' => false,
                'status' => 'failed',
                'reason' => 'No SMS template available for this reminder.',
            ];
        }

        $variables = $this->templateService->buildClientVariables($deal->client, $deal, [
            'trigger_days' => $this->suggestTriggerDays($deal),
        ]);

        $rendered = $this->templateService->renderTemplate($template, $variables);
        if (!empty($rendered['missing'])) {
            return [
                'success' => false,
                'status' => 'failed',
                'reason' => 'Template rendering missing variables: ' . implode(', ', $rendered['missing']),
                'missing' => $rendered['missing'],
            ];
        }

        $delivery = $this->notificationService->sendSmsToClient($deal->client, $rendered['body'], [
            'phone_prefix' => optional($deal->client->platform)->phone_prefix ?? '254',
            'template_id' => $template->id,
            'deal_id' => $deal->id,
            'mode' => 'manual',
        ]);

        $eventType = $delivery['success'] ? 'renewal_sms_sent' : 'renewal_sms_failed';
        $notePrefix = $delivery['success'] ? '[Renewal SMS]' : '[Renewal SMS Failed]';

        DB::transaction(function () use ($deal, $template, $delivery, $eventType, $notePrefix, $rendered, $actorId) {
            ClientNote::create([
                'client_id' => $deal->client_id,
                'author_id' => $this->resolveActorId($actorId),
                'note_type' => 'system',
                'content' => sprintf(
                    '%s Template #%d: %s',
                    $notePrefix,
                    $template->id,
                    $rendered['body']
                ),
                'follow_up_at' => null,
                'created_at' => now(),
            ]);

            TimelineEvent::create([
                'platform_id' => $deal->platform_id,
                'entity_type' => $deal->id ? 'deal' : 'client',
                'entity_id' => $deal->id ?: $deal->client_id,
                'event_type' => $eventType,
                'actor_id' => $actorId,
                'content' => [
                    'template_id' => $template->id,
                    'delivery_status' => $delivery['status'],
                    'provider_response' => $delivery['provider_response'] ?? null,
                    'manual' => true,
                ],
                'created_at' => now(),
            ]);

            $this->auditService->record([
                'platform_id' => $deal->platform_id,
                'actor_id' => $actorId,
                'action' => $delivery['success'] ? CrmAuditAction::RENEWAL_SMS_SENT : CrmAuditAction::RENEWAL_SMS_FAILED,
                'entity_type' => $deal->id ? 'deal' : 'client',
                'entity_id' => $deal->id ?: $deal->client_id,
                'before_state' => null,
                'after_state' => [
                    'template_id' => $template->id,
                    'delivery_status' => $delivery['status'],
                ],
                'reason' => 'Manual renewal reminder',
            ]);
        });

        return array_merge([
            'success' => (bool) $delivery['success'],
            'status' => $delivery['status'],
            'template_id' => $template->id,
            'message' => $rendered['body'],
        ], $delivery);
    }

    public function pauseReminders(Deal $deal, string $reason, ?int $actorId = null, ?string $pauseUntil = null): array
    {
        $pauseUntilDate = null;
        if ($pauseUntil) {
            $pauseUntilDate = Carbon::parse($pauseUntil)->endOfDay();
        }

        $beforeState = [
            'renewal_reminders_paused' => (bool) $deal->renewal_reminders_paused,
            'renewal_paused_until' => optional($deal->renewal_paused_until)->toDateTimeString(),
            'renewal_pause_reason' => $deal->renewal_pause_reason,
        ];

        $deal->update([
            'renewal_reminders_paused' => true,
            'renewal_paused_until' => $pauseUntilDate,
            'renewal_pause_reason' => $reason,
        ]);

        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_reminders_paused',
            'actor_id' => $actorId,
            'content' => [
                'reason' => $reason,
                'renewal_paused_until' => optional($pauseUntilDate)->toDateTimeString(),
            ],
            'created_at' => now(),
        ]);

        $this->auditService->record([
            'platform_id' => $deal->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::RENEWAL_PAUSE,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'before_state' => $beforeState,
            'after_state' => [
                'renewal_reminders_paused' => true,
                'renewal_paused_until' => optional($pauseUntilDate)->toDateTimeString(),
                'renewal_pause_reason' => $reason,
            ],
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'status' => 'paused',
            'deal_id' => $deal->id,
            'renewal_paused_until' => optional($pauseUntilDate)->toDateTimeString(),
        ];
    }

    public function resumeReminders(Deal $deal, string $reason, ?int $actorId = null): array
    {
        $beforeState = [
            'renewal_reminders_paused' => (bool) $deal->renewal_reminders_paused,
            'renewal_paused_until' => optional($deal->renewal_paused_until)->toDateTimeString(),
            'renewal_pause_reason' => $deal->renewal_pause_reason,
        ];

        $deal->update([
            'renewal_reminders_paused' => false,
            'renewal_paused_until' => null,
            'renewal_pause_reason' => null,
        ]);

        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_reminders_resumed',
            'actor_id' => $actorId,
            'content' => [
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->record([
            'platform_id' => $deal->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::RENEWAL_RESUME,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'before_state' => $beforeState,
            'after_state' => [
                'renewal_reminders_paused' => false,
                'renewal_paused_until' => null,
                'renewal_pause_reason' => null,
            ],
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'status' => 'active',
            'deal_id' => $deal->id,
        ];
    }

    private function runSingleCampaign(RenewalCampaign $campaign, ?int $actorId, ?array $platformIds = null): array
    {
        $campaign->loadMissing('template');

        $deals = $this->targetDealsForCampaign($campaign, $platformIds);
        $runnerId = $this->resolveActorId($actorId);

        $run = RenewalRun::create([
            'campaign_id' => $campaign->id,
            'run_at' => now(),
            'total_targeted' => $deals->count(),
            'sent_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'run_by' => $runnerId,
            'status' => 'completed',
            'created_at' => now(),
        ]);

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($deals as $deal) {
            if ($this->alreadyAttemptedToday($deal->id, $campaign->id)) {
                $skipped++;
                continue;
            }

            $deal->loadMissing(['client.platform', 'product']);
            if (!$deal->client || !$campaign->template) {
                $failed++;
                $this->writeRenewalTimeline($deal, $campaign, $run, false, 'Missing client or template');
                continue;
            }

            $variables = $this->templateService->buildClientVariables($deal->client, $deal, [
                'trigger_days' => $campaign->trigger_days,
            ]);
            $rendered = $this->templateService->renderTemplate($campaign->template, $variables);

            if (!empty($rendered['missing'])) {
                $failed++;
                $this->writeRenewalTimeline(
                    $deal,
                    $campaign,
                    $run,
                    false,
                    'Missing variables: ' . implode(', ', $rendered['missing'])
                );
                continue;
            }

            $delivery = $this->notificationService->sendSmsToClient($deal->client, $rendered['body'], [
                'phone_prefix' => optional($deal->client->platform)->phone_prefix ?? '254',
                'campaign_id' => $campaign->id,
                'run_id' => $run->id,
                'template_id' => $campaign->template_id,
            ]);

            if ($delivery['success']) {
                $sent++;
            } else {
                $failed++;
            }

            DB::transaction(function () use ($deal, $campaign, $run, $rendered, $delivery, $runnerId) {
                $notePrefix = $delivery['success'] ? '[RC' . $campaign->id . '] Renewal SMS' : '[RC' . $campaign->id . '] Renewal SMS Failed';

                ClientNote::create([
                    'client_id' => $deal->client_id,
                    'author_id' => $runnerId,
                    'note_type' => 'system',
                    'content' => sprintf('%s: %s', $notePrefix, $rendered['body']),
                    'follow_up_at' => null,
                    'created_at' => now(),
                ]);

                $this->writeRenewalTimeline(
                    $deal,
                    $campaign,
                    $run,
                    (bool) $delivery['success'],
                    $delivery['provider_response'] ?? null
                );

                $this->auditService->record([
                    'platform_id' => $deal->platform_id,
                    'actor_id' => $runnerId,
                    'action' => $delivery['success'] ? CrmAuditAction::RENEWAL_SMS_SENT : CrmAuditAction::RENEWAL_SMS_FAILED,
                    'entity_type' => $deal->id ? 'deal' : 'client',
                    'entity_id' => $deal->id ?: $deal->client_id,
                    'after_state' => [
                        'campaign_id' => $campaign->id,
                        'run_id' => $run->id,
                        'delivery_status' => $delivery['status'],
                    ],
                    'reason' => 'Automated renewal campaign',
                ]);
            });
        }

        $status = 'completed';
        if ($failed > 0 && $sent === 0) {
            $status = 'failed';
        } elseif ($failed > 0 || $skipped > 0) {
            $status = 'partial';
        }

        $run->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'status' => $status,
        ]);

        return [
            'campaign_id' => $campaign->id,
            'trigger_days' => $campaign->trigger_days,
            'run_id' => $run->id,
            'total_targeted' => $run->total_targeted,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'status' => $status,
        ];
    }

    private function targetDealsForCampaign(RenewalCampaign $campaign, ?array $platformIds = null): Collection
    {
        $targetDate = now()->startOfDay()->addDays($campaign->trigger_days * -1);

        // 1. Target real Deals
        $deals = Deal::query()
            ->whereIn('status', ['active', 'expired'])
            ->whereDate('expires_at', $targetDate->toDateString())
            ->where(function (Builder $builder) {
                $builder->where('renewal_reminders_paused', false)
                    ->orWhere(function (Builder $pausedBuilder) {
                        $pausedBuilder->where('renewal_reminders_paused', true)
                            ->whereNotNull('renewal_paused_until')
                            ->where('renewal_paused_until', '<', now());
                    });
            })
            ->when(
                is_array($platformIds),
                fn(Builder $builder) => $builder->whereIn('platform_id', $platformIds)
            )
            ->when($campaign->product_id, fn(Builder $builder) => $builder->where('product_id', $campaign->product_id))
            ->with(['client.platform', 'product'])
            ->get();

        // 2. Target Virtual Renewals (Clients with escort_expire and no active/expired Deal)
        // Note: Filters on product_id are ignored for virtual renewals as they have no linked product.
        $virtuals = Client::query()
            ->whereDate('escort_expire', $targetDate->toDateString())
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('deals')
                    ->whereColumn('deals.client_id', 'clients.id')
                    ->whereIn('deals.status', ['active', 'expired']);
            })
            ->when(
                is_array($platformIds),
                fn(Builder $builder) => $builder->whereIn('platform_id', $platformIds)
            )
            ->with(['platform'])
            ->get()
            ->map(function ($client) {
                // Return a Deal-like object (or the client itself with necessary fields)
                $client->client = $client;
                $client->client_id = $client->id;
                $client->expires_at = $client->escort_expire;
                $client->product = null;
                // deal_id is null, signaling virtual
                return $client;
            });

        return $deals->concat($virtuals);
    }

    private function applyBucketFilter(Builder|\Illuminate\Database\Query\Builder $query, string $bucket): void
    {
        $nowTs = now()->timestamp;
        $dateExpr = 'COALESCE(UNIX_TIMESTAMP(deals.expires_at), clients.escort_expire)';

        if ($bucket === 'paused') {
            $query->where('deals.renewal_reminders_paused', true)
                ->where(function (Builder $builder) {
                    $builder->whereNull('deals.renewal_paused_until')
                        ->orWhere('deals.renewal_paused_until', '>=', now());
                });
            return;
        }

        if ($bucket === 'risk') {
            $query->whereBetween(DB::raw($dateExpr), [$nowTs, $nowTs + (3 * 86400)]);
            return;
        }

        if ($bucket === 'pending') {
            $query->whereBetween(DB::raw($dateExpr), [$nowTs + (4 * 86400), $nowTs + (14 * 86400)]);
            return;
        }

        if ($bucket === 'stable') {
            $query->where(DB::raw($dateExpr), '>', $nowTs + (14 * 86400));
            return;
        }

        if ($bucket === 'expired') {
            $query->whereBetween(DB::raw($dateExpr), [$nowTs - (14 * 86400), $nowTs - 1]);
            return;
        }

        if ($bucket === 'lapsed') {
            $query->where(function ($q) use ($nowTs, $dateExpr) {
                $q->where(DB::raw($dateExpr), '<', $nowTs - (14 * 86400))
                    ->orWhere(function ($sq) {
                        $sq->whereNull('deals.id')
                            ->where('clients.profile_status', 'private')
                            ->whereNull('clients.escort_expire');
                    });
            });
        }
    }

    private function alreadyAttemptedToday(int $dealId, int $campaignId): bool
    {
        return TimelineEvent::query()
            ->where('entity_type', 'deal')
            ->where('entity_id', $dealId)
            ->whereIn('event_type', ['renewal_sms_sent', 'renewal_sms_failed'])
            ->whereDate('created_at', now()->toDateString())
            ->where('content', 'like', '%"campaign_id":' . $campaignId . '%')
            ->exists();
    }

    private function writeRenewalTimeline($deal, RenewalCampaign $campaign, RenewalRun $run, bool $success, ?string $response): void
    {
        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => $deal->id ? 'deal' : 'client',
            'entity_id' => $deal->id ?: $deal->client_id,
            'event_type' => $success ? 'renewal_sms_sent' : 'renewal_sms_failed',
            'actor_id' => null,
            'content' => [
                'campaign_id' => $campaign->id,
                'run_id' => $run->id,
                'template_id' => $campaign->template_id,
                'response' => $response,
            ],
            'created_at' => now(),
        ]);
    }

    private function resolveDefaultRenewalTemplate(Deal $deal): ?Template
    {
        $trigger = $this->suggestTriggerDays($deal);

        $campaign = RenewalCampaign::query()
            ->where('enabled', true)
            ->where('trigger_days', $trigger)
            ->with('template')
            ->first();

        if ($campaign?->template) {
            return $campaign->template;
        }

        return Template::query()
            ->where('category', 'renewal')
            ->where('channel', 'sms')
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();
    }

    private function suggestTriggerDays(Deal $deal): int
    {
        $daysLeft = $this->daysUntil($deal->expires_at);

        if ($daysLeft === null) {
            return 0;
        }

        if ($daysLeft >= 7) {
            return -7;
        }

        if ($daysLeft >= 3) {
            return -3;
        }

        if ($daysLeft >= 0) {
            return 0;
        }

        return 3;
    }

    private function daysUntil($dateValue): ?int
    {
        if (!$dateValue) {
            return null;
        }

        $date = $dateValue instanceof Carbon ? $dateValue : Carbon::parse($dateValue);

        return now()->diffInDays($date, false);
    }

    private function bucketForDays(?int $daysLeft): string
    {
        if ($daysLeft === null) {
            return 'unknown';
        }

        if ($daysLeft < 0) {
            return 'expired';
        }

        if ($daysLeft <= 3) {
            return 'risk';
        }

        if ($daysLeft <= 14) {
            return 'pending';
        }

        return 'stable';
    }

    private function bucketForDaysExpired(?int $daysLeft): string
    {
        if ($daysLeft === null) {
            return 'unknown';
        }

        if ($daysLeft < -14) {
            return 'lapsed';
        }

        return 'expired';
    }

    private function isReminderPaused(Deal $deal): bool
    {
        if (!(bool) $deal->renewal_reminders_paused) {
            return false;
        }

        if (!$deal->renewal_paused_until) {
            return true;
        }

        return Carbon::parse($deal->renewal_paused_until)->isFuture();
    }

    private function resolveActorId(?int $actorId): int
    {
        if ($actorId) {
            return $actorId;
        }

        $userId = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->value('id');

        if ($userId) {
            return (int) $userId;
        }

        $fallback = User::query()->orderBy('id')->value('id');
        if ($fallback) {
            return (int) $fallback;
        }

        throw new \RuntimeException('No users available to set renewal run owner.');
    }
}
