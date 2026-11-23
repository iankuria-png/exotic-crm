<?php

namespace App\Services\Collections\Kopokopo;

use App\Models\Collections\CollectionProviderFeed;
use App\Services\Collections\CollectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** New receiving transport. Never changes STK settings or follows provider-supplied arbitrary URLs. */
class KopokopoCollectionClient
{
    public function request(CollectionProviderFeed $feed, string $method, string $path, array $payload = []): Response
    {
        if (! config('collection_kopokopo.enabled') || ! config('collection.capture_enabled') || ! $feed->enabled) {
            throw new CollectionException('provider_capture_disabled', 404);
        }
        $config = app(KopokopoFeedService::class)->configuration($feed);
        try {
            $token = Http::asForm()->acceptJson()->withoutRedirecting()->timeout(12)->post('https://api.kopokopo.com/oauth/token', [
                'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'], 'grant_type' => 'client_credentials']);
            if (! $token->successful() || ! is_string($token->json('access_token')) || $token->json('access_token') === '') {
                throw new CollectionException('provider_authorization_failed', 502);
            }
            $request = Http::withToken($token->json('access_token'))->acceptJson()->asJson()->withoutRedirecting()->timeout(12);

            return $method === 'GET' ? $request->get('https://api.kopokopo.com'.$path) : $request->post('https://api.kopokopo.com'.$path, $payload);
        } catch (CollectionException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new CollectionException('provider_request_result_unknown', 502);
        }
    }

    public function location(string $url, string $kind): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'api.kopokopo.com'
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($kind, ['polling', 'webhook_subscriptions'], true)
            || ! preg_match('#^/api/v2/'.$kind.'/([A-Za-z0-9][A-Za-z0-9_-]{1,127})$#D', $parts['path'] ?? '', $m)) {
            throw new CollectionException('provider_location_requires_review', 409);
        }

        return ['path' => $parts['path'], 'id' => $m[1], 'url' => $url];
    }
}
