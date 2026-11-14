<?php

namespace App\Services\Mcp;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/** A report interval always has an inclusive start and an exclusive end. */
final class RevenueReportingWindow
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $timezone,
    ) {}

    public static function explicit(array $arguments): bool
    {
        return array_key_exists('from', $arguments) || array_key_exists('to', $arguments);
    }

    public static function fromArguments(array $arguments): self
    {
        $timezone = (string) ($arguments['timezone'] ?? 'Africa/Nairobi');
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw McpProtocolException::rpc(-32602, 'Invalid reporting timezone.', 'invalid_params', 422);
        }
        if (self::explicit($arguments)) {
            if (empty($arguments['from']) || empty($arguments['to'])) {
                throw McpProtocolException::rpc(-32602, 'Both from and to are required.', 'invalid_params', 422);
            }
            $from = self::parse((string) $arguments['from'], $timezone, false);
            $to = self::parse((string) $arguments['to'], $timezone, true);
        } else {
            $window = (string) ($arguments['window'] ?? '30d');
            $now = CarbonImmutable::now($timezone);
            $from = match ($window) {
                'today', 'day' => $now->startOfDay(),
                '7d' => $now->subDays(6)->startOfDay(),
                '30d' => $now->subDays(29)->startOfDay(),
                '90d' => $now->subDays(89)->startOfDay(),
                'ytd' => $now->startOfYear(),
                default => throw McpProtocolException::rpc(-32602, 'Unknown window; provide explicit from and to for custom reporting.', 'invalid_params', 422),
            };
            $to = $now;
        }
        if ($from->greaterThanOrEqualTo($to)) {
            throw McpProtocolException::rpc(-32602, 'Reporting interval must be positive.', 'invalid_params', 422);
        }

        return new self($from->utc(), $to->utc(), $timezone);
    }

    private static function parse(string $value, string $timezone, bool $end): CarbonImmutable
    {
        $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1;
        if (! $dateOnly && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw McpProtocolException::rpc(-32602, 'Use an ISO date or timestamp with an explicit timezone offset.', 'invalid_params', 422);
        }
        try {
            $date = CarbonImmutable::parse($value, $timezone);
            if ($date->format('Y-m-d') !== substr($value, 0, 10)) {
                throw new \InvalidArgumentException;
            }
        } catch (Throwable) {
            throw McpProtocolException::rpc(-32602, 'Invalid reporting date.', 'invalid_params', 422);
        }

        return $dateOnly ? ($end ? $date->startOfDay()->addDay() : $date->startOfDay()) : $date;
    }

    public function serialize(): array
    {
        return [
            'from' => $this->from->toIso8601String(), 'to' => $this->to->toIso8601String(),
            'from_local' => $this->from->setTimezone($this->timezone)->toIso8601String(),
            'to_local' => $this->to->setTimezone($this->timezone)->toIso8601String(),
            'timezone' => $this->timezone, 'boundaries' => '[from,to)',
            'date_only_to' => 'inclusive calendar date expanded to following local midnight',
        ];
    }
}
