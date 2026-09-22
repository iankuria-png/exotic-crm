<?php

namespace App\Services\Mcp;

use App\Models\McpStaffAlias;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class McpStaffAliasService
{
    public function aliasFor(int $userId): ?string
    {
        return McpStaffAlias::query()->where('user_id', $userId)->where('active', true)->value('agent_alias');
    }

    public function save(User $staff, string $alias, bool $active, User $actor): McpStaffAlias
    {
        $alias = strtolower(trim($alias));
        if (! preg_match('/^[a-z][a-z-]{2,15}$/', $alias) || ctype_digit($alias)) {
            throw ValidationException::withMessages(['agent_alias' => 'Use a unique 3–16 character word-style alias.']);
        }
        if (strcasecmp($alias, trim((string) $staff->name)) === 0) {
            throw ValidationException::withMessages(['agent_alias' => 'An MCP alias cannot be the staff member’s full name.']);
        }
        $existing = McpStaffAlias::query()->where('agent_alias', $alias)->where('user_id', '!=', $staff->id)->exists();
        if ($existing) {
            throw ValidationException::withMessages(['agent_alias' => 'This alias is already reserved. Choose a different word-style alias.']);
        }

        return McpStaffAlias::query()->updateOrCreate(
            ['user_id' => $staff->id],
            ['agent_alias' => $alias, 'active' => $active, 'updated_by' => $actor->id],
        );
    }

    /** Replace staff-only identity fields without changing non-staff CRM fields. */
    public function present(array $payload): array
    {
        return $this->walk($payload);
    }

    private function walk(array $value): array
    {
        $staffId = $value['user_id'] ?? ((isset($value['id'], $value['name'], $value['role']) && is_numeric($value['id'])) ? $value['id'] : null);
        if ($staffId !== null && isset($value['name']) && is_numeric($staffId)) {
            $alias = $this->aliasFor((int) $staffId);
            unset($value['user_id'], $value['name'], $value['id'], $value['email'], $value['phone']);
            if ($alias !== null) {
                $value['agent_alias'] = $alias;
            } else {
                $value['staff_visibility'] = 'alias_not_configured';
            }
        }
        if (isset($value['agent']) && is_array($value['agent']) && isset($value['agent']['id'])) {
            $alias = $this->aliasFor((int) $value['agent']['id']);
            $value['agent'] = $alias ? ['agent_alias' => $alias] : ['staff_visibility' => 'alias_not_configured'];
        }
        foreach ($value as $key => $child) {
            $value[$key] = is_array($child) ? $this->walk($child) : $child;
        }

        return $value;
    }
}
