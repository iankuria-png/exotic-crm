<?php

namespace App\Http\Controllers\API\Kyc;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Kyc\KycSubjectService;
use Illuminate\Http\Request;

class SiteAnnounceController extends Controller
{
    public function __construct(private readonly KycSubjectService $subjectService)
    {
    }

    public function announce(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'site_url' => 'nullable|url',
            'advertiser_user_ids' => 'required|array',
            'advertiser_user_ids.*' => 'integer',
        ]);

        $clients = Client::query()
            ->where('platform_id', (int) $validated['platform_id'])
            ->whereIn('wp_user_id', array_map('intval', $validated['advertiser_user_ids']))
            ->get();

        $created = 0;
        foreach ($clients as $client) {
            $subject = $this->subjectService->resolveOrCreateForClient($client);
            if ($subject->wasRecentlyCreated) {
                $created++;
            }
        }

        return response()->json([
            'success' => true,
            'matched_clients' => $clients->count(),
            'subjects_created_or_verified' => $clients->count(),
            'created' => $created,
        ]);
    }
}
