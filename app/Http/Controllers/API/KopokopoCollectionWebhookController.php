<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Collections\CollectionProviderFeed;
use App\Models\Collections\CollectionProviderPoll;
use App\Services\Collections\CollectionException;
use App\Services\Collections\Kopokopo\KopokopoConnectionService;
use App\Services\Collections\Kopokopo\KopokopoFeedService;
use App\Services\Collections\Kopokopo\KopokopoIngestService;
use Illuminate\Http\Request;

class KopokopoCollectionWebhookController extends Controller
{
    public function receipt(Request $request, CollectionProviderFeed $feed, KopokopoIngestService $ingest)
    {
        return response()->json($ingest->webhook($feed, $request->getContent(), (string) $request->header('X-KopoKopo-Signature')), 202);
    }

    public function polling(Request $request, CollectionProviderFeed $feed, CollectionProviderPoll $poll)
    {
        if (strlen($request->getContent()) > config('collection_kopokopo.max_poll_bytes')) {
            throw new CollectionException('provider_body_too_large', 413);
        }
        app(KopokopoFeedService::class)->authenticate($feed, $request->getContent(), (string) $request->header('X-KopoKopo-Signature'));

        return response()->json(app(KopokopoConnectionService::class)->result($feed, $poll, $request->getContent(), true), 202);
    }
}
