<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Template;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ClientOutreachService
{
    private const EXPIRY_VARIABLES = [
        'days_left',
        'days_since_expiry',
        'expiry_date',
        'expiry_datetime',
    ];

    private const SITUATION_CATEGORY_MAP = [
        'expired' => 'win_back',
        'expiring' => 'renewal',
        'never_paid' => 'payment',
        'active' => 'welcome',
    ];

    public function __construct(
        private readonly TemplateService $templateService,
        private readonly MarketAuthorizationService $marketAuthorizationService,
    ) {
    }

    public function quickRepliesFor(Client $client, User $user): array
    {
        $client->loadMissing('platform');
        $deal = $this->resolveLatestDeal($client);
        $situation = $this->resolveSituation($client, $deal);
        $suggestedCategory = self::SITUATION_CATEGORY_MAP[$situation] ?? 'welcome';
        $variables = $this->safeVariablesFor($client, $deal);

        $messages = $this->quickReplyTemplatesFor($client, $user)
            ->map(function (Template $template) use ($variables, $suggestedCategory) {
                $rendered = $this->templateService->renderTemplate($template, $variables);

                if (!empty($rendered['missing'])) {
                    return null;
                }

                return [
                    'id' => (int) $template->id,
                    'title' => $template->title,
                    'category' => $template->category,
                    'body' => $rendered['body'],
                    'suggested' => $template->category === $suggestedCategory,
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $message) => $message['suggested'])
            ->values()
            ->all();

        return [
            'situation' => $situation,
            'whatsapp_phone' => PhoneNormalizer::normalize(
                $client->phone_normalized,
                (string) ($client->platform?->phone_prefix ?: '254')
            ),
            'messages' => $messages,
        ];
    }

    public function resolveLatestDeal(Client $client): ?Deal
    {
        $deal = $client->deals()
            ->with('product')
            ->whereIn('status', ['active', 'expired'])
            ->latest()
            ->first();

        if ($deal) {
            return $deal;
        }

        $expiresAt = $this->resolveLegacyExpiryDate(
            $client->escort_expire,
            $client->premium_expire,
            $client->featured_expire
        );

        if (!$expiresAt) {
            return null;
        }

        $virtualDeal = new Deal();
        $virtualDeal->id = null;
        $virtualDeal->client_id = (int) $client->id;
        $virtualDeal->platform_id = (int) $client->platform_id;
        $virtualDeal->expires_at = $expiresAt;
        $virtualDeal->setRelation('client', $client);
        $virtualDeal->setRelation('product', null);

        return $virtualDeal;
    }

    public function isNeverPaid(Client $client): bool
    {
        $hasSubscriptionDeal = $client->deals()
            ->whereIn('status', ['active', 'paid', 'awaiting_payment'])
            ->exists();

        if ($hasSubscriptionDeal) {
            return false;
        }

        return !$client->payments()
            ->whereIn('status', ['completed', 'activated'])
            ->exists();
    }

    public function resolveSituation(Client $client, ?Deal $deal): string
    {
        $expiresAt = $this->normalizeExpiry($deal?->expires_at);

        if (!$expiresAt && $this->isNeverPaid($client)) {
            return 'never_paid';
        }

        if (!$expiresAt) {
            return 'active';
        }

        if ($expiresAt->isPast()) {
            return 'expired';
        }

        if (now()->diffInDays($expiresAt, false) <= 7) {
            return 'expiring';
        }

        return 'active';
    }

    private function quickReplyTemplatesFor(Client $client, User $user): Collection
    {
        $query = Template::query()
            ->active()
            ->quickReply()
            ->where(function ($builder) use ($client) {
                $builder->whereNull('platform_id')
                    ->orWhere('platform_id', (int) $client->platform_id);
            });

        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($user);

        if (is_array($allowedPlatformIds)) {
            $query->where(function ($builder) use ($allowedPlatformIds) {
                $builder->whereNull('platform_id')
                    ->orWhereIn('platform_id', $allowedPlatformIds);
            });
        }

        return $query
            ->orderByRaw("CASE category WHEN 'win_back' THEN 1 WHEN 'renewal' THEN 2 WHEN 'payment' THEN 3 WHEN 'welcome' THEN 4 ELSE 5 END")
            ->orderBy('title')
            ->get();
    }

    private function safeVariablesFor(Client $client, ?Deal $deal): array
    {
        $variables = $this->templateService->buildClientVariables($client, $deal);
        $expiresAt = $this->normalizeExpiry($deal?->expires_at);

        if (!$expiresAt) {
            foreach (self::EXPIRY_VARIABLES as $key) {
                $variables[$key] = null;
            }
        }

        foreach (['plan_name', 'package'] as $key) {
            if (($variables[$key] ?? '') === '') {
                $variables[$key] = null;
            }
        }

        return $variables;
    }

    private function resolveLegacyExpiryDate($escortExpiry, $premiumExpiry, $featuredExpiry): ?Carbon
    {
        $timestamps = array_values(array_filter([
            $this->toUnixTimestamp($escortExpiry),
            $this->toUnixTimestamp($premiumExpiry),
            $this->toUnixTimestamp($featuredExpiry),
        ], static fn ($value) => $value !== null));

        if ($timestamps === []) {
            return null;
        }

        return Carbon::createFromTimestamp(max($timestamps));
    }

    private function toUnixTimestamp($value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $parsed = strtotime((string) $value);

        return $parsed === false ? null : (int) $parsed;
    }

    private function normalizeExpiry($value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        return Carbon::parse($value);
    }
}
