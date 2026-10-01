<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanMarketRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * With the scanner switched on, its tasks must follow the Kernel invariants
 * (background, overlap-guarded, one server), consume only the db_scan queue,
 * and leave the legacy Ads API, payment callbacks and STK routes untouched.
 */
class DbScannerSchedulerIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, Event>
     */
    private function events(bool $scanner): array
    {
        config()->set('queue.default', 'database');
        config()->set('db_scanner.enabled', $scanner);
        $schedule = new Schedule;
        $kernel = new \App\Console\Kernel($this->app, $this->app['events']);
        $method = new \ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        return $schedule->events();
    }

    public function test_scanner_tasks_are_absent_when_the_deployment_switch_is_off(): void
    {
        foreach ($this->events(false) as $event) {
            $this->assertStringNotContainsString('db-scan', (string) $event->command);
            $this->assertStringNotContainsString('--queue=db_scan', (string) $event->command);
        }
    }

    public function test_scanner_tasks_are_backgrounded_and_isolated_on_their_own_queue(): void
    {
        $without = $this->events(false);
        $with = $this->events(true);
        $scanner = array_values(array_filter($with, fn (Event $e) => str_contains((string) $e->command, 'db-scan') || str_contains((string) $e->command, '--queue=db_scan')));

        $this->assertCount(4, $scanner, 'dispatch, recover and two workers');
        foreach ($scanner as $event) {
            $this->assertTrue($event->runInBackground, (string) $event->command);
            $this->assertTrue($event->withoutOverlapping, (string) $event->command);
            $this->assertTrue($event->onOneServer, (string) $event->command);
        }

        $workers = array_filter($scanner, fn (Event $e) => str_contains((string) $e->command, 'queue:work'));
        $this->assertCount(2, $workers);
        foreach ($workers as $worker) {
            $command = (string) $worker->command;
            $this->assertStringContainsString('database_long', $command);
            $this->assertStringContainsString('--queue=db_scan ', $command);
            $this->assertStringContainsString('--timeout=60', $command);
            $this->assertStringContainsString('--max-time=55', $command);
            $this->assertStringContainsString('--max-jobs=1', $command);
            $this->assertStringContainsString('--tries=1', $command);
        }

        // No other task changes when the scanner is switched on.
        $this->assertSame(
            array_map(fn (Event $e) => (string) $e->command, $without),
            array_values(array_map(fn (Event $e) => (string) $e->command, array_filter($with, fn (Event $e) => ! in_array($e, $scanner, true))))
        );
        $this->assertSame(4200, config('queue.connections.database_long.retry_after'));
    }

    public function test_workers_are_not_started_while_no_scanner_run_is_waiting(): void
    {
        $workers = array_filter($this->events(true), fn (Event $e) => str_contains((string) $e->command, '--queue=db_scan'));
        foreach ($workers as $worker) {
            $this->assertFalse($worker->filtersPass($this->app), 'Idle workers are skipped.');
        }

        DbScanMarketRun::query()->create(['pass_id' => 1, 'platform_id' => 1, 'profile' => 'quick', 'status' => 'queued']);
        foreach ($workers as $worker) {
            $this->assertTrue($worker->filtersPass($this->app));
        }
    }

    public function test_legacy_payment_and_stk_routes_are_unchanged(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->mapWithKeys(fn ($r) => [implode('|', $r->methods()).' '.$r->uri() => $r]);

        $callback = $routes['POST api/payment-callback'] ?? null;
        $this->assertNotNull($callback);
        $this->assertStringContainsString('PaymentController@callback', $callback->getActionName());
        $this->assertContains('legacy.payment.callback', $callback->gatherMiddleware());

        foreach (['POST api/initiate-stk-payment', 'POST api/callback', 'POST api/billing/mpesa/callback', 'POST api/payment/update'] as $key) {
            $this->assertArrayHasKey($key, $routes->all(), $key);
            $this->assertStringNotContainsString('DbObservatory', $routes[$key]->getActionName());
        }

        foreach ($routes as $key => $route) {
            if (str_contains($route->getActionName(), 'DbObservatory')) {
                $this->assertStringStartsWith('api/crm/db-observatory/', $route->uri());
                $this->assertContains('crm.session-token', $route->gatherMiddleware());
            }
        }
    }
}
