<?php

namespace App\Services\Scraper\Fetch;

interface FetchDriver
{
    /** 'http' | 'capture' | 'render_worker' */
    public function key(): string;

    public function isAvailable(): bool;

    public function fetch(FetchRequest $request): FetchResult;
}
