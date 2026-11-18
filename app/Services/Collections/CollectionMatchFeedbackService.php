<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionMatchFeedback;
use App\Models\Collections\CollectionReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records which profile staff actually activated for a receipt and, when it differs from the
 * system's suggestion, why. Feeds the accuracy report and, for duplicates, the payer exclusions.
 */
class CollectionMatchFeedbackService
{
    public const REASONS = [
        'advertiser_confirmed' => 'Advertiser told us which profile',
        'pays_for_several' => 'Payer pays for several profiles',
        'new_profile' => 'New profile for this payment',
        'old_duplicate' => 'Suggestion is an old or duplicate profile',
        'other' => 'Other reason',
    ];

    public const SOURCES = ['activation', 'correction', 'recorded', 'review'];

    /** Profiles that this receipt actually activated or was recorded for. */
    public function outcomeClientIds(CollectionReceipt $receipt): array
    {
        $ids = DB::table('collection_sales_items as i')->join('collection_sales_sessions as s', 's.id', '=', 'i.session_id')
            ->where('s.receipt_id', $receipt->id)->whereNotIn('i.state', ['canceled'])->pluck('i.client_id')->map(fn ($id) => (int) $id)->all();
        if ($client = data_get($receipt->allocation?->purchase_snapshot, 'client_id')) {
            if ($receipt->allocation->state !== 'unassigned') {
                $ids[] = (int) $client;
            }
        }
        $usage = app(CollectionReceiptStatusService::class)->forReceipts(collect([$receipt->loadMissing('allocation', 'sale')]))[$receipt->id] ?? [];
        foreach ($usage['matches'] ?? [] as $match) {
            if ($id = data_get($match, 'client.id')) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** The suggestion staff were measured against: the saved decision, else the current rule evidence. */
    public function suggestion(CollectionReceipt $receipt): array
    {
        $matching = data_get($receipt->candidate_snapshot, 'matching');
        if (! $matching) {
            try {
                $matching = app(CollectionMatchService::class)->automatic($receipt);
            } catch (\Throwable) {
                $matching = ['suggestions' => []];
            }
        }
        $top = collect($matching['suggestions'] ?? [])->filter(fn ($s) => ($s['score'] ?? 0) > 0)->sortByDesc('score')->first();

        return ['client_id' => $top ? (int) $top['id'] : null, 'score' => $top ? (int) $top['score'] : null,
            'rules' => $top['rules'] ?? null, 'rule_version' => $matching['rule_version'] ?? null];
    }

    public function record(CollectionReceipt $receipt, User $actor, int $clientId, ?string $reason, ?string $note, string $source): CollectionMatchFeedback
    {
        if ($reason !== null && ! array_key_exists($reason, self::REASONS)) {
            throw new CollectionException('match_reason_unknown', 422);
        }
        if (! in_array($source, self::SOURCES, true)) {
            throw new CollectionException('match_feedback_source_unknown', 422);
        }
        if (! in_array($clientId, $this->outcomeClientIds($receipt), true)) {
            throw new CollectionException('match_feedback_profile_not_activated', 422);
        }
        $suggested = $this->suggestion($receipt);
        $agreed = $suggested['client_id'] === $clientId;
        $note = $note !== null ? trim(mb_substr($note, 0, 500)) : null;
        if ($reason === 'other' && ! $note) {
            throw new CollectionException('match_reason_note_required', 422);
        }

        return DB::transaction(function () use ($receipt, $actor, $clientId, $reason, $note, $source, $suggested, $agreed) {
            $feedback = CollectionMatchFeedback::updateOrCreate(['receipt_id' => $receipt->id, 'client_id' => $clientId], [
                'account_id' => $receipt->account_id, 'platform_id' => DB::table('clients')->where('id', $clientId)->value('platform_id'),
                'suggested_client_id' => $suggested['client_id'], 'suggested_score' => $suggested['score'], 'suggested_rules' => $suggested['rules'],
                'agreed' => $agreed, 'reason' => $agreed ? null : $reason, 'note' => $agreed ? null : ($note ?: null), 'source' => $source,
                'actor_id' => $actor->id, 'rule_version' => $suggested['rule_version']]);
            // Staff say the suggestion is an old or duplicate profile: stop proposing it for this payer.
            if (! $agreed && $reason === 'old_duplicate' && $suggested['client_id']) {
                app(CollectionMatchExclusionService::class)->record($receipt, $suggested['client_id'], $clientId,
                    'match-feedback:'.$receipt->id.':'.$suggested['client_id'], $actor->id, 'Staff marked the suggested profile as an old or duplicate profile.');
            }
            app(CollectionPurchaseService::class)->audit($receipt, $actor->id, 'match_feedback_recorded', ['client_id' => $clientId,
                'suggested_client_id' => $suggested['client_id'], 'agreed' => $agreed, 'reason' => $reason, 'source' => $source]);

            return $feedback;
        });
    }

    /** Feedback rows for the drawer, with names. */
    public function forReceipt(CollectionReceipt $receipt): array
    {
        $rows = CollectionMatchFeedback::where('receipt_id', $receipt->id)->orderBy('id')->get();
        $names = DB::table('clients')->whereIn('id', $rows->pluck('client_id')->merge($rows->pluck('suggested_client_id'))->filter())->pluck('name', 'id');
        $actors = DB::table('users')->whereIn('id', $rows->pluck('actor_id'))->pluck('name', 'id');

        return $rows->map(fn ($f) => ['client_id' => $f->client_id, 'client_name' => $names[$f->client_id] ?? null,
            'suggested_client_id' => $f->suggested_client_id, 'suggested_name' => $names[$f->suggested_client_id] ?? null,
            'suggested_score' => $f->suggested_score, 'agreed' => $f->agreed, 'reason' => $f->reason,
            'reason_label' => self::REASONS[$f->reason] ?? null, 'note' => $f->note, 'source' => $f->source,
            'by' => $actors[$f->actor_id] ?? null, 'at' => $f->updated_at?->toIso8601String()])->all();
    }
}
