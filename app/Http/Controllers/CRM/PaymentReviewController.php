<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\DailyPaymentReview;
use App\Models\PaymentCorrectionRequest;
use App\Models\PaymentManualSubmission;
use App\Models\PaymentReviewAttachment;
use App\Models\Platform;
use App\Models\User;
use App\Services\Reconciliation\DailyPaymentReviewService;
use App\Services\Reconciliation\ReviewAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentReviewController extends Controller
{
    public function __construct(private readonly DailyPaymentReviewService $reviews, private readonly ReviewAccess $access) {}

    public function index(Request $request)
    {
        $result = $this->reviews->listing($request->user(), $this->filters($request));
        $result['data'] = array_map($this->reviews->publicUnit(...), $result['data']);

        return response()->json($result);
    }

    public function options(Request $request)
    {
        $actor = $request->user();
        $ids = $this->access->markets($actor);
        $marketScope = app(\App\Services\MarketAuthorizationService::class)->resolveAccessiblePlatformIds($actor);
        $q = Platform::query();
        if (is_array($marketScope)) {
            $q->whereIn('id', $marketScope);
        }
        $users = User::where('status', 'active')->whereIn('role', ['admin', 'sub_admin', 'sales', 'field_sales'])->get(['id', 'name', 'role', 'assigned_market_ids']);

        return response()->json(['markets' => $q->orderBy('name')->get(['id', 'name', 'currency_code']), 'review_market_ids' => $ids,
            'export_market_ids' => $this->access->markets($actor, 'export'), 'is_admin' => $this->access->admin($actor),
            'staff' => $ids ? $users->map->only(['id', 'name', 'role'])->all() : [],
            'grant_versions' => $this->access->admin($actor) ? DB::table('payment_review_settings')->where('key', 'like', 'grant_version:%')->pluck('value', 'key') : [],
            'grants' => $this->access->admin($actor) ? DB::table('payment_review_grants')->get() : [], 'timezone' => config('reconciliation.timezone')]);
    }

    public function show(Request $request, string $type, int $id)
    {
        $u = $this->load($request, $type, $id);
        $events = $u['review_id'] ? DB::table('payment_review_events')->leftJoin('users', 'users.id', '=', 'payment_review_events.actor_id')
            ->where('review_id', $u['review_id'])->orderByDesc('payment_review_events.id')->limit(100)
            ->get(['payment_review_events.id', 'action', 'reason', 'payment_review_events.created_at', 'users.name as actor']) : [];

        return response()->json(['unit' => $this->reviews->publicUnit($u), 'events' => $events]);
    }

    public function preview(Request $request)
    {
        $this->noImpersonation($request);
        $v = $request->validate(['units' => 'required|array|min:1|max:100', 'units.*.type' => 'required|in:payment,bundle', 'units.*.id' => 'required|integer|min:1',
            'units.*.review_version' => 'required|integer|min:0', 'units.*.source_digest' => 'required|string|size:64',
            'action' => 'required|in:approve,deny,flag,clear_flag,request_correction,reopen', 'reason' => 'nullable|string|max:2000',
            'assigned_to' => 'nullable|integer|exists:users,id', 'reason_code' => 'nullable|string|max:50', 'required_fields' => 'nullable|array',
            'required_fields.*' => 'in:reference,evidence,amount,currency,client,package,other', 'due_at' => 'nullable|date', 'self_approval_reason' => 'nullable|string|max:2000']);

        return response()->json($this->reviews->preview($request->user(), $v));
    }

    public function commit(Request $request)
    {
        $this->noImpersonation($request);
        $v = $request->validate(['preview_token' => 'required|string|max:200000', 'idempotency_key' => 'required|uuid']);

        return response()->json($this->reviews->commit($request->user(), $v['preview_token'], $v['idempotency_key']));
    }

    public function inbox(Request $request)
    {
        $this->access->ready();
        abort_unless($request->user()->isActive(), 403);
        $rows = PaymentCorrectionRequest::where('assigned_to', $request->user()->id)->whereIn('status', ['open', 'submitted'])->orderBy('due_at')->limit(200)->get();
        $data = [];
        foreach ($rows as $row) {
            $review = DailyPaymentReview::find($row->review_id);
            if (! $review) {
                continue;
            }
            try {
                $unit = $this->reviews->unit($request->user(), $review->unit_type, $review->unit_id, assigned: true);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if ($e->getStatusCode() === 403) {
                    continue;
                } throw $e;
            }
            $data[] = ['request' => $row, 'unit' => $this->reviews->publicUnit($unit)];
        }

        return response()->json(['data' => $data]);
    }

    public function resubmit(Request $request, PaymentCorrectionRequest $correction)
    {
        $this->noImpersonation($request);
        $v = $request->validate(['version' => 'required|integer', 'source_digest' => 'required|string|size:64', 'response' => 'required|string|max:4000', 'reference_annotation' => 'nullable|string|max:128', 'idempotency_key' => 'required|uuid']);

        return DB::transaction(function () use ($request, $correction, $v) {
            $review = DailyPaymentReview::findOrFail($correction->review_id);
            $u = $this->reviews->unit($request->user(), $review->unit_type, $review->unit_id, true, assigned: true);
            $correction = PaymentCorrectionRequest::lockForUpdate()->findOrFail($correction->id);
            abort_unless((int) $correction->assigned_to === (int) $request->user()->id, 403);
            foreach ($correction->response_revisions_json as $response) {
                if (($response['key'] ?? '') === $v['idempotency_key']) {
                    abort_unless(($response['response'] ?? '') === $v['response'] && ($response['reference_annotation'] ?? '') === ($v['reference_annotation'] ?? ''), 409, 'This retry key belongs to another response.');

                    return response()->json(['request' => $correction]);
                }
            }
            abort_unless($correction->version === (int) $v['version'] && hash_equals($u['source_digest'], $v['source_digest']), 409, 'The request changed. Refresh before resubmitting.');
            abort_unless($correction->status === 'open', 422, 'This request is already submitted or closed.');
            if (array_intersect($correction->required_fields, ['amount', 'currency', 'client', 'package'])) {
                abort_if(hash_equals((string) $correction->financial_digest, $u['financial_digest']), 422, 'A canonical financial correction is still required. Ask an admin to correct it through the authorized billing workflow before resubmitting.');
            }
            $before = $review->toArray();
            $responses = $correction->response_revisions_json;
            $responses[] = ['key' => $v['idempotency_key'], 'actor_id' => $request->user()->id, 'at' => now()->toIso8601String(), 'response' => $v['response'], 'reference_annotation' => $v['reference_annotation'] ?? '', 'source_digest' => $u['source_digest']];
            $correction->update(['response_revisions_json' => $responses, 'status' => 'submitted', 'version' => $correction->version + 1]);
            $fresh = $this->reviews->unit($request->user(), $review->unit_type, $review->unit_id, true, assigned: true);
            $review->update(['status' => 'resubmitted', 'source_digest' => $fresh['source_digest'], 'snapshot_json' => $fresh['_snapshot'], 'version' => $review->version + 1]);
            $this->reviews->event($review, $request->user(), 'resubmitted', $before, ['status' => 'resubmitted'], $v['response']);

            return response()->json(['request' => $correction]);
        });
    }

    public function reassign(Request $request, PaymentCorrectionRequest $correction)
    {
        $this->noImpersonation($request);
        $v = $request->validate(['version' => 'required|integer', 'assigned_to' => 'required|integer|exists:users,id', 'reason' => 'required|string|max:2000']);

        return DB::transaction(function () use ($request, $correction, $v) {
            $review = DailyPaymentReview::findOrFail($correction->review_id);
            $u = $this->reviews->unit($request->user(), $review->unit_type, $review->unit_id, true);
            $correction = PaymentCorrectionRequest::lockForUpdate()->findOrFail($correction->id);
            abort_unless($correction->version === (int) $v['version'] && in_array($correction->status, ['open', 'submitted']), 409, 'Request changed or closed.');
            $this->access->assignee($request->user(), (int) $v['assigned_to'], $u['platform_ids']);
            $old = $correction->assigned_to;
            $correction->update(['assigned_to' => $v['assigned_to'], 'version' => $correction->version + 1]);
            $review->increment('version');
            $this->reviews->event($review, $request->user(), 'reassigned', ['assigned_to' => $old], ['assigned_to' => $v['assigned_to']], $v['reason']);

            return response()->json(['request' => $correction]);
        });
    }

    public function attachment(Request $request, string $type, int $id)
    {
        $this->noImpersonation($request);
        $v = $request->validate(['file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf']);
        $u = $this->load($request, $type, $id);
        $file = $v['file'];
        $path = $file->store('payment-review-proofs', 'local');
        try {
            $attachment = DB::transaction(function () use ($request, $u, $file, $path) {
                $u = $this->load($request, $u['type'], $u['id'], true);
                $review = $this->reviews->touch($u);
                $a = PaymentReviewAttachment::create(['review_id' => $review->id, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'uploaded_by' => $request->user()->id]);
                $review->increment('version');
                $this->reviews->event($review, $request->user(), 'evidence_added', null, ['attachment_id' => $a->id, 'sha256' => $a->sha256], 'Supporting evidence added.');

                return $a->only(['id', 'mime', 'size', 'sha256']);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['attachment' => $attachment], 201);
    }

    public function evidence(Request $request, string $type, int $id, string $kind, int $evidence)
    {
        $u = $this->load($request, $type, $id);
        if ($kind === 'submission') {
            $row = PaymentManualSubmission::findOrFail($evidence);
            abort_unless(in_array($row->id, array_column($u['proofs'], 'id')), 404);
            $disk = $row->proof_disk;
            $path = $row->proof_path;
            $mime = $row->proof_mime;
            abort_unless($disk === 'local' && str_starts_with($path ?? '', 'manual-payment-proofs/'), 404);
        } else {
            $row = PaymentReviewAttachment::where('review_id', $u['review_id'])->findOrFail($evidence);
            $disk = $row->disk;
            $path = $row->path;
            $mime = $row->mime;
        }
        abort_unless($path && ! str_contains($path, '..') && Storage::disk($disk)->exists($path), 404, 'Supporting file is unavailable.');

        return Storage::disk($disk)->response($path, 'receipt.'.match ($mime) {
            'image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp',default => 'pdf'
        }, [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"], in_array($mime, ['image/jpeg', 'image/png', 'image/webp']) ? 'inline' : 'attachment');
    }

    public function grants(Request $request, User $user)
    {
        $this->noImpersonation($request);
        abort_unless($this->access->admin($request->user()), 403);
        $v = $request->validate(['expected_version' => 'required|integer|min:0', 'grants' => 'present|array', 'grants.*.platform_id' => 'required|integer|exists:platforms,id', 'grants.*.capability' => 'required|in:review,export', 'reason' => 'required|string|max:2000']);
        abort_unless($user->isActive() && in_array($user->role, ['admin', 'sub_admin', 'sales', 'field_sales']), 422, 'Choose an active eligible staff account.');

        return DB::transaction(function () use ($request, $user, $v) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $versionKey = 'grant_version:'.$user->id;
            $currentVersion = (int) DB::table('payment_review_settings')->where('key', $versionKey)->value('value');
            abort_unless($currentVersion === (int) $v['expected_version'], 409, 'Review access changed. Reload before saving.');
            foreach ($v['grants'] as $g) {
                if ($g['capability'] === 'export') {
                    abort_unless(collect($v['grants'])->contains(fn ($x) => (int) $x['platform_id'] === (int) $g['platform_id'] && $x['capability'] === 'review'), 422, 'Export access also requires review access for the same market.');
                }
            }

            foreach ($v['grants'] as $g) {
                app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($user, (int) $g['platform_id']);
            }
            $before = DB::table('payment_review_grants')->where('user_id', $user->id)->get()->toArray();
            DB::table('payment_review_grants')->where('user_id', $user->id)->delete();
            foreach ($v['grants'] as $g) {
                DB::table('payment_review_grants')->insertOrIgnore(['user_id' => $user->id, 'platform_id' => $g['platform_id'], 'capability' => $g['capability'], 'granted_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            abort_unless(app(\App\Services\AuditService::class)->record(['platform_id' => $v['grants'][0]['platform_id'] ?? Platform::value('id'), 'actor_id' => $request->user()->id, 'entity_type' => 'payment_review_grants', 'entity_id' => $user->id, 'action' => 'daily_review_grants', 'before_state' => $before, 'after_state' => $v['grants'], 'reason' => $v['reason']]), 500, 'Access audit failed.');

            DB::table('payment_review_settings')->updateOrInsert(['key' => $versionKey], ['value' => (string) ($currentVersion + 1)]);

            return response()->json(['grants' => DB::table('payment_review_grants')->where('user_id', $user->id)->get()]);
        });
    }

    public function export(Request $request)
    {
        $result = $this->reviews->listing($request->user(), $this->filters($request), true);

        return response()->streamDownload(function () use ($result) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Receipt', 'Reporting day', 'Currency', 'Recorded amount', 'Original reference', 'Reference annotation', 'Review status', 'Flag', 'Reviewer ID', 'Reviewed at', 'Reason', 'Admin self approval', 'Snapshot at']);
            foreach ($result['data'] as $u) {
                $revisions = $u['correction']['response_revisions_json'] ?? [];
                $last = end($revisions) ?: [];
                $row = [$u['key'], $u['business_date'], $u['currency'], $u['amount'], $u['reference'], $last['reference_annotation'] ?? '', $u['review_status'], $u['flag_reason'], $u['decided_by'], $u['decided_at'], $u['decision_reason'], $u['self_approval'] ? 'Yes' : 'No', $result['meta']['as_of']];
                fputcsv($stream, array_map(fn ($v) => preg_match('/^[\s]*[=+@-]/u', (string) $v) ? "'".$v : $v, $row));
            }
            fclose($stream);
        }, 'daily-reconciliation-'.$result['meta']['date'].'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
    }

    private function load(Request $r, string $type, int $id, bool $lock = false): array
    {
        if ($this->access->markets($r->user())) {
            try {
                return $this->reviews->unit($r->user(), $type, $id, $lock);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
            }
        }

        return $this->reviews->unit($r->user(), $type, $id, $lock, assigned: true);
    }

    private function noImpersonation(Request $r): void
    {
        abort_if($r->attributes->get('crm_impersonator') || $r->attributes->get('impersonation') || $r->header('X-Impersonation-Token'), 403, 'Exit impersonation before making financial review decisions.');
    }

    private function filters(Request $r): array
    {
        return $r->validate(['date' => 'nullable|date_format:Y-m-d', 'platform_id' => 'nullable|integer|exists:platforms,id', 'channel' => 'nullable|in:all,manual,provider,other',
            'owner_id' => 'nullable|integer', 'status' => 'nullable|in:pending,correction_requested,resubmitted,approved,denied', 'search' => 'nullable|string|max:120',
            'history' => 'nullable|boolean', 'flagged' => 'nullable|boolean', 'earlier_unresolved' => 'nullable|boolean', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
    }
}
