<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\CustomerActivityEvent;
use App\Models\CustomerSavedObject;
use App\Models\Platform;
use App\Services\CustomerProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Customer (site member) product state, called server-to-server by the
 * WordPress `exotic-crm-sync` plugin over HMAC.
 *
 * The HMAC middleware proves which *site* is calling; it proves nothing about
 * which *person*. WordPress derives the member identity from its own session
 * and signs it into the body, so every action here re-validates that identity
 * through CustomerProductService before touching state.
 */
class CustomerProductController extends Controller
{
    public function __construct(
        private readonly CustomerProductService $customerProduct,
    ) {}

    /** Link or refresh the account and return its workspace summary. */
    public function sync(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account, Platform $platform) {
            $this->customerProduct->recordEvent($account, CustomerActivityEvent::EVENT_WORKSPACE_VIEWED);

            return response()->json($this->summary($account));
        });
    }

    /** Saved profile ids, newest first. */
    public function savedIndex(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) {
            return response()->json($this->summary($account));
        });
    }

    public function savedStore(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $created = $this->customerProduct->save(
                $account,
                (int) $request->input('object_ref'),
                CustomerSavedObject::SOURCE_WORKSPACE
            );

            return response()->json($this->summary($account) + [
                'created' => $created,
                'saved' => true,
            ], $created ? 201 : 200);
        });
    }

    public function savedDestroy(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $removed = $this->customerProduct->unsave($account, (int) $request->input('object_ref'));

            return response()->json($this->summary($account) + [
                'removed' => $removed,
                'saved' => false,
            ]);
        });
    }

    /**
     * Fold a batch of profile ids into the account: the legacy WordPress
     * `user_meta.favorites` backfill, and the signed-out browser-local list
     * merged on first authenticated load.
     */
    public function savedMerge(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $source = (string) $request->input('source', CustomerSavedObject::SOURCE_LOCAL_MERGE);
            $allowed = [
                CustomerSavedObject::SOURCE_LEGACY_BACKFILL,
                CustomerSavedObject::SOURCE_LOCAL_MERGE,
            ];
            if (! in_array($source, $allowed, true)) {
                $source = CustomerSavedObject::SOURCE_LOCAL_MERGE;
            }

            $refs = $request->input('object_refs', []);
            if (! is_array($refs)) {
                $refs = [];
            }

            $merged = $this->customerProduct->merge($account, $refs, $source);

            return response()->json($this->summary($account) + [
                'merged' => $merged,
            ]);
        });
    }

    /** Delete every trace of a customer. Called from the WordPress user-delete hook. */
    public function forget(Request $request): JsonResponse
    {
        $platform = $request->attributes->get('platform');

        try {
            $deleted = $this->customerProduct->forget($platform, (int) $request->input('wp_user_id'));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
        ]);
    }

    /**
     * Resolve the signed member identity, then run the action. Identity failures
     * are 422 so the WordPress side can distinguish them from transport errors.
     */
    private function withAccount(Request $request, callable $action): JsonResponse
    {
        $platform = $request->attributes->get('platform');

        try {
            $account = $this->customerProduct->resolveAccount($platform, [
                'wp_user_id' => $request->input('wp_user_id'),
                'wp_role' => $request->input('wp_role'),
                'display_name' => $request->input('display_name'),
                'email' => $request->input('email'),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            return $action($account, $platform);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function summary($account): array
    {
        $savedIds = $this->customerProduct->savedProfileIds($account);

        return [
            'success' => true,
            'customer_account_id' => (int) $account->id,
            'saved_profile_ids' => $savedIds,
            'saved_count' => count($savedIds),
            'saved_limit' => CustomerProductService::MAX_SAVED_OBJECTS,
        ];
    }
}
