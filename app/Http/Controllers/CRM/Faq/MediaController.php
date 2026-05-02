<?php

namespace App\Http\Controllers\CRM\Faq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faq\StoreMediaRequest;
use App\Models\Faq\Article;
use App\Models\Faq\Media;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService
    ) {
    }

    public function store(StoreMediaRequest $request, Article $article)
    {
        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = sprintf('faq/%d/%s.%s', $article->id, Str::uuid()->toString(), $extension);
        Storage::disk('public')->putFileAs(sprintf('faq/%d', $article->id), $file, basename($path));

        $media = $article->media()->create([
            'kind' => str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image',
            'disk_path' => $path,
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'caption' => $request->input('caption'),
            'position' => (int) $request->integer('position', ($article->media()->max('position') ?? 0) + 1),
        ]);

        $this->auditService->fromSystemRequest(
            $request,
            'faq_media_created',
            'faq_media',
            (int) $media->id,
            null,
            $media->toArray(),
            'Uploaded FAQ media'
        );

        return response()->json([
            'message' => 'FAQ media uploaded.',
            'media' => [
                'id' => $media->id,
                'kind' => $media->kind,
                'caption' => $media->caption,
                'mime' => $media->mime,
                'size_bytes' => $media->size_bytes,
                'position' => $media->position,
                'url' => $media->url,
                'disk_path' => $media->disk_path,
            ],
        ], 201);
    }

    public function destroy(Request $request, Article $article, Media $media)
    {
        abort_unless($media->article_id === $article->id, 404);

        $before = $media->toArray();
        if ($media->disk_path) {
            Storage::disk('public')->delete($media->disk_path);
        }
        $mediaId = (int) $media->id;
        $media->delete();

        $this->auditService->fromSystemRequest(
            $request,
            'faq_media_deleted',
            'faq_media',
            $mediaId,
            $before,
            null,
            'Deleted FAQ media'
        );

        return response()->json([
            'message' => 'FAQ media deleted.',
        ]);
    }
}
