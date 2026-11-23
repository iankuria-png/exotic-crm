<?php

namespace App\Services\Scraper\Fetch;

use Illuminate\Contracts\Container\Container;

class FetchDriverRegistry
{
    public const KEYS = ['http', 'capture', 'render_worker'];

    public function __construct(private readonly Container $container)
    {
    }

    public function get(string $key): FetchDriver
    {
        return match ($key) {
            'capture' => $this->container->make(CaptureFetchDriver::class),
            'render_worker' => $this->renderWorker() ?? new UnavailableFetchDriver('render_worker', 'The browser render worker is not configured yet.'),
            default => $this->container->make(HttpFetchDriver::class),
        };
    }

    /** @return array<string, array{key: string, available: bool, label: string}> */
    public function describe(): array
    {
        $render = $this->renderWorker();

        return [
            'http' => ['key' => 'http', 'available' => true, 'label' => 'Direct (fast, no JavaScript)'],
            'capture' => ['key' => 'capture', 'available' => true, 'label' => 'Browser capture (pages you send from your browser)'],
            'render_worker' => ['key' => 'render_worker', 'available' => $render !== null && $render->isAvailable(), 'label' => 'Render worker (JavaScript pages)'],
        ];
    }

    public function renderWorkerAvailable(): bool
    {
        $render = $this->renderWorker();

        return $render !== null && $render->isAvailable();
    }

    private function renderWorker(): ?FetchDriver
    {
        $class = __NAMESPACE__ . '\\RenderWorkerDriver';
        if (!class_exists($class)) {
            return null;
        }
        $driver = $this->container->make($class);

        return $driver instanceof FetchDriver ? $driver : null;
    }
}
