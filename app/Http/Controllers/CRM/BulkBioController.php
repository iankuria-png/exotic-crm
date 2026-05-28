<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBulkBioBatchJob;
use App\Models\SeoBioBatch;
use App\Models\SeoBioBatchRow;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\BulkBioRowResolver;
use App\Services\WpSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Bulk bio generation endpoints.
 *
 *   POST   /api/crm/seo/bulk/preview   — parse + resolve paste WITHOUT creating a batch
 *   POST   /api/crm/seo/bulk           — create a batch and queue the job
 *   GET    /api/crm/seo/bulk           — list recent batches for the current user
 *   GET    /api/crm/seo/bulk/{batch}   — status + rows for one batch
 *   POST   /api/crm/seo/bulk/{batch}/accept — push selected generated rows back to WP
 *   POST   /api/crm/seo/bulk/{batch}/cancel — cancel an in-flight batch
 *   DELETE /api/crm/seo/bulk/{batch}   — discard the batch (and its rows)
 */
class BulkBioController extends Controller
{
    public function __construct(private readonly BulkBioRowResolver $resolver) {}

    /**
     * Dry-run: parse pasted content, resolve URLs to clients, return summary
     * for the editor to review BEFORE we create a batch + dispatch the job.
     */
    public function preview(Request $request): JsonResponse
    {
        if (!config('services.seo_engine.enabled', false)) {
            return response()->json(['message' => 'SEO Engine is disabled.'], 403);
        }

        $data = $request->validate([
            'platform_id' => 'required|integer|min:1',
            'content'     => 'required|string|max:200000',
        ]);

        $rows = $this->resolver->parse($data['content'], (int) $data['platform_id']);
        $summary = $this->resolver->summarize($rows);

        if ($summary['total'] === 0) {
            return response()->json([
                'rows'    => [],
                'summary' => $summary,
                'message' => 'No URLs/IDs/slugs found in the pasted content.',
            ], 422);
        }

        return response()->json([
            'rows'    => $rows,
            'summary' => $summary,
            'max_rows' => BulkBioRowResolver::MAX_ROWS,
        ]);
    }

    /**
     * Create a batch from a pasted content string and queue the background job.
     */
    public function store(Request $request): JsonResponse
    {
        if (!config('services.seo_engine.enabled', false)) {
            return response()->json(['message' => 'SEO Engine is disabled.'], 403);
        }

        $data = $request->validate([
            'platform_id'     => 'required|integer|min:1',
            'content'         => 'required|string|max:200000',
            'language'        => ['nullable', 'string', Rule::in(array_keys(BioGenerationService::SUPPORTED_LANGUAGES))],
            'auto_save_to_wp' => 'nullable|boolean',
            'generation_options' => 'nullable|array',
            'generation_options.tone' => 'nullable|string|max:180',
            'generation_options.temperament' => 'nullable|string|max:180',
            'generation_options.min_words' => 'nullable|integer|min:25|max:500',
            'generation_options.max_words' => 'nullable|integer|min:40|max:700',
            'generation_options.max_characters' => 'nullable|integer|min:200|max:5000',
            'generation_options.max_services' => 'nullable|integer|min:0|max:20',
            'generation_options.include_location' => 'nullable|boolean',
            'generation_options.include_services' => 'nullable|boolean',
            'generation_options.include_contact' => 'nullable|boolean',
            'generation_options.contact_channel' => 'nullable|string|in:none,phone,whatsapp,both',
            'generation_options.custom_prompt' => 'nullable|string|max:2000',
        ]);

        $rows = $this->resolver->parse($data['content'], (int) $data['platform_id']);
        if (count($rows) === 0) {
            return response()->json([
                'message' => 'No URLs/IDs/slugs found in the pasted content.',
            ], 422);
        }

        $batch = DB::transaction(function () use ($request, $data, $rows) {
            $batch = SeoBioBatch::create([
                'platform_id'        => (int) $data['platform_id'],
                'user_id'            => $request->user()?->id,
                'language'           => $data['language'] ?? 'en',
                'generation_options' => $data['generation_options'] ?? [],
                'status'             => SeoBioBatch::STATUS_QUEUED,
                'total_rows'         => count($rows),
                'source_paste'       => mb_substr($data['content'], 0, 20000),
                'auto_save_to_wp'    => (bool) ($data['auto_save_to_wp'] ?? false),
            ]);

            $now = now();
            $payloads = array_map(static function (array $row) use ($batch, $now) {
                return array_merge($row, [
                    'batch_id'   => $batch->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }, $rows);

            SeoBioBatchRow::insert($payloads);

            return $batch;
        });

        ProcessBulkBioBatchJob::dispatch($batch->id);

        Log::info('seo.bulk.batch_created', [
            'batch_id'    => $batch->id,
            'platform_id' => $batch->platform_id,
            'rows'        => $batch->total_rows,
            'language'    => $batch->language,
            'auto_save'   => $batch->auto_save_to_wp,
            'user_id'     => $batch->user_id,
        ]);

        return response()->json([
            'batch'   => $this->serializeBatch($batch->fresh()),
            'message' => 'Bulk bio generation queued.',
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $batches = SeoBioBatch::query()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'batches' => $batches->map(fn ($b) => $this->serializeBatch($b))->all(),
        ]);
    }

    public function show(Request $request, SeoBioBatch $batch): JsonResponse
    {
        return response()->json([
            'batch' => $this->serializeBatch($batch),
            'rows'  => $batch->rows()
                ->orderBy('row_index')
                ->get()
                ->map(fn ($r) => $this->serializeRow($r))
                ->all(),
        ]);
    }

    /**
     * Accept N generated rows: push their bio_html + score to WP and mark
     * accepted. Skips rows the editor didn't select.
     */
    public function accept(Request $request, SeoBioBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'row_ids'   => 'required|array|min:1|max:500',
            'row_ids.*' => 'integer|min:1',
        ]);

        $rows = $batch->rows()
            ->whereIn('id', $data['row_ids'])
            ->where('status', SeoBioBatchRow::STATUS_GENERATED)
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No accepted rows to push.'], 422);
        }

        try {
            $wpSync = WpSyncService::forPlatform((int) $batch->platform_id);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'WordPress sync is not configured for this market: ' . $e->getMessage(),
            ], 502);
        }

        $accepted = 0;
        $failures = [];
        foreach ($rows as $row) {
            if (!$row->wp_post_id) {
                $row->update(['status' => SeoBioBatchRow::STATUS_SKIPPED, 'error' => 'No wp_post_id on row.']);
                continue;
            }
            try {
                $wpSync->updateClientProfile((int) $row->wp_post_id, ['content' => $row->bio_html]);
                $wpSync->writeSeoScore((int) $row->wp_post_id, (int) $row->score, (array) $row->breakdown);
                $row->update(['status' => SeoBioBatchRow::STATUS_ACCEPTED]);
                $accepted++;
            } catch (\Throwable $e) {
                $row->update(['error' => 'Save to WP failed: ' . $e->getMessage()]);
                $failures[] = ['row_id' => $row->id, 'error' => $e->getMessage()];
            }
        }

        $batch->increment('accepted_rows', $accepted);
        $batch->refresh();

        // Mark batch completed if every generated row is now accepted or skipped
        $stillGenerated = $batch->rows()->where('status', SeoBioBatchRow::STATUS_GENERATED)->count();
        if ($stillGenerated === 0) {
            $batch->update(['status' => SeoBioBatch::STATUS_COMPLETED]);
        }

        return response()->json([
            'accepted_count' => $accepted,
            'failed_count'   => count($failures),
            'failures'       => $failures,
            'batch'          => $this->serializeBatch($batch->fresh()),
        ]);
    }

    public function cancel(Request $request, SeoBioBatch $batch): JsonResponse
    {
        if ($batch->isTerminal()) {
            return response()->json(['message' => 'Batch is already finished.', 'batch' => $this->serializeBatch($batch)]);
        }

        $batch->update([
            'status'      => SeoBioBatch::STATUS_CANCELLED,
            'finished_at' => now(),
        ]);

        // Mark queued rows as skipped so the editor can see what didn't run.
        $batch->rows()
            ->where('status', SeoBioBatchRow::STATUS_QUEUED)
            ->update(['status' => SeoBioBatchRow::STATUS_SKIPPED]);

        return response()->json(['batch' => $this->serializeBatch($batch->fresh())]);
    }

    public function destroy(Request $request, SeoBioBatch $batch): JsonResponse
    {
        $batch->delete(); // cascades to rows via FK
        return response()->json(['message' => 'Batch deleted.']);
    }

    // -----------------------------------------------------------------

    private function serializeBatch(SeoBioBatch $batch): array
    {
        return [
            'id'                 => (int) $batch->id,
            'platform_id'        => (int) $batch->platform_id,
            'user_id'            => $batch->user_id,
            'language'           => $batch->language,
            'status'             => $batch->status,
            'total_rows'         => (int) $batch->total_rows,
            'processed_rows'     => (int) $batch->processed_rows,
            'succeeded_rows'     => (int) $batch->succeeded_rows,
            'failed_rows'        => (int) $batch->failed_rows,
            'accepted_rows'      => (int) $batch->accepted_rows,
            'auto_save_to_wp'    => (bool) $batch->auto_save_to_wp,
            'generation_options' => $batch->generation_options,
            'error'              => $batch->error,
            'created_at'         => $batch->created_at?->toIso8601String(),
            'started_at'         => $batch->started_at?->toIso8601String(),
            'finished_at'        => $batch->finished_at?->toIso8601String(),
        ];
    }

    private function serializeRow(SeoBioBatchRow $row): array
    {
        return [
            'id'             => (int) $row->id,
            'row_index'      => (int) $row->row_index,
            'input_text'     => $row->input_text,
            'input_url'      => $row->input_url,
            'wp_post_id'     => $row->wp_post_id,
            'client_id'      => $row->client_id,
            'profile_name'   => $row->profile_name,
            'status'         => $row->status,
            'bio_html'       => $row->bio_html,
            'score'          => $row->score,
            'breakdown'      => $row->breakdown,
            'provider_used'  => $row->provider_used,
            'error'          => $row->error,
            'processed_at'   => $row->processed_at?->toIso8601String(),
        ];
    }
}
