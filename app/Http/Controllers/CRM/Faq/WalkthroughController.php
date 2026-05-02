<?php

namespace App\Http\Controllers\CRM\Faq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faq\StoreWalkthroughRequest;
use App\Http\Requests\Faq\UpdateWalkthroughRequest;
use App\Models\Faq\Walkthrough;
use App\Services\AuditService;
use Illuminate\Http\Request;

class WalkthroughController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService
    ) {
    }

    public function index()
    {
        return response()->json([
            'walkthroughs' => Walkthrough::query()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreWalkthroughRequest $request)
    {
        $walkthrough = Walkthrough::create($request->validated());

        $this->auditService->fromSystemRequest(
            $request,
            'faq_walkthrough_created',
            'faq_walkthrough',
            (int) $walkthrough->id,
            null,
            $walkthrough->toArray(),
            'Created FAQ walkthrough'
        );

        return response()->json([
            'message' => 'FAQ walkthrough created.',
            'walkthrough' => $walkthrough,
        ], 201);
    }

    public function show(Walkthrough $walkthrough)
    {
        return response()->json([
            'walkthrough' => $walkthrough,
        ]);
    }

    public function update(UpdateWalkthroughRequest $request, Walkthrough $walkthrough)
    {
        $before = $walkthrough->toArray();
        $walkthrough->fill($request->validated());
        $walkthrough->save();

        $this->auditService->fromSystemRequest(
            $request,
            'faq_walkthrough_updated',
            'faq_walkthrough',
            (int) $walkthrough->id,
            $before,
            $walkthrough->fresh()->toArray(),
            'Updated FAQ walkthrough'
        );

        return response()->json([
            'message' => 'FAQ walkthrough updated.',
            'walkthrough' => $walkthrough->fresh(),
        ]);
    }

    public function destroy(Request $request, Walkthrough $walkthrough)
    {
        $before = $walkthrough->toArray();
        $walkthroughId = (int) $walkthrough->id;
        $walkthrough->delete();

        $this->auditService->fromSystemRequest(
            $request,
            'faq_walkthrough_deleted',
            'faq_walkthrough',
            $walkthroughId,
            $before,
            null,
            'Deleted FAQ walkthrough'
        );

        return response()->json([
            'message' => 'FAQ walkthrough deleted.',
        ]);
    }
}
