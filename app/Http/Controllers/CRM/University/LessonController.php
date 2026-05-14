<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Lesson;
use App\Models\University\Module;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LessonController extends Controller
{
    use SerializesUniversityPayloads;

    public function store(Request $request, Module $module)
    {
        $validated = $this->validateLesson($request);
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);
        if (($validated['status'] ?? 'draft') === 'published') {
            $validated['body'] = $validated['body'] ?? $validated['body_draft'] ?? null;
        }

        $lesson = $module->lessons()->create($validated);

        return response()->json([
            'message' => 'Lesson created.',
            'lesson' => $this->serializeLesson($lesson->fresh('media'), null, true),
        ], 201);
    }

    public function update(Request $request, Lesson $lesson)
    {
        $validated = $this->validateLesson($request, partial: true);
        if (isset($validated['title']) && empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['title']);
        }
        if (($validated['status'] ?? null) === 'published') {
            $validated['body'] = $validated['body'] ?? $validated['body_draft'] ?? $lesson->body_draft ?? $lesson->body;
        }

        $lesson->update($validated);

        return response()->json([
            'message' => 'Lesson updated.',
            'lesson' => $this->serializeLesson($lesson->fresh('media'), null, true),
        ]);
    }

    public function uploadMedia(Request $request, Lesson $lesson)
    {
        $validated = $request->validate([
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,mp4,pdf', 'max:51200'],
            'embed_url' => ['nullable', 'url', 'max:2048'],
            'kind' => ['nullable', 'in:image,video,pdf,embed'],
            'caption' => ['nullable', 'string', 'max:255'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (!$request->hasFile('file') && empty($validated['embed_url'])) {
            return response()->json(['message' => 'Upload a file or provide an embed URL.'], 422);
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $mime = $file->getMimeType();
            if (str_starts_with((string) $mime, 'image/') && $file->getSize() > 5 * 1024 * 1024) {
                return response()->json(['message' => 'Images must be 5MB or smaller.'], 422);
            }
            if (str_starts_with((string) $mime, 'video/') && $file->getSize() > 50 * 1024 * 1024) {
                return response()->json(['message' => 'Videos must be 50MB or smaller.'], 422);
            }
            $path = $file->store('university/lessons/' . $lesson->id, 'public');
            $kind = str_starts_with((string) $mime, 'image/') ? 'image' : (str_starts_with((string) $mime, 'video/') ? 'video' : 'pdf');
            $media = $lesson->media()->create([
                'kind' => $validated['kind'] ?? $kind,
                'disk_path' => $path,
                'mime' => $mime,
                'size_bytes' => $file->getSize(),
                'caption' => $validated['caption'] ?? null,
                'order' => $validated['order'] ?? 0,
            ]);
        } else {
            $media = $lesson->media()->create([
                'kind' => 'embed',
                'embed_url' => $validated['embed_url'],
                'caption' => $validated['caption'] ?? null,
                'order' => $validated['order'] ?? 0,
            ]);
        }

        return response()->json([
            'message' => 'Lesson media added.',
            'media' => [
                'id' => $media->id,
                'kind' => $media->kind,
                'disk_path' => $media->disk_path,
                'embed_url' => $media->embed_url,
                'url' => $media->url,
                'caption' => $media->caption,
            ],
        ], 201);
    }

    public function destroyMedia(Lesson $lesson, int $mediaId)
    {
        $media = $lesson->media()->whereKey($mediaId)->firstOrFail();
        if ($media->disk_path) {
            Storage::disk('public')->delete($media->disk_path);
        }
        $media->delete();

        return response()->json(['message' => 'Lesson media deleted.']);
    }

    public function destroy(Lesson $lesson)
    {
        $lesson->delete();

        return response()->json(['message' => 'Lesson deleted.']);
    }

    private function validateLesson(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'slug' => [$partial ? 'sometimes' : 'nullable', 'string', 'max:180'],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'body_draft' => ['nullable', 'string'],
            'duration_minutes' => ['sometimes', 'integer', 'min:0'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:draft,published,archived'],
        ]);
    }
}
