<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\McpStaffAlias;
use App\Models\User;
use App\Services\Mcp\McpStaffAliasService;
use Illuminate\Http\Request;

class McpStaffAliasController extends Controller
{
    public function __construct(private readonly McpStaffAliasService $aliases) {}

    public function index()
    {
        $aliases = McpStaffAlias::query()->get()->keyBy('user_id');

        return response()->json(['staff' => User::query()
            ->where('status', 'active')
            ->whereIn('role', ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'])
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'agent_alias' => $aliases->get($user->id)?->agent_alias,
                'active' => (bool) ($aliases->get($user->id)?->active ?? false),
            ])->values()]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate(['agent_alias' => ['required', 'string'], 'active' => ['sometimes', 'boolean']]);
        abort_unless(in_array($user->role, ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'], true), 422, 'Only CRM staff can receive an MCP alias.');
        $alias = $this->aliases->save($user, $data['agent_alias'], (bool) ($data['active'] ?? true), $request->user());

        return response()->json(['user_id' => $alias->user_id, 'agent_alias' => $alias->agent_alias, 'active' => $alias->active]);
    }
}
