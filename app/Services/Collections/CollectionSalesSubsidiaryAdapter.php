<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\SubsidiaryActivationPreviewService;
use App\Services\SubsidiaryTrialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/** Reuses the committed subsidiary selector/approval contract without creating a draft Deal. */
class CollectionSalesSubsidiaryAdapter
{
    public function main(CollectionReceipt $receipt, User $actor, int $clientId, int $priceId): Deal
    {
        app(CollectionAccessService::class)->receipt($actor, $receipt->id);
        $client = app(CollectionPurchaseService::class)->selectableClients()->findOrFail($clientId);
        $intent = app(CollectionPurchaseService::class)->prepare($receipt->account, $client, $actor,
            ['product_price_id' => $priceId, 'valid_until' => now()->addMinutes(5)], false);
        $purchase = $intent->purchase_snapshot;
        $main = new Deal(['id' => 0, 'client_id' => $client->id, 'platform_id' => $client->platform_id,
            'product_id' => $purchase['product_id'], 'product_price_id' => $priceId, 'plan_type' => $purchase['tier'], 'status' => 'pending']);
        $main->setRelation('client', $client->loadMissing('platform'));
        $main->setRelation('product', \App\Models\Product::findOrFail($purchase['product_id']));

        return $main;
    }

    public function targets(CollectionReceipt $receipt, User $actor, array $input): array
    {
        return app(SubsidiaryTrialService::class)->targetsForDeal($this->main($receipt, $actor, $input['source_client_id'], $input['product_price_id']), $actor);
    }

    private function projection(CollectionReceipt $receipt, User $actor, Deal $main, array $input): array
    {
        $target = Platform::findOrFail((int) $input['platform_id']);
        app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $target->id);
        abort_if((int) $main->platform_id === (int) $target->id, 422, 'Choose a different subsidiary market.');

        return app(SubsidiaryTrialService::class)->previewForDeal($main, $target, ! empty($input['client_id']) ? (int) $input['client_id'] : null);
    }

    public function preview(CollectionReceipt $receipt, User $actor, array $input): array
    {
        $main = $this->main($receipt, $actor, $input['source_client_id'], $input['product_price_id']);
        $preview = $this->projection($receipt, $actor, $main, $input);
        $days = (int) ($input['duration_days'] ?? $preview['default_duration_days']);
        $inner = app(SubsidiaryActivationPreviewService::class)->issue($actor, $main, $preview, $input['client_id'] ?? null, $days);
        $preview['preview_token'] = $inner ? Crypt::encryptString(json_encode(['receipt' => $receipt->id, 'receipt_version' => $receipt->version,
            'source_client' => $main->client_id, 'price' => $main->product_price_id, 'actor' => $actor->id, 'expires' => now()->addMinutes(5)->timestamp, 'token' => $inner], JSON_THROW_ON_ERROR)) : null;
        unset($preview['resolution']['identity']);

        return $preview;
    }

    /** Canonical trial intent for a sale preview. No approval, PIN storage, profile creation or activation. */
    public function validate(CollectionReceipt $receipt, User $actor, array $purchase, array $input): ?array
    {
        if (empty($input['enabled'])) {
            return null;
        }
        $main = $this->main($receipt, $actor, $purchase['client_id'], $purchase['product_price_id']);
        $preview = $this->projection($receipt, $actor, $main, $input);
        try {
            $token = json_decode(Crypt::decryptString($input['preview_token'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new CollectionException('refresh_subsidiary_preview');
        }
        if (($token['receipt'] ?? null) !== $receipt->id || ($token['receipt_version'] ?? null) !== $receipt->version
            || ($token['source_client'] ?? null) !== $main->client_id || ($token['price'] ?? null) !== $main->product_price_id
            || ($token['actor'] ?? null) !== $actor->id || ($token['expires'] ?? 0) <= now()->timestamp) {
            throw new CollectionException('subsidiary_sale_context_changed');
        }
        $input['preview_token'] = $token['token'];
        app(SubsidiaryActivationPreviewService::class)->validate($actor, $main, $input, $preview);
        abort_unless(empty($preview['config_errors']) && in_array($preview['resolution']['kind'], ['existing', 'create_if_needed', 'already_active']), 409, 'Resolve this subsidiary profile before reviewing activation.');
        if ($preview['resolution']['kind'] === 'create_if_needed' && empty($input['create_confirmed'])) {
            throw new CollectionException('subsidiary_creation_consent_required', 422);
        }

        return ['enabled' => true, 'market_name' => $preview['target_platform']['name'], 'platform_id' => (int) $input['platform_id'], 'client_id' => $preview['resolution']['client_id'],
            'manual_client_id' => ! empty($input['manual_client_id']) ? (int) $input['manual_client_id'] : null,
            'duration_days' => (int) $input['duration_days'], 'resolution_kind' => $preview['resolution']['kind'],
            'create_confirmed' => (bool) ($input['create_confirmed'] ?? false), 'identity' => $preview['resolution']['identity'],
            'coverage_id' => $preview['resolution']['coverage_id'], 'product_id' => $preview['target_platform']['trial_product_id']];
    }

    /** Called only inside the prepared sale transaction, against the actual new/reused Deal. */
    public function attach(Deal $deal, User $actor, array $canonical, array $input): void
    {
        $preview = app(SubsidiaryTrialService::class)->previewForDeal($deal->loadMissing('client.platform', 'product'), Platform::findOrFail($canonical['platform_id']), $canonical['client_id']);
        if (! app(SubsidiaryActivationPreviewService::class)->same($canonical['identity'], $preview['resolution']['identity'])
            || $canonical['resolution_kind'] !== $preview['resolution']['kind'] || $canonical['product_id'] !== $preview['target_platform']['trial_product_id'] || $preview['config_errors']) {
            throw new CollectionException('subsidiary_identity_changed_before_attachment');
        }
        if ($canonical['resolution_kind'] === 'already_active') {
            $deal->update(['pending_subsidiary_trial' => ['status' => 'already_active', 'platform_id' => $canonical['platform_id'],
                'subsidiary_client_id' => $canonical['client_id'], 'coverage_id' => $canonical['coverage_id']]]);

            return;
        }
        $request = Request::create('/receipt-sale-subsidiary', 'POST');
        $request->setUserResolver(fn () => $actor);
        $trialInput = ['platform_id' => $canonical['platform_id'], 'client_id' => $canonical['client_id'], 'duration_days' => $canonical['duration_days'],
            'create_confirmed' => $canonical['create_confirmed'], 'request_key' => $input['request_key'], 'pin' => $input['pin'] ?? null];
        $trial = app(\App\Http\Controllers\CRM\FreeTrialApprovalController::class)->createForSubsidiary($request, $deal, $trialInput);
        $deal->update(['pending_subsidiary_trial' => ['status' => 'pending', 'platform_id' => $canonical['platform_id'],
            'duration_days' => $canonical['duration_days'], 'subsidiary_client_id' => $canonical['client_id'], 'trial_request_id' => $trial->id,
            'requested_by_user_id' => $actor->id, 'requested_at' => now()->toDateTimeString(), 'attempt_count' => 0]]);
    }
}
