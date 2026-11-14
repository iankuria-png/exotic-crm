<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionEvent;
use Illuminate\Support\Collection;

/**
 * Receiving-wallet balances as reported by the provider's own credit SMS. Each value is the balance the
 * message stated after that credit; nothing is computed or inferred, and unverifiable messages are ignored.
 */
class CollectionWalletBalanceService
{
    /** @return array<int, array{minor: int, currency: string, at: ?string}> receipt id => balance after that payment */
    public function forReceipts(Collection $receipts): array
    {
        if ($receipts->isEmpty()) {
            return [];
        }
        $parser = app(TzMpesaParser::class);
        $byId = $receipts->keyBy('id');
        $events = CollectionEvent::whereIn('receipt_id', $byId->keys())->where('state', 'parsed')->where('event_kind', 'sms:received')
            ->orderBy('sms_received_at')->orderBy('id')->limit(400)->get();
        $out = [];
        foreach ($events as $event) {
            $receipt = $byId[$event->receipt_id] ?? null;
            if (! $receipt || $event->account_id !== $receipt->account_id) {
                continue;
            }
            $balance = $this->verified($parser, $event, $receipt->reference_norm, $receipt->currency, $receipt->principal_minor);
            if ($balance !== null) {
                // Paired messages for one payment report the same balance; keep the latest statement.
                $out[$receipt->id] = ['minor' => $balance, 'currency' => $receipt->currency, 'at' => $event->sms_received_at?->toIso8601String()];
            }
        }

        return $out;
    }

    /** Latest balance each account's receiving phone reported, newest credit SMS first. */
    public function latest(array $accountIds): array
    {
        $parser = app(TzMpesaParser::class);
        $out = [];
        foreach (CollectionAccount::whereIn('id', $accountIds)->orderBy('label')->get() as $account) {
            $events = CollectionEvent::where('account_id', $account->id)->where('state', 'parsed')->where('event_kind', 'sms:received')
                ->whereNotNull('receipt_id')->orderByDesc('sms_received_at')->orderByDesc('id')->limit(20)->get();
            foreach ($events as $event) {
                $data = $event->normalized ?? [];
                $credit = $parser->parse((string) ($data['sender'] ?? ''), (string) ($data['message'] ?? ''));
                if (($credit['kind'] ?? '') !== 'credit' || ($credit['currency'] ?? null) !== $account->currency) {
                    continue;
                }
                $balance = $parser->balanceMinor((string) ($data['message'] ?? ''));
                if ($balance !== null) {
                    $out[] = ['account_id' => $account->id, 'label' => $account->label, 'minor' => $balance, 'currency' => $account->currency,
                        'at' => $event->sms_received_at?->toIso8601String(), 'receipt_id' => (int) $event->receipt_id];
                    break;
                }
            }
        }

        return $out;
    }

    private function verified(TzMpesaParser $parser, CollectionEvent $event, string $reference, string $currency, int $principal): ?int
    {
        $data = $event->normalized ?? [];
        $body = (string) ($data['message'] ?? '');
        $credit = $parser->parse((string) ($data['sender'] ?? ''), $body);
        if (($credit['kind'] ?? '') !== 'credit' || ($credit['reference'] ?? null) !== $reference
            || ($credit['currency'] ?? null) !== $currency || ($credit['principal_minor'] ?? null) !== $principal) {
            return null;
        }

        return $parser->balanceMinor($body);
    }
}
