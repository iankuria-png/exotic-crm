<?php

namespace Tests\Feature;

use App\Models\ErrorLogGroup;
use App\Models\ErrorLogOccurrence;
use App\Services\ErrorLogRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ErrorLogRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_marked_exception_is_recorded_once_by_the_log_path(): void
    {
        $recorder = app(ErrorLogRecorder::class);
        $this->assertSame($recorder, app(ErrorLogRecorder::class));

        $exception = new RuntimeException('The CRM error was recorded once.');
        $recorder->markException($exception);

        logger()->error($exception->getMessage(), ['exception' => $exception]);
        $recorder->flushMarkedExceptions();

        $group = ErrorLogGroup::query()->sole();
        $this->assertSame('exception', $group->source);
        $this->assertSame(1, $group->occurrence_count);
        $this->assertSame(1, ErrorLogOccurrence::query()->count());
    }

    public function test_repeated_records_atomically_increment_the_existing_group(): void
    {
        $recorder = app(ErrorLogRecorder::class);
        $exception = new RuntimeException('The CRM error counter increments.');

        $recorder->recordException($exception);
        $recorder->recordException($exception);

        $group = ErrorLogGroup::query()->sole();
        $this->assertSame(2, $group->occurrence_count);
        $this->assertSame(2, ErrorLogOccurrence::query()->count());
    }

    public function test_nightly_prune_keeps_the_latest_twenty_occurrences_per_group(): void
    {
        $recorder = app(ErrorLogRecorder::class);
        $exception = new RuntimeException('The CRM occurrence cap is enforced overnight.');

        foreach (range(1, 21) as $_) {
            $recorder->recordException($exception);
        }

        $this->artisan('crm:prune-error-logs')->assertExitCode(0);

        $this->assertSame(20, ErrorLogOccurrence::query()->count());
    }
}
