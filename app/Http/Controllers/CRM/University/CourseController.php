<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Course;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    use SerializesUniversityPayloads;

    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function index(Request $request)
    {
        $isAdmin = $this->isUniversityAdmin($request);
        $query = Course::query()
            ->with(['modules.lessons.media', 'certifications.questions'])
            ->orderBy('order')
            ->orderBy('title');

        if (!$isAdmin) {
            $query->published();
            $role = $request->user()->role;
            $query->where(function ($visibilityQuery) use ($role) {
                $visibilityQuery->where('visibility', 'all')
                    ->orWhere('visibility', $role)
                    ->orWhereJsonContains('required_for_roles', $role);
            });
        } elseif ($request->filled('status') && (string) $request->string('status') !== 'all') {
            $query->where('status', (string) $request->string('status'));
        }

        $search = trim((string) $request->string('search'));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('title', 'like', "%{$search}%")
                    ->orWhere('summary', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'courses' => $query->get()->map(fn (Course $course) => $this->serializeCourse($course, $request->user()->id, $isAdmin))->values(),
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $isAdmin = $this->isUniversityAdmin($request);
        $course = Course::query()
            ->with(['modules.lessons.media', 'certifications.questions.options'])
            ->where('slug', $slug)
            ->firstOrFail();

        if (!$isAdmin && $course->status !== 'published') {
            abort(404);
        }

        return response()->json([
            'course' => $this->serializeCourse($course, $request->user()->id, $isAdmin),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateCourse($request);
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);
        $validated['author_id'] = $request->user()->id;
        if (($validated['status'] ?? 'draft') === 'published') {
            $validated['published_at'] = now();
        }

        $course = Course::create($validated);
        $this->auditService->fromSystemRequest($request, 'university_course_created', 'university_course', (int) $course->id, null, $course->toArray(), 'Created university course');

        return response()->json([
            'message' => 'Course created.',
            'course' => $this->serializeCourse($course->fresh(['modules.lessons.media', 'certifications']), $request->user()->id, true),
        ], 201);
    }

    public function update(Request $request, Course $course)
    {
        $validated = $this->validateCourse($request, partial: true);
        $before = $course->toArray();
        if (isset($validated['title']) && empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['title']);
        }
        if (($validated['status'] ?? null) === 'published' && !$course->published_at) {
            $validated['published_at'] = now();
        }
        if (($validated['status'] ?? null) === 'draft') {
            $validated['published_at'] = null;
        }

        $course->update($validated);
        $this->auditService->fromSystemRequest($request, 'university_course_updated', 'university_course', (int) $course->id, $before, $course->fresh()->toArray(), 'Updated university course');

        return response()->json([
            'message' => 'Course updated.',
            'course' => $this->serializeCourse($course->fresh(['modules.lessons.media', 'certifications']), $request->user()->id, true),
        ]);
    }

    public function destroy(Request $request, Course $course)
    {
        $before = $course->toArray();
        $courseId = (int) $course->id;
        $course->delete();
        $this->auditService->fromSystemRequest($request, 'university_course_deleted', 'university_course', $courseId, $before, null, 'Deleted university course');

        return response()->json(['message' => 'Course deleted.']);
    }

    private function validateCourse(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'slug' => [$partial ? 'sometimes' : 'nullable', 'string', 'max:180'],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'cover_image_path' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:draft,published,archived'],
            'visibility' => ['sometimes', 'in:all,sales,cs,admin,sub_admin,marketing'],
            'required_for_roles' => ['nullable', 'array'],
            'required_for_roles.*' => ['string', 'max:60'],
            'prerequisite_course_id' => ['nullable', 'integer', 'exists:university_courses,id'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }
}
