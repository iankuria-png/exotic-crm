<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class McpTokenController extends Controller
{
    public function index()
    {
        $tokens = PersonalAccessToken::query()
            ->where('name', 'like', 'mcp:%')
            ->with('tokenable:id,name,email,role')
            ->latest('id')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'label' => str_starts_with($token->name, 'mcp:') ? substr($token->name, 4) : $token->name,
                'owner' => $token->tokenable?->name,
                'role' => $token->tokenable?->role,
                'abilities' => $token->abilities,
                'expires_at' => optional($token->expires_at)->toISOString(),
                'last_used_at' => optional($token->last_used_at)->toISOString(),
            ]);

        return response()->json(['tokens' => $tokens]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'ttl_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'tools' => ['nullable', 'array'],
            'tools.*' => ['string', 'max:64'],
        ]);
        $ttl = (int) ($data['ttl_days'] ?? 90);
        $abilities = ['mcp:read'];
        foreach ((array) ($data['tools'] ?? []) as $tool) {
            $abilities[] = 'mcp:tool:'.$tool;
        }

        $token = $request->user()->createToken(
            'mcp:'.trim($data['label']),
            array_values(array_unique($abilities)),
            now()->addDays($ttl)
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'label' => $data['label'],
            'expires_at' => now()->addDays($ttl)->toISOString(),
        ], 201);
    }

    public function destroy(PersonalAccessToken $token)
    {
        abort_unless(str_starts_with((string) $token->name, 'mcp:'), 404);
        $token->delete();

        return response()->json(['status' => 'revoked']);
    }
}
