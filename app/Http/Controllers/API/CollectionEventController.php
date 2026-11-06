<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Collections\CollectionDevice;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionIngestService;
use Illuminate\Http\Request;

class CollectionEventController extends Controller
{
    public function store(Request $request, string $deviceId, CollectionIngestService $ingest)
    {
        abort_unless(config('collection.capture_enabled'), 404);
        if (strlen($request->getContent()) > config('collection.max_body_bytes')) {
            throw new CollectionException('event_too_large', 413);
        }
        $device = CollectionDevice::find($deviceId);
        abort_unless($device, 401);
        $ingest->authenticate($device, $request->getContent(), (string) $request->header('X-Timestamp'), (string) $request->header('X-Signature'));

        $result = $ingest->accept($device, $request->getContent());

        return response()->json($result, ($result['reason'] ?? '') === 'transport_id_conflict' ? 409 : 202);
    }
}
