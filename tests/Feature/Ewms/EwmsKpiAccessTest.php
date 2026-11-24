<?php

namespace Tests\Feature\Ewms;

use App\Models\User;
use App\Services\Ewms\EwmsKpiAccess;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class EwmsKpiAccessTest extends EwmsKpiTestCase
{
    public function test_absent_invalid_expired_and_revoked_tokens_are_rejected(): void
    {
        $this->evidence(null)->assertUnauthorized();
        $this->evidence('invalid-secret')->assertUnauthorized();
        $this->evidence($this->token(expired: true))->assertUnauthorized();
        $token = $this->token();
        PersonalAccessToken::findToken($token)->delete();
        $this->evidence($token)->assertUnauthorized();
        $this->assertSame(0, DB::table('audit_log')->count());
    }

    public function test_exact_ability_active_integration_role_and_bearer_are_all_required(): void
    {
        foreach ([[['*'], EwmsKpiAccess::ROLE, 'active'], [[EwmsKpiAccess::ABILITY, 'other:read'], EwmsKpiAccess::ROLE, 'active'], [[EwmsKpiAccess::ABILITY], 'admin', 'active'], [[EwmsKpiAccess::ABILITY], EwmsKpiAccess::ROLE, 'inactive'], [[], EwmsKpiAccess::ROLE, 'active']] as [$abilities, $role, $status]) {
            $this->evidence($this->token($abilities, $role, $status))->assertForbidden();
        }
        $this->evidence($this->token(['mcp:read']))->assertForbidden();
        $user = User::where('role', EwmsKpiAccess::ROLE)->first();
        $this->actingAs($user, 'web')->postJson('/'.EwmsKpiAccess::RECORDS_PATH, ['platform_ids' => [1], 'from' => '2026-11-14', 'to' => '2026-11-21'])->assertUnauthorized();
        $this->assertSame(0, DB::table('audit_log')->count());
    }

    public function test_disabled_and_empty_or_malformed_allow_lists_fail_closed(): void
    {
        $token = $this->token();
        config(['ewms.enabled' => false]);
        $this->evidence($token)->assertStatus(503)->assertJsonPath('code', 'ewms_disabled');
        config(['ewms.enabled' => true]);
        foreach ([[], ['1', 'invalid'], [0], [1.2], '1,2'] as $allowed) {
            config(['ewms.allowed_platform_ids' => $allowed]);
            $this->evidence($token)->assertForbidden();
        }
        config(['ewms.allowed_platform_ids' => [1, 999]]);
        $this->evidence($token, ['platform_ids' => [999], 'from' => '2026-11-14', 'to' => '2026-11-21'])->assertForbidden();
        config(['ewms.allowed_platform_ids' => [1]]);
        $this->evidence($token, ['platform_ids' => [1, 2], 'from' => '2026-11-14', 'to' => '2026-11-21'])->assertForbidden();
        $this->assertSame(0, DB::table('audit_log')->count());
    }

    public function test_ewms_token_cannot_reach_other_api_routes_and_regular_ping_is_unchanged(): void
    {
        $token = $this->token();
        foreach (['/api/ping', '/api/platforms', '/api/crm/me'] as $path) {
            $this->app['auth']->forgetGuards();
            $this->getJson($path, ['Authorization' => 'Bearer '.$token])->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/mcp', [], ['Authorization' => 'Bearer '.$token])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/ping')->assertOk()->assertJsonPath('message', 'API is working!');
        $this->evidence($token)->assertOk();
    }

    public function test_validation_rejects_unknown_fields_bad_periods_id_counts_and_mixed_modes(): void
    {
        $token = $this->token();
        $base = ['platform_ids' => [1], 'from' => '2026-11-14', 'to' => '2026-11-21'];
        foreach ([
            ['crm_user_ids' => [1]], ['platform_ids' => []], ['platform_ids' => [1, 1]], ['platform_ids' => [0]],
            ['platform_ids' => range(1, 101)], ['from' => '2026-02-30'], ['to' => '2026-11-01'],
            ['from' => '2026-01-01', 'to' => '2026-04-04'], ['limit' => 0], ['limit' => 501], ['limit' => null],
            ['payment_ids' => [1]], ['payment_ids' => null],
        ] as $change) {
            $this->evidence($token, array_replace($base, $change))->assertUnprocessable();
        }
        foreach ([['payment_ids' => []], ['payment_ids' => [1, 1]], ['payment_ids' => range(1, 101)], ['payment_ids' => [0]], ['payment_ids' => [1], 'limit' => null]] as $change) {
            $this->evidence($token, array_replace(['platform_ids' => [1]], $change))->assertUnprocessable();
        }
        $this->evidence($token, array_replace($base, ['limit' => 1]), true)->assertUnprocessable();
        $this->evidence($token, ['platform_ids' => [1], 'from' => '2026-01-01', 'to' => '2026-04-03'])->assertOk();
    }

    public function test_rate_limit_is_per_token_and_refuses_the_thirty_first_read(): void
    {
        $token = $this->token();
        for ($i = 0; $i < 30; $i++) {
            $this->evidence($token)->assertOk();
        }
        $this->evidence($token)->assertStatus(429);
        $this->evidence($this->token())->assertOk();
        $this->assertSame(31, DB::table('audit_log')->count());
    }

    public function test_issuer_reuses_the_identity_preserves_existing_tokens_and_never_prints_the_secret(): void
    {
        $existing = $this->token();
        $user = PersonalAccessToken::findToken($existing)->tokenable;
        $filename = 'fixture-'.bin2hex(random_bytes(6)).'.json';
        $path = storage_path('app/private/ewms/'.$filename);
        try {
            $this->artisan('ewms:kpi-token', ['--user-id' => $user->id, '--output' => $filename, '--expires-in' => 7])
                ->expectsOutputToContain('Restricted token saved')->assertSuccessful();
            $this->assertSame(2, $user->tokens()->count());
            $issued = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([EwmsKpiAccess::ABILITY], PersonalAccessToken::findToken($issued['token'])->abilities);
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->artisan('ewms:kpi-token', ['--user-id' => $user->id, '--output' => $filename])->assertFailed();
            $this->assertSame(2, $user->tokens()->count());
            $this->assertNotNull(PersonalAccessToken::findToken($existing));
            $this->artisan('ewms:kpi-token', ['--user-id' => $user->id, '--output' => '../public/secret.json'])->assertFailed();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
