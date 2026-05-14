<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\GlossaryTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GlossaryController extends Controller
{
    use SerializesUniversityPayloads;

    public function index(Request $request)
    {
        $terms = GlossaryTerm::query()->orderBy('term')->get();

        return response()->json([
            'terms' => $terms->map(fn (GlossaryTerm $t) => $this->serializeTerm($t))->values(),
        ]);
    }

    public function show(GlossaryTerm $term)
    {
        return response()->json(['term' => $this->serializeTerm($term)]);
    }

    public function store(Request $request)
    {
        if (! $this->isUniversityAdmin($request)) abort(403);

        $validated = $request->validate([
            'term' => ['required', 'string', 'max:160'],
            'definition' => ['required', 'string'],
            'topic_tag' => ['sometimes', 'nullable', 'string', 'max:120'],
            'playbook_url' => ['sometimes', 'nullable', 'url'],
            'aliases' => ['sometimes', 'array'],
        ]);

        $term = GlossaryTerm::create([
            ...$validated,
            'slug' => Str::slug($validated['term']) . '-' . Str::random(4),
        ]);

        return response()->json(['term' => $this->serializeTerm($term)], 201);
    }

    public function update(Request $request, GlossaryTerm $term)
    {
        if (! $this->isUniversityAdmin($request)) abort(403);

        $validated = $request->validate([
            'term' => ['sometimes', 'string', 'max:160'],
            'definition' => ['sometimes', 'string'],
            'topic_tag' => ['sometimes', 'nullable', 'string', 'max:120'],
            'playbook_url' => ['sometimes', 'nullable', 'url'],
            'aliases' => ['sometimes', 'array'],
        ]);

        $term->fill($validated)->save();

        return response()->json(['term' => $this->serializeTerm($term)]);
    }

    public function destroy(Request $request, GlossaryTerm $term)
    {
        if (! $this->isUniversityAdmin($request)) abort(403);

        $term->delete();

        return response()->json(['message' => 'Term deleted.']);
    }

    private function serializeTerm(GlossaryTerm $term): array
    {
        return [
            'id' => $term->id,
            'term' => $term->term,
            'slug' => $term->slug,
            'definition' => $term->definition,
            'topic_tag' => $term->topic_tag,
            'playbook_url' => $term->playbook_url,
            'aliases' => $term->aliases ?: [],
        ];
    }
}
