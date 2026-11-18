<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Collections\CollectionAccount;
use App\Services\Collections\CollectionAccessService;
use App\Services\Collections\CollectionClaimCheckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Read-only claim checks for self-submitted receiving accounts (flag only; never changes subscriptions). */
class CollectionClaimCheckController extends Controller
{
    public function index(Request $request, CollectionAccessService $access, CollectionClaimCheckService $checks)
    {
        abort_unless(config('collection.workspace_enabled') && Schema::hasTable('collection_accounts'), 404);
        $v = $request->validate(['days' => 'nullable|integer|min:1|max:90', 'all' => 'nullable|boolean']);
        $accounts = CollectionAccount::whereIn('id', $access->accountIds($request->user()))->orderBy('label')->get()->filter(fn ($a) => $checks->supports($a));

        return response()->json(['accounts' => $accounts->values()->map(fn ($a) => ['label' => $a->label, 'currency' => $a->currency]
            + $checks->forAccount($a, (int) ($v['days'] ?? 14), ! ($v['all'] ?? false)))])->header('Cache-Control', 'private, no-store');
    }
}
