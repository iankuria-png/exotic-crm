<?php

namespace App\Services\Scraper\Fetch;

final class UnavailableFetchDriver implements FetchDriver
{
    public function __construct(private readonly string $driverKey, private readonly string $message)
    {
    }

    public function key(): string
    {
        return $this->driverKey;
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function fetch(FetchRequest $request): FetchResult
    {
        return FetchResult::failure($request->url, $this->driverKey, $this->message);
    }
}
