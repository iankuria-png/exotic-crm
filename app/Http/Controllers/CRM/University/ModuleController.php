<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Course;
use App\Models\University\Module;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ModuleController extends Controller
{
    use SerializesUniversityPayloads;

    public function store(Request $request, Course $course)
    {
        $validated = $request->validate([
            'slug' => ['nullable', 'string', 'max:180'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);

        $module = $course->modules()->create($validated);

        return response()->json([
            'message' => 'Module created.',
            'module' => $module,
        ], 201);
    }

    public function update(Request $request, Module $module)
    {
        $validated = $request->validate([
            'slug' => ['sometimes', 'string', 'max:180'],
            'title' => ['sometimes', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);
        if (isset($validated['title']) && empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['title']);
        }

        $module->update($validated);

        return response()->json([
            'message' => 'Module updated.',
            'module' => $module->fresh('lessons.media'),
        ]);
    }

    public function destroy(Module $module)
    {
        $module->delete();

        return response()->json(['message' => 'Module deleted.']);
    }
}
