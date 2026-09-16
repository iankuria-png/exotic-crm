<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Knowledge\OntologyRegistry;

class ResourceRegistry
{
    public const REVENUE_DASHBOARD_URI = 'ui://exotic/revenue-dashboard/v1.html';

    public const MCP_APP_MIME_TYPE = 'text/html;profile=mcp-app';

    private const MODERN = [
        'exotic://ontology/entity-map' => ['title' => 'CRM entity map', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing']],
        'exotic://ontology/metric-definitions' => ['title' => 'Metric definitions', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing']],
        'exotic://runbook/payment-activation' => ['title' => 'Payment activation runbook', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales']],
    ];

    private const UI = [
        self::REVENUE_DASHBOARD_URI => [
            'title' => 'Revenue dashboard',
            'path' => 'mcp/apps/revenue-dashboard-v1.html',
            'roles' => ['admin', 'sub_admin'],
            'connect_domains' => [],
            'resource_domains' => [],
        ],
    ];

    public function list(McpAuthorizationContext $auth, McpSettingsService $settings, bool $modern): array
    {
        $bootstrap = collect((array) data_get($settings->settings(), 'resources', []))
            ->filter(fn ($resource) => (bool) ($resource['enabled'] ?? false))
            ->map(fn ($resource, $uri) => ['uri' => $uri, 'name' => basename((string) $uri), 'mimeType' => 'text/markdown'])
            ->values()->all();
        if (! $modern || ! config('mcp.waves.knowledge')) {
            return $modern ? array_merge($bootstrap, $this->uiResources($auth)) : $bootstrap;
        }
        // mcp:resource:* is explicit; no wildcard is silently inferred.
        $extra = collect(self::MODERN)->filter(fn ($meta, $uri) => in_array($auth->user->role, $meta['roles'], true) && $auth->allows('mcp:resource:'.$uri))
            ->map(fn ($meta, $uri) => ['uri' => $uri, 'name' => $meta['title'], 'mimeType' => 'text/markdown', 'annotations' => ['audience' => $meta['roles']]])->values()->all();

        return array_merge($bootstrap, $this->uiResources($auth), $extra);
    }

    public function read(string $uri, McpAuthorizationContext $auth, McpSettingsService $settings, bool $modern): string
    {
        return (string) ($this->readContent($uri, $auth, $settings, $modern)['text'] ?? '');
    }

    public function readContent(string $uri, McpAuthorizationContext $auth, McpSettingsService $settings, bool $modern): array
    {
        $resources = (array) data_get($settings->settings(), 'resources', []);
        if (isset($resources[$uri]) && (bool) ($resources[$uri]['enabled'] ?? false)) {
            $path = resource_path((string) $resources[$uri]['path']);
            if (! is_file($path)) {
                throw McpProtocolException::rpc(-32001, 'Resource not found.', 'resource_not_found', 404);
            }

            return [
                'uri' => $uri,
                'mimeType' => (string) ($resources[$uri]['mimeType'] ?? 'text/markdown'),
                'text' => (string) file_get_contents($path),
            ];
        }
        if ($modern && isset(self::UI[$uri]) && $this->canReadUi($uri, $auth)) {
            $meta = self::UI[$uri];
            $path = resource_path((string) $meta['path']);
            if (! is_file($path)) {
                throw McpProtocolException::rpc(-32001, 'Resource not found.', 'resource_not_found', 404);
            }

            return [
                'uri' => $uri,
                'mimeType' => self::MCP_APP_MIME_TYPE,
                'text' => (string) file_get_contents($path),
                '_meta' => [
                    'ui' => [
                        'prefersBorder' => true,
                        'csp' => [
                            'connectDomains' => $meta['connect_domains'],
                            'resourceDomains' => $meta['resource_domains'],
                        ],
                    ],
                    'openai/widgetDescription' => 'Read-only revenue dashboard for CRM revenue, trend and market mix.',
                    'openai/widgetPrefersBorder' => true,
                    'openai/widgetCSP' => [
                        'connect_domains' => $meta['connect_domains'],
                        'resource_domains' => $meta['resource_domains'],
                    ],
                ],
            ];
        }
        if (! $modern || ! config('mcp.waves.knowledge') || ! isset(self::MODERN[$uri]) || ! in_array($auth->user->role, self::MODERN[$uri]['roles'], true) || ! $auth->allows('mcp:resource:'.$uri)) {
            throw McpProtocolException::rpc(-32001, 'Resource not found.', 'resource_not_found', 404);
        }
        $ontology = app(OntologyRegistry::class)->active();

        return [
            'uri' => $uri,
            'mimeType' => 'text/markdown',
            'text' => match ($uri) {
                'exotic://ontology/entity-map' => json_encode(['version' => $ontology['version'], 'entities' => $ontology['ontology']['entities']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                'exotic://ontology/metric-definitions' => json_encode(['version' => $ontology['version'], 'metrics' => $ontology['ontology']['metrics']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                'exotic://runbook/payment-activation' => (string) file_get_contents(resource_path('mcp/runbooks/payment-activation.md')),
            },
        ];
    }

    private function uiResources(McpAuthorizationContext $auth): array
    {
        return collect(self::UI)
            ->filter(fn (array $meta, string $uri) => $this->canReadUi($uri, $auth))
            ->map(fn (array $meta, string $uri) => [
                'uri' => $uri,
                'name' => $meta['title'],
                'mimeType' => self::MCP_APP_MIME_TYPE,
                'annotations' => ['audience' => $meta['roles']],
            ])
            ->values()
            ->all();
    }

    private function canReadUi(string $uri, McpAuthorizationContext $auth): bool
    {
        return isset(self::UI[$uri]) && in_array($auth->user->role, self::UI[$uri]['roles'], true);
    }
}
