<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CustomerSafetyReport;
use App\Models\Platform;
use App\Services\MarketAuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Staff review surface for member safety reports.
 *
 * This is a slice of the existing Web Visitors workspace, not a second visitor
 * dashboard. Phase 7 wrote `customer_safety_reports` rows with no way for staff
 * to move them, so a member's report status could only ever read "Received".
 * This controller is the missing half.
 *
 * What it deliberately does not expose:
 *   - The member's free text. It is emailed to staff and never stored, so there
 *     is nothing here to leak.
 *   - The reporting member's identity beyond the account id. A report is a
 *     request for attention, not an accusation to be attributed in a table.
 *   - Any automatic action against the advertiser. Staff decide; the row only
 *     records that they did.
 */
class CustomerSafetyAdminController extends Controller
{
    /** Reports returned in one staff page. */
    private const PER_PAGE_DEFAULT = 25;

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorization
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:5|max:100',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'status' => ['nullable', Rule::in([
                CustomerSafetyReport::STATUS_RECEIVED,
                CustomerSafetyReport::STATUS_UNDER_REVIEW,
                CustomerSafetyReport::STATUS_CLOSED,
            ])],
            'category' => ['nullable', Rule::in(CustomerSafetyReport::categories())],
            'search' => 'nullable|string|max:120',
            'sort' => ['nullable', Rule::in(['submitted_at', 'status', 'category'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $this->marketAuthorization->ensureRequestedPlatformIsAccessible($request);
        $platformIds = $this->marketAuthorization->resolveAccessiblePlatformIds($request->user());
        $canManage = $this->marketAuthorization->isManager($request->user());

        $base = fn (): Builder => CustomerSafetyReport::query()
            ->when(is_array($platformIds), fn (Builder $q) => $q->whereIn('platform_id', $platformIds))
            ->when(! empty($filters['platform_id']), fn (Builder $q) => $q->where('platform_id', (int) $filters['platform_id']))
            ->when(! empty($filters['from']), fn (Builder $q) => $q->where('submitted_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when(! empty($filters['to']), fn (Builder $q) => $q->where('submitted_at', '<=', Carbon::parse($filters['to'])->endOfDay()));

        // Counts are computed before the status filter so the tab badges keep
        // showing the full queue while staff are looking at one slice of it.
        $counts = [
            'received' => (clone $base())->where('status', CustomerSafetyReport::STATUS_RECEIVED)->count(),
            'under_review' => (clone $base())->where('status', CustomerSafetyReport::STATUS_UNDER_REVIEW)->count(),
            'closed' => (clone $base())->where('status', CustomerSafetyReport::STATUS_CLOSED)->count(),
        ];
        $counts['open'] = $counts['received'] + $counts['under_review'];

        $sort = $filters['sort'] ?? 'submitted_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = $base()
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['category']), fn (Builder $q) => $q->where('category', $filters['category']))
            ->when(! empty($filters['search']), function (Builder $q) use ($filters): void {
                $term = trim((string) $filters['search']);
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('reference', 'like', '%' . $term . '%')
                        ->orWhere('wp_post_id', (int) $term);
                });
            })
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');

        $perPage = (int) ($filters['per_page'] ?? self::PER_PAGE_DEFAULT);
        $paginator = $query->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));

        $clientIds = collect($paginator->items())
            ->pluck('client_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $clients = $clientIds === []
            ? collect()
            : Client::query()->whereIn('id', $clientIds)->get(['id', 'name', 'wp_post_id'])->keyBy('id');

        $platformNames = Platform::query()
            ->when(is_array($platformIds), fn (Builder $q) => $q->whereIn('id', $platformIds))
            ->get(['id', 'name'])
            ->keyBy('id');

        return response()->json([
            'permissions' => ['can_manage' => $canManage],
            'counts' => $counts,
            'categories' => CustomerSafetyReport::categories(),
            'statuses' => [
                CustomerSafetyReport::STATUS_RECEIVED,
                CustomerSafetyReport::STATUS_UNDER_REVIEW,
                CustomerSafetyReport::STATUS_CLOSED,
            ],
            'reports' => collect($paginator->items())->map(function (CustomerSafetyReport $report) use ($clients, $platformNames): array {
                $client = $report->client_id ? $clients->get($report->client_id) : null;

                return [
                    'id' => (int) $report->id,
                    'reference' => (string) $report->reference,
                    'platform_id' => (int) $report->platform_id,
                    'platform_name' => (string) ($platformNames->get($report->platform_id)->name ?? ''),
                    'wp_post_id' => (int) $report->wp_post_id,
                    'client_id' => $report->client_id ? (int) $report->client_id : null,
                    'client_name' => $client ? (string) $client->name : '',
                    'category' => (string) $report->category,
                    'status' => (string) $report->status,
                    'source' => (string) $report->source,
                    'is_open' => $report->isOpen(),
                    // The account link is dropped at 730 days; a report whose
                    // link is gone is still a moderation record staff can act on.
                    'has_account_link' => $report->customer_account_id !== null,
                    'submitted_at' => optional($report->submitted_at)->toIso8601String(),
                    'reviewed_at' => optional($report->reviewed_at)->toIso8601String(),
                    'reviewed_by' => $report->reviewed_by ? (int) $report->reviewed_by : null,
                    'review_note' => (string) ($report->review_note ?? ''),
                ];
            })->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Move a report's status and record who moved it.
     *
     * The note is staff-only: `CustomerProductService::serializeSafetyReport()`
     * never returns it to the member, and this endpoint is the only writer.
     */
    public function update(Request $request, CustomerSafetyReport $report): JsonResponse
    {
        $this->marketAuthorization->ensureManager($request->user());
        $this->marketAuthorization->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $report->platform_id,
            'You do not have access to reports in this market.'
        );

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                CustomerSafetyReport::STATUS_RECEIVED,
                CustomerSafetyReport::STATUS_UNDER_REVIEW,
                CustomerSafetyReport::STATUS_CLOSED,
            ])],
            'review_note' => 'nullable|string|max:2000',
        ]);

        $report->status = $validated['status'];
        if (array_key_exists('review_note', $validated)) {
            $report->review_note = $validated['review_note'] !== null && $validated['review_note'] !== ''
                ? $validated['review_note']
                : null;
        }
        $report->reviewed_at = Carbon::now();
        $report->reviewed_by = (int) $request->user()->id;
        $report->save();

        return response()->json([
            'report' => [
                'id' => (int) $report->id,
                'reference' => (string) $report->reference,
                'status' => (string) $report->status,
                'is_open' => $report->isOpen(),
                'reviewed_at' => optional($report->reviewed_at)->toIso8601String(),
                'reviewed_by' => (int) $report->reviewed_by,
                'review_note' => (string) ($report->review_note ?? ''),
            ],
        ]);
    }
}
