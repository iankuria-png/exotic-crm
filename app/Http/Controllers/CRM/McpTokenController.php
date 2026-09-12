<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\McpTokenLimit;
use App\Models\McpToolCall;
use App\Models\User;
use App\Services\Mcp\McpSettingsService;
use App\Services\Mcp\PromptRegistry;
use App\Services\Mcp\ResourceRegistry;
use App\Services\Mcp\ToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class McpTokenController extends Controller
{
    public function __construct(private readonly ToolRegistry $registry, private readonly McpSettingsService $settings, private readonly PromptRegistry $prompts, private readonly ResourceRegistry $resources) {}

    public function options()
    {
        return response()->json([
            'owners' => User::query()->where('status', 'active')->whereIn('role', ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'])->orderBy('name')->get(['id', 'name', 'role'])->map(fn ($user) => ['id' => $user->id, 'label' => $user->name, 'role' => $user->role]),
            'protocols' => config('mcp.waves.contracts_2026') ? ['2026-07-28'] : [],
            'tools' => collect(['exotic_search_knowledge', 'exotic_get_document', 'exotic_payment_flow_trace', 'exotic_payment_failure_diagnosis', 'exotic_system_vitals_live', 'exotic_error_digest_live'])->map(fn ($name) => ['name' => $name, 'available' => $this->registry->metadata($name) !== null])->values(),
            'prompts' => array_keys($this->prompts->all()),
        ]);
    }

    public function index()
    {
        $weekAgo = now()->subDays(7);
        $stats = McpToolCall::query()
            ->select('token_id')
            ->selectRaw('COUNT(*) as calls_7d')
            ->selectRaw('COALESCE(SUM(bytes_out), 0) as bytes_7d')
            ->where('created_at', '>=', $weekAgo)
            ->whereNotNull('token_id')
            ->groupBy('token_id')
            ->get()
            ->keyBy('token_id');

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
                'status' => $token->expires_at && $token->expires_at->isPast() ? 'expired' : 'active',
                'calls_7d' => (int) ($stats[$token->id]->calls_7d ?? 0),
                'bytes_7d' => (int) ($stats[$token->id]->bytes_7d ?? 0),
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
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'protocol' => ['nullable', 'in:2026-07-28'],
            'resources' => ['nullable', 'array'], 'resources.*' => ['string', 'max:255'],
            'prompts' => ['nullable', 'array'], 'prompts.*' => ['string', 'max:80'],
            'daily_rows' => ['nullable', 'integer', 'min:1'], 'daily_bytes' => ['nullable', 'integer', 'min:1'],
        ]);
        $ttl = (int) ($data['ttl_days'] ?? 90);
        $owner = isset($data['owner_id']) ? User::query()->findOrFail($data['owner_id']) : $request->user();
        abort_unless($owner->isActive(), 422, 'Token owner must be active.');
        $abilities = ['mcp:read'];
        if (isset($data['protocol'])) {
            abort_unless(config('mcp.waves.contracts_2026'), 422, 'The modern protocol is disabled.');
            $abilities[] = 'mcp:protocol:2026-07-28';
        }
        foreach ((array) ($data['tools'] ?? []) as $tool) {
            abort_unless($this->registry->metadata($tool) !== null, 422, 'Unknown MCP tool.');
            $abilities[] = 'mcp:tool:'.$tool;
        }
        foreach ((array) ($data['resources'] ?? []) as $resource) {
            abort_unless(str_starts_with($resource, 'exotic://'), 422, 'Unknown MCP resource.');
            $abilities[] = 'mcp:resource:'.$resource;
        }
        foreach ((array) ($data['prompts'] ?? []) as $prompt) {
            abort_unless(array_key_exists($prompt, $this->prompts->all()), 422, 'Unknown MCP prompt.');
            $abilities[] = 'mcp:prompt:'.$prompt;
        }
        $limits = $this->settings->effectiveLimits();
        $rows = min((int) ($data['daily_rows'] ?? $limits['token_rows']), $limits['server_rows']);
        $bytes = min((int) ($data['daily_bytes'] ?? $limits['token_bytes']), $limits['server_bytes']);
        $token = DB::transaction(function () use ($owner, $data, $abilities, $ttl, $rows, $bytes, $request) {
            $token = $owner->createToken('mcp:'.trim($data['label']), array_values(array_unique($abilities)), now()->addDays($ttl));
            McpTokenLimit::create(['token_id' => $token->accessToken->id, 'daily_rows' => $rows, 'daily_bytes' => $bytes, 'created_by' => $request->user()->id]);

            return $token;
        });

        return response()->json([
            'token' => $token->plainTextToken,
            'label' => $data['label'],
            'expires_at' => now()->addDays($ttl)->toISOString(),
            'owner' => $owner->id,
            'abilities' => array_values(array_unique($abilities)),
            'limits' => ['daily_rows' => $rows, 'daily_bytes' => $bytes],
        ], 201);
    }

    public function destroy(PersonalAccessToken $token)
    {
        abort_unless(str_starts_with((string) $token->name, 'mcp:'), 404);
        $token->delete();

        return response()->json(['status' => 'revoked']);
    }
}
