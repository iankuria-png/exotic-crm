<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An expired session must read as "sign in again", not "the server broke".
 *
 * There is no Laravel route named `login` — the SPA owns /login client-side —
 * so both the auth middleware and the framework's exception handler used to
 * resolve `route('login')` and throw RouteNotFoundException, turning every
 * unauthenticated non-JSON request into a 500.
 */
class UnauthenticatedResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function nonJsonHeaderSets(): array
    {
        return [
            'browser navigation' => [['Accept' => 'text/html,application/xhtml+xml']],
            'no accept header at all' => [[]],
            'wildcard accept' => [['Accept' => '*/*']],
        ];
    }

    /**
     * @dataProvider nonJsonHeaderSets
     * @param  array<string, string>  $headers
     */
    public function test_an_unauthenticated_request_without_json_headers_gets_401_not_500(array $headers): void
    {
        $response = $this->withHeaders($headers)->get('/api/crm/me');

        $response->assertStatus(401);
        $this->assertSame('Unauthenticated.', $response->json('message'));
    }

    public function test_the_json_path_is_unchanged_and_still_normalized(): void
    {
        $response = $this->getJson('/api/crm/me');

        $response->assertStatus(401);
        $response->assertJsonStructure(['message', 'code', 'request_id']);
        $this->assertSame('auth_error', $response->json('code'));
        $this->assertSame('Unauthenticated.', $response->json('message'));
    }

    public function test_the_401_carries_the_request_id_header_for_correlation(): void
    {
        $response = $this->withHeaders(['Accept' => 'text/html'])->get('/api/crm/me');

        $response->assertStatus(401);
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_no_route_named_login_exists_so_the_fix_cannot_silently_regress(): void
    {
        // If someone later adds a `login` route, the redirect branch stops
        // throwing and this fix looks unnecessary — but the API should still
        // answer 401 rather than redirect. This pins the assumption the fix
        // is built on, so a future change has to think about it.
        $this->assertNull(
            app('router')->getRoutes()->getByName('login'),
            'A route named `login` now exists; revisit Handler::unauthenticated().'
        );
    }
}
