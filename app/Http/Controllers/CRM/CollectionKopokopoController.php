<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Collections\CollectionProviderPoll;
use App\Services\Collections\CollectionException;
use App\Services\Collections\Kopokopo\KopokopoConnectionService;
use App\Services\Collections\Kopokopo\KopokopoFeedService;
use Illuminate\Http\Request;

class CollectionKopokopoController extends Controller
{
    public function status(Request $request, KopokopoFeedService $feeds)
    {
        return response()->json($feeds->present($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function setup(Request $request, KopokopoFeedService $feeds)
    {
        $request->validate(['confirmation' => 'required|in:prepare-till-268491']);
        $feeds->setup($request->user());

        return response()->json($feeds->present($request->user()), 201);
    }

    public function subscribe(Request $request, KopokopoFeedService $feeds, KopokopoConnectionService $connection)
    {
        $feeds->scope($request->user(), true);
        $v = $request->validate(['expected_version' => 'required|integer|min:1', 'confirmation' => 'required|in:connect-till-268491']);
        $feed = $feeds->feed() ?? throw new CollectionException('provider_setup_required', 409);
        $connection->subscribe($feed, $request->user(), $v['expected_version']);

        return response()->json($feeds->present($request->user()));
    }

    public function verifySubscription(Request $request, KopokopoFeedService $feeds, KopokopoConnectionService $connection)
    {
        $feeds->scope($request->user(), true);
        $v = $request->validate(['topic' => 'required|string|max:64', 'location' => 'required|string|max:512', 'expected_version' => 'required|integer|min:1']);
        $feed = $feeds->feed() ?? throw new CollectionException('provider_setup_required', 409);
        $connection->verifySubscription($feed, $request->user(), $v['topic'], $v['location'], $v['expected_version']);

        return response()->json($feeds->present($request->user()));
    }

    public function reconcile(Request $request, KopokopoFeedService $feeds, KopokopoConnectionService $connection)
    {
        $feeds->scope($request->user(), true);
        $feed = $feeds->feed() ?? throw new CollectionException('provider_setup_required', 409);
        $connection->reconcile($feed, $request->user());

        return response()->json($feeds->present($request->user()));
    }

    public function recover(Request $request, CollectionProviderPoll $poll, KopokopoFeedService $feeds, KopokopoConnectionService $connection)
    {
        $feeds->scope($request->user(), true);
        $v = $request->validate(['location' => 'nullable|string|max:512']);
        $feed = $feeds->feed() ?? throw new CollectionException('provider_setup_required', 409);
        $connection->recover($feed, $poll, $v['location'] ?? null);

        return response()->json($feeds->present($request->user()));
    }
}
