<?php

namespace App\Services\Mcp;

use App\Models\AuditLog;
use App\Models\McpTokenLimit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class McpTokenGrantService
{
    public function __construct(private readonly ToolRegistry $tools, private readonly ResourceRegistry $resources, private readonly PromptRegistry $prompts, private readonly McpSettingsService $settings) {}

    public function update(PersonalAccessToken $token, User $actor, array $input): array
    {
        abort_unless(str_starts_with((string) $token->name, 'mcp:'), 404);
        $owner = $token->tokenable;
        if (! $owner instanceof User || ! $owner->isActive()) {
            abort(422, 'The MCP grant owner must be an active CRM user.');
        }
        $currentAbilities = (array) $token->abilities;
        $tools = array_values(array_unique(array_key_exists('tools', $input)
            ? (array) $input['tools']
            : $this->grantedNames($currentAbilities, 'mcp:tool:')));
        $resources = array_values(array_unique(array_key_exists('resources', $input)
            ? (array) $input['resources']
            : $this->grantedNames($currentAbilities, 'mcp:resource:')));
        $prompts = array_values(array_unique(array_key_exists('prompts', $input)
            ? (array) $input['prompts']
            : $this->grantedNames($currentAbilities, 'mcp:prompt:')));
        $availableTools = collect($this->tools->definitions($owner, $this->settings))->pluck('name')->all();
        $availableResources = collect($this->resources->list(McpAuthorizationContext::for($owner, ['mcp:read'], app(\App\Services\MarketAuthorizationService::class)), $this->settings, false))->pluck('uri')->all();
        $availablePrompts = collect($this->prompts->list(McpAuthorizationContext::for($owner, ['mcp:read'], app(\App\Services\MarketAuthorizationService::class))))->pluck('name')->all();
        if (array_diff($tools, $availableTools) || array_diff($resources, $availableResources) || array_diff($prompts, $availablePrompts)) {
            abort(422, 'A requested MCP grant is unavailable to this owner or registry.');
        }
        $identifiedClients = array_key_exists('identified_clients', $input)
            ? (bool) $input['identified_clients']
            : in_array('mcp:identified-clients', $currentAbilities, true);
        if ($identifiedClients && $owner->role !== 'admin') {
            abort(422, 'Only an administrator-owned grant can receive identified-client access.');
        }

        return DB::transaction(function () use ($token, $actor, $input, $tools, $resources, $prompts, $identifiedClients): array {
            $before = ['abilities' => $token->abilities, 'expires_at' => optional($token->expires_at)->toIso8601String()];
            $abilities = ['mcp:read'];
            foreach ($tools as $tool) {
                $abilities[] = 'mcp:tool:'.$tool;
            }
            foreach ($resources as $resource) {
                $abilities[] = 'mcp:resource:'.$resource;
            }
            foreach ($prompts as $prompt) {
                $abilities[] = 'mcp:prompt:'.$prompt;
            }
            if ($identifiedClients) {
                $abilities[] = 'mcp:identified-clients';
            }
            $token->forceFill(['abilities' => $abilities, 'expires_at' => $input['expires_at'] ?? $token->expires_at])->save();
            $grant = DB::table('mcp_token_grants')->where('token_id', $token->id)->first();
            $version = ((int) ($grant->grant_version ?? 0)) + 1;
            DB::table('mcp_token_grants')->updateOrInsert(['token_id' => $token->id], ['grant_version' => $version, 'tools' => json_encode($tools), 'resources' => json_encode($resources), 'prompts' => json_encode($prompts), 'updated_at' => now(), 'created_at' => $grant->created_at ?? now()]);
            if (isset($input['daily_rows']) || isset($input['daily_bytes'])) {
                McpTokenLimit::query()->updateOrCreate(['token_id' => $token->id], ['daily_rows' => max(1, (int) ($input['daily_rows'] ?? $this->settings->effectiveLimits($token->id)['token_rows'])), 'daily_bytes' => max(1, (int) ($input['daily_bytes'] ?? $this->settings->effectiveLimits($token->id)['token_bytes']))]);
            }
            AuditLog::query()->create(['actor_id' => $actor->id, 'action' => 'mcp_token_grant_update', 'entity_type' => 'mcp_token_grant', 'entity_id' => $token->id, 'before_state' => ['tool_count' => count(array_filter($before['abilities'], fn ($ability) => str_starts_with($ability, 'mcp:tool:'))), 'expires_at' => $before['expires_at']], 'after_state' => ['tool_count' => count($tools), 'resource_count' => count($resources), 'prompt_count' => count($prompts), 'identified_clients' => in_array('mcp:identified-clients', $abilities, true), 'grant_version' => $version, 'secret_rotated' => false], 'created_at' => now()]);

            return ['id' => $token->id, 'capabilities' => ['tools' => $tools, 'resources' => $resources, 'prompts' => $prompts, 'identified_clients' => in_array('mcp:identified-clients', $abilities, true)], 'limits' => $this->settings->effectiveLimits($token->id), 'grant_version' => $version, 'secret_rotated' => false];
        });
    }

    /** @return array<int, string> */
    private function grantedNames(array $abilities, string $prefix): array
    {
        return array_values(array_map(
            fn (string $ability) => substr($ability, strlen($prefix)),
            array_filter($abilities, fn ($ability) => str_starts_with((string) $ability, $prefix)),
        ));
    }
}
