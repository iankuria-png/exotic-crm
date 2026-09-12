<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Knowledge\OntologyRegistry;

class ResourceRegistry
{
    private const MODERN = [
        'exotic://ontology/entity-map' => ['title' => 'CRM entity map', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing']],
        'exotic://ontology/metric-definitions' => ['title' => 'Metric definitions', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing']],
        'exotic://runbook/payment-activation' => ['title' => 'Payment activation runbook', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales']],
    ];

    public function list(McpAuthorizationContext $auth, McpSettingsService $settings, bool $modern): array
    {
        $bootstrap = collect((array) data_get($settings->settings(), 'resources', []))
            ->filter(fn ($resource) => (bool) ($resource['enabled'] ?? false))
            ->map(fn ($resource, $uri) => ['uri' => $uri, 'name' => basename((string) $uri), 'mimeType' => 'text/markdown'])
            ->values()->all();
        if (! $modern || ! config('mcp.waves.knowledge')) {
            return $bootstrap;
        }
        // mcp:resource:* is explicit; no wildcard is silently inferred.
        $extra = collect(self::MODERN)->filter(fn ($meta, $uri) => in_array($auth->user->role, $meta['roles'], true) && $auth->allows('mcp:resource:'.$uri))
            ->map(fn ($meta, $uri) => ['uri' => $uri, 'name' => $meta['title'], 'mimeType' => 'text/markdown', 'annotations' => ['audience' => $meta['roles']]])->values()->all();

        return array_merge($bootstrap, $extra);
    }

    public function read(string $uri, McpAuthorizationContext $auth, McpSettingsService $settings, bool $modern): string
    {
        $resources = (array) data_get($settings->settings(), 'resources', []);
        if (isset($resources[$uri]) && (bool) ($resources[$uri]['enabled'] ?? false)) {
            $path = resource_path((string) $resources[$uri]['path']);
            if (! is_file($path)) {
                throw McpProtocolException::rpc(-32001, 'Resource not found.', 'resource_not_found', 404);
            }

            return (string) file_get_contents($path);
        }
        if (! $modern || ! config('mcp.waves.knowledge') || ! isset(self::MODERN[$uri]) || ! in_array($auth->user->role, self::MODERN[$uri]['roles'], true) || ! $auth->allows('mcp:resource:'.$uri)) {
            throw McpProtocolException::rpc(-32001, 'Resource not found.', 'resource_not_found', 404);
        }
        $ontology = app(OntologyRegistry::class)->active();

        return match ($uri) {
            'exotic://ontology/entity-map' => json_encode(['version' => $ontology['version'], 'entities' => $ontology['ontology']['entities']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'exotic://ontology/metric-definitions' => json_encode(['version' => $ontology['version'], 'metrics' => $ontology['ontology']['metrics']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'exotic://runbook/payment-activation' => (string) file_get_contents(resource_path('mcp/runbooks/payment-activation.md')),
        };
    }
}
