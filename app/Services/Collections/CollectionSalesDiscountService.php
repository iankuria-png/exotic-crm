<?php

namespace App\Services\Collections;

use App\Models\User;
use App\Services\WalletSettingsService;
use Illuminate\Validation\ValidationException;

/** Uses the existing CRM discount PIN and market limits. Never persists a PIN or creates cash. */
class CollectionSalesDiscountService
{
    public function __construct(private readonly WalletSettingsService $settings) {}

    public function availability(int $platform): array
    {
        return ['pin_configured' => $this->settings->discountPinIsConfigured(),
            'max_percentage' => (float) data_get($this->settings->getDiscountConfig(), 'max_percentage_by_platform.'.$platform, 0)];
    }

    private function invalid(int $position, string $field, string $message): never
    {
        throw ValidationException::withMessages(['items.'.$position.'.discount.'.$field => $message]);
    }

    public function apply(array $purchase, User $actor, array $input, int $position): array
    {
        if (empty($input['enabled'])) {
            return $purchase;
        }
        if (! empty($purchase['discount_source']) || ! empty($purchase['original_amount'])) {
            $this->invalid($position, 'payable_amount', 'This quote already has an approved price. Choose the catalogue option to approve a different final price.');
        }
        $base = (int) $purchase['catalogue_minor'];
        if ($base <= 0 || $base !== (int) $purchase['amount_minor']) {
            $this->invalid($position, 'payable_amount', 'A current catalogue price is required before approving a discount.');
        }
        if (isset($input['payable_amount']) && $input['payable_amount'] !== '') {
            $payable = app(TzMpesaParser::class)->minor((string) $input['payable_amount']);
        } else {
            $percentage = round((float) ($input['percentage'] ?? 0), 2);
            if ($percentage < 1 || $percentage > 99) {
                $this->invalid($position, 'percentage', 'Enter a discount between 1% and 99%.');
            }
            $payable = (int) round($base * (1 - $percentage / 100));
        }
        if ($payable <= 0 || $payable < (int) round($base * 0.01) || $payable > (int) round($base * 0.99)) {
            $this->invalid($position, 'payable_amount', 'The final payable amount must apply a discount between 1% and 99%.');
        }
        // The exact payable wins. Never reconstruct money from the rounded display percentage.
        $actual = round((($base - $payable) / $base) * 100, 6);
        $policy = $this->availability((int) $purchase['platform_id']);
        if (! $policy['pin_configured']) {
            $this->invalid($position, 'pin', 'Discount PIN is not configured. Ask an admin to set it in Settings first.');
        }
        if (! $this->settings->verifyDiscountPin(trim((string) ($input['pin'] ?? '')))) {
            $this->invalid($position, 'pin', 'Discount PIN is invalid.');
        }
        if ($actual > $policy['max_percentage']) {
            $this->invalid($position, 'percentage', 'Discount exceeds the configured market maximum of '.$policy['max_percentage'].'%.');
        }

        return array_replace($purchase, ['amount_minor' => $payable,
            'original_amount' => intdiv($base, 100).'.'.str_pad((string) ($base % 100), 2, '0', STR_PAD_LEFT),
            'discount_percentage' => number_format(round($actual, 2), 2, '.', ''),
            'discount_approved_by' => $actor->id, 'discount_source' => 'agent_manual']);
    }
}
