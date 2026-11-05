<?php

namespace App\Services\SubscriptionAudit;

use App\Models\SubscriptionAuditRun;
use App\Services\Mcp\McpAuthorizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubscriptionAuditService
{
    public function report(string $kind, array $arguments, McpAuthorizationContext $auth): array
    {
        if ($auth->user->role !== 'admin' || ! $auth->user->isActive() || ! $auth->allows('mcp:subscription-audit')) {
            throw new SubscriptionAuditException('An active administrator audit grant is required.', 'audit_forbidden', 403, -32011);
        }
        if (! in_array($kind, ['summary', 'events', 'trace', 'coverage'], true)) {
            throw new SubscriptionAuditException('Unknown subscription audit report.');
        }
        $purpose = trim((string) ($arguments['purpose'] ?? ''));
        if (mb_strlen($purpose) < 3 || mb_strlen($purpose) > 160) {
            throw new SubscriptionAuditException('Provide an audit purpose between 3 and 160 characters.');
        }
        if (! Schema::hasTable('subscription_audit_runs') || ! Schema::hasTable('subscription_audit_events')) {
            throw new SubscriptionAuditException('Subscription audit reporting migrations are unavailable.', 'audit_unavailable', 503, -32020);
        }
        $cursor = isset($arguments['cursor']) && $arguments['cursor'] !== '' ? $this->decodeCursor((string) $arguments['cursor']) : null;
        $runQuery = SubscriptionAuditRun::query()->where('status', 'completed');
        if (! empty($arguments['run_ref'])) {
            $runQuery->where('run_ref', $arguments['run_ref']);
        } elseif ($cursor) {
            $runQuery->whereKey($cursor['r']);
        }
        $run = $runQuery->latest('id')->first();
        if (! $run) {
            throw new SubscriptionAuditException('No completed subscription audit snapshot is available. Build the reporting snapshot first.', 'audit_unavailable', 503, -32020);
        }
        $filters = $this->filters($arguments, $run, $auth);
        $query = $this->query($run, $filters, $auth);
        $total = (clone $query)->count();
        $coverage = $this->coverage($run, $filters, $auth);
        $caveats = $run->coverage['caveats'] ?? [];
        if ($run->as_of->lt(CarbonImmutable::now('UTC')->subHours(2))) {
            $caveats[] = 'The completed reporting snapshot is older than two hours; refresh is pending.';
        }
        $data = ['run_ref' => $run->run_ref, 'rule_version' => $run->rule_version, 'as_of' => $run->as_of->toIso8601String(),
            'window' => ['from' => $filters['from'], 'to_exclusive' => $filters['to'], 'timezone' => 'UTC'],
            'total_matching' => $total];
        $returned = 0;
        if ($kind === 'summary') {
            $data += $this->summarise($query);
            $returned = $total;
        } elseif ($kind === 'coverage') {
            $data['coverage'] = $coverage;
            $data['source_counts'] = $auth->platformIds === null && $filters['platform_id'] === null && $filters['country'] === null ? ($run->coverage['source_counts'] ?? []) : [];
            $returned = count($coverage);
        } elseif ($kind === 'trace') {
            $ref = (string) ($arguments['event_ref'] ?? '');
            if (! preg_match('/^evt_[a-f0-9]{64}$/D', $ref)) {
                throw new SubscriptionAuditException('Provide a valid opaque event reference.');
            }
            $event = (clone $query)->where('event_ref', $ref)->first(['payload']);
            if (! $event) {
                throw new SubscriptionAuditException('The audit event is unavailable in this authorised report scope.', 'audit_event_not_found', 404, -32004);
            }
            $data['event'] = AuditValue::json($event->payload);
            $returned = 1;
        } else {
            $pageSize = filter_var($arguments['page_size'] ?? 50, FILTER_VALIDATE_INT);
            if ($pageSize === false || $pageSize < 1 || $pageSize > 100) {
                throw new SubscriptionAuditException('Page size must be between 1 and 100.');
            }
            $digest = $this->cursorDigest($run, $filters, $auth);
            if ($cursor && ((int) $cursor['r'] !== (int) $run->id || ! hash_equals($digest, (string) $cursor['h']))) {
                throw new SubscriptionAuditException('The cursor does not match the current report filters or authorisation scope.', 'audit_cursor_mismatch');
            }
            $page = (clone $query)->when($cursor, fn (Builder $q) => $q->where('id', '>', (int) $cursor['i']))->orderBy('id')->limit($pageSize + 1)->get(['id', 'payload']);
            $hasMore = $page->count() > $pageSize;
            $page = $page->take($pageSize);
            $data['rows'] = $page->map(fn ($row) => AuditValue::json($row->payload))->values()->all();
            $data['returned_count'] = $page->count();
            $data['next_cursor'] = $hasMore ? $this->encodeCursor(['r' => (int) $run->id, 'i' => (int) $page->last()->id, 'h' => $digest, 'e' => time() + 900]) : null;
            $returned = $page->count();
        }
        DB::table('audit_log')->insert(['platform_id' => $filters['platform_id'], 'actor_id' => $auth->user->id,
            'action' => 'subscription_audit_report_read', 'entity_type' => 'subscription_audit_run', 'entity_id' => $run->id,
            'before_state' => null, 'after_state' => json_encode(['report' => $kind, 'run_ref' => $run->run_ref, 'filters' => $filters, 'returned_count' => $returned], JSON_THROW_ON_ERROR),
            'reason' => $purpose, 'ip_address' => null, 'created_at' => now('UTC')]);
        $data['_report_meta'] = ['result_state' => 'partial', 'filters' => $filters + ['purpose_recorded' => true], 'coverage' => $coverage,
            'row_count' => $returned, 'caveats' => $caveats, 'citations' => [['source' => 'retained_crm_audit_snapshot', 'reference' => $run->run_ref, 'as_of' => $run->as_of->toIso8601String()]]];

        return $data;
    }

    private function filters(array $args, SubscriptionAuditRun $run, McpAuthorizationContext $auth): array
    {
        $from = $this->date($args['from'] ?? $run->from_at->toIso8601String());
        $to = $this->date($args['to'] ?? $run->to_at->toIso8601String());
        if ($from >= $to || $from->diffInSeconds($to) > 90 * 86400 || $from < $run->from_at || $to > $run->to_at) {
            throw new SubscriptionAuditException('The requested UTC period must fit the completed snapshot and be no longer than 90 days.');
        }
        $platform = isset($args['platform_id']) ? filter_var($args['platform_id'], FILTER_VALIDATE_INT) : null;
        if ($platform !== null && ($platform === false || $platform < 1)) {
            throw new SubscriptionAuditException('Provide a valid website platform identifier.');
        }
        if ($platform !== null && $auth->platformIds !== null && ! in_array($platform, $auth->platformIds, true)) {
            throw new SubscriptionAuditException('The requested website is outside the current grant scope.', 'audit_forbidden', 403, -32011);
        }
        $population = (string) ($args['population'] ?? 'live');
        if (! in_array($population, ['live', 'test', 'all'], true)) {
            throw new SubscriptionAuditException('Population must be live, test or all.');
        }
        $type = $args['event_type'] ?? null;
        if ($type !== null && ! in_array($type, ['activation', 'renewal', 'extension', 'observation'], true)) {
            throw new SubscriptionAuditException('Unknown subscription event type.');
        }
        $staff = $args['staff_handle'] ?? null;
        if ($staff !== null && ! preg_match('/^staff_[a-f0-9]{32}$/D', (string) $staff)) {
            throw new SubscriptionAuditException('Provide a valid opaque staff handle.');
        }
        $country = isset($args['country']) ? trim((string) $args['country']) : null;
        if ($country !== null && ($country === '' || mb_strlen($country) > 80)) {
            throw new SubscriptionAuditException('Use an exact country label of at most 80 characters.');
        }
        $exceptions = $args['exceptions_only'] ?? false;
        if (! is_bool($exceptions)) {
            throw new SubscriptionAuditException('exceptions_only must be a boolean.');
        }

        return ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString(), 'platform_id' => $platform,
            'country' => $country, 'population' => $population, 'event_type' => $type, 'staff_handle' => $staff, 'exceptions_only' => $exceptions];
    }

    private function query(SubscriptionAuditRun $run, array $filters, McpAuthorizationContext $auth): Builder
    {
        return DB::table('subscription_audit_events')->where('run_id', $run->id)
            ->when($auth->platformIds !== null, fn (Builder $q) => $q->whereIn('platform_id', $auth->platformIds))
            ->where('occurred_at', '>=', $filters['from'])->where('occurred_at', '<', $filters['to'])
            ->when($filters['platform_id'] !== null, fn (Builder $q) => $q->where('platform_id', $filters['platform_id']))
            ->when($filters['country'] !== null, fn (Builder $q) => $q->where('country', $filters['country']))
            ->when($filters['population'] !== 'all', fn (Builder $q) => $q->where('population', $filters['population']))
            ->when($filters['event_type'] !== null, fn (Builder $q) => $q->where('event_type', $filters['event_type']))
            ->when($filters['staff_handle'] !== null, fn (Builder $q) => $q->where('staff_handle', $filters['staff_handle']))
            ->when($filters['exceptions_only'], fn (Builder $q) => $q->where('exception', true));
    }

    private function summarise(Builder $query): array
    {
        $totals = ['event_count' => 0, 'exception_count' => 0, 'unpriced_count' => 0, 'by_currency' => []];
        $sites = $countries = $staff = [];
        foreach ((clone $query)->orderBy('id')->cursor(['platform_id', 'country', 'staff_handle', 'exception', 'payload']) as $row) {
            $payload = AuditValue::json($row->payload);
            $this->addSummary($totals, $payload, (bool) $row->exception);
            $site = (string) $row->platform_id;
            $country = $row->country ?? 'Unknown';
            $actor = $row->staff_handle ?? 'not_recorded';
            foreach ([['target' => &$sites, 'key' => $site, 'label' => ['platform_id' => (int) $row->platform_id, 'market_label' => $payload['market_label']]], ['target' => &$countries, 'key' => $country, 'label' => ['country' => $country]], ['target' => &$staff, 'key' => $actor, 'label' => ['staff_handle' => $row->staff_handle, 'agent_alias' => $payload['actor']['agent_alias'] ?? null]]] as &$group) {
                if (! isset($group['target'][$group['key']])) {
                    $group['target'][$group['key']] = $group['label'] + ['event_count' => 0, 'exception_count' => 0, 'unpriced_count' => 0, 'by_currency' => []];
                }
                $this->addSummary($group['target'][$group['key']], $payload, (bool) $row->exception);
            }
            unset($group);
        }
        $this->formatSummary($totals);
        foreach ([&$sites, &$countries, &$staff] as &$groups) {
            foreach ($groups as &$group) {
                $this->formatSummary($group);
            }
        }

        return ['summary' => $totals, 'by_website' => array_values($sites), 'by_country' => array_values($countries), 'by_staff' => array_values($staff)];
    }

    private function addSummary(array &$summary, array $row, bool $exception): void
    {
        $summary['event_count']++;
        $summary['exception_count'] += (int) $exception;
        $exposure = AuditValue::minor($row['potential_exposure'] ?? null);
        if ($exposure === null) {
            $summary['unpriced_count']++;
        }
        $currency = $row['currency'] ?? 'UNKNOWN';
        $summary['by_currency'][$currency] ??= ['currency' => $currency, 'potential_exposure_minor' => 0, 'priced_count' => 0, 'unpriced_count' => 0];
        if ($exposure === null) {
            $summary['by_currency'][$currency]['unpriced_count']++;
        } else {
            if ($summary['by_currency'][$currency]['potential_exposure_minor'] > PHP_INT_MAX - $exposure) {
                throw new SubscriptionAuditException('The exact monetary total exceeds the supported integer range.', 'audit_amount_overflow', 422, -32021);
            }
            $summary['by_currency'][$currency]['potential_exposure_minor'] += $exposure;
            $summary['by_currency'][$currency]['priced_count']++;
        }
    }

    private function formatSummary(array &$summary): void
    {
        foreach ($summary['by_currency'] as &$currency) {
            $currency['potential_exposure'] = AuditValue::decimal($currency['potential_exposure_minor']);
            unset($currency['potential_exposure_minor']);
        }
        $summary['by_currency'] = array_values($summary['by_currency']);
    }

    private function coverage(SubscriptionAuditRun $run, array $filters, McpAuthorizationContext $auth): array
    {
        $sites = array_values(array_filter($run->coverage['platforms'] ?? [], fn (array $site) => ($auth->platformIds === null || in_array((int) $site['platform_id'], $auth->platformIds, true))
            && ($filters['platform_id'] === null || (int) $site['platform_id'] === $filters['platform_id'])
            && ($filters['country'] === null || ($site['country'] ?? null) === $filters['country'])));

        return array_map(fn (array $site) => $site + ['count_basis' => 'entire_snapshot_all_populations', 'snapshot_from' => $run->from_at->toIso8601String(), 'snapshot_to_exclusive' => $run->to_at->toIso8601String()], $sites);
    }

    private function date(mixed $date): CarbonImmutable
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:Z|\+00:00)?)?$/D', $date)) {
            throw new SubscriptionAuditException('Use an ISO date or UTC timestamp; the to boundary is exclusive.');
        }
        try {
            $parsed = CarbonImmutable::parse($date, 'UTC')->utc();
            if ($parsed->format('Y-m-d') !== substr($date, 0, 10)) {
                throw new \RuntimeException('Invalid date');
            }

            return $parsed;
        } catch (\Throwable) {
            throw new SubscriptionAuditException('Use a valid UTC reporting date.');
        }
    }

    private function cursorDigest(SubscriptionAuditRun $run, array $filters, McpAuthorizationContext $auth): string
    {
        $scope = $auth->platformIds;
        if (is_array($scope)) {
            sort($scope);
        }

        return hash('sha256', json_encode([$run->run_ref, $filters, $auth->user->id, $auth->user->role, $scope], JSON_THROW_ON_ERROR));
    }

    private function encodeCursor(array $payload): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $body.'.'.hash_hmac('sha256', $body, (string) config('app.key'));
    }

    private function decodeCursor(string $cursor): array
    {
        if (strlen($cursor) > 450 || ! preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $m)
            || ! hash_equals(hash_hmac('sha256', $m[1], (string) config('app.key')), $m[2])) {
            throw new SubscriptionAuditException('Invalid or modified audit cursor.', 'audit_cursor_invalid');
        }
        $payload = json_decode((string) base64_decode(strtr($m[1], '-_', '+/'), true), true);
        if (! is_array($payload) || ! isset($payload['r'], $payload['i'], $payload['h'], $payload['e']) || (int) $payload['e'] < time()) {
            throw new SubscriptionAuditException('The audit cursor has expired or is invalid.', 'audit_cursor_expired');
        }

        return $payload;
    }
}
