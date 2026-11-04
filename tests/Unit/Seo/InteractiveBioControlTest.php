<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\Llm\Adapters\DeepSeekAdapter;
use App\Services\Seo\Llm\Adapters\OpenRouterAdapter;
use App\Services\Seo\Llm\BioGenerationRun;
use App\Services\Seo\Llm\ProviderWaterfall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class InteractiveBioControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'services.seo_engine.openrouter.api_key' => 'synthetic-key',
            'services.seo_engine.openrouter.model' => 'deepseek/deepseek-v4.1-flash-1017',
            'services.seo_engine.openrouter.fallback_models' => [],
            'services.seo_engine.openrouter.data_collection' => 'deny',
            'services.seo_engine.deepseek.api_key' => 'synthetic-direct-key',
            'services.seo_engine.deepseek.model' => 'deepseek-flash',
            'services.seo_engine.deepseek.fallback_models' => [],
        ]);
    }

    private function good(): array
    {
        return ['model' => 'actual-model', 'provider' => 'Synthetic', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'A complete synthetic draft.']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 12, 'completion_tokens_details' => ['reasoning_tokens' => 0]]];
    }

    private function attempt(BioGenerationRun $run, array $opts = []): void
    {
        try {
            app(OpenRouterAdapter::class)->generate('private-prompt', 'private-profile', $opts + ['_bio_run' => $run]);
        } catch (\RuntimeException) {
        }
    }

    public function test_reasoning_controls_preserve_privacy_and_report_actual_model(): void
    {
        Http::fake(fn () => Http::response($this->good()));
        $run = new BioGenerationRun;
        app(OpenRouterAdapter::class)->generate('system', 'profile', ['_bio_run' => $run, 'max_tokens' => 384]);
        Http::assertSent(fn ($r) => data_get($r->data(), 'reasoning.enabled') === false
            && data_get($r->data(), 'provider.require_parameters') === true
            && data_get($r->data(), 'provider.data_collection') === 'deny'
            && $r['max_tokens'] === 384);
        $this->assertSame('actual-model', $run->summary()['attempts'][0]['actual_model']);
        $this->assertSame(12, $run->summary()['attempts'][0]['visible_tokens']);
        $this->assertStringNotContainsString('private', json_encode($run->summary()));
    }

    public function test_direct_thinking_is_disabled_only_for_interactive_bios(): void
    {
        $payloads = [];
        Http::fake(function ($r) use (&$payloads) {
            $payloads[] = $r->data();

            return Http::response($this->good());
        });
        app(DeepSeekAdapter::class)->generate('s', 'u', ['_bio_run' => new BioGenerationRun]);
        app(DeepSeekAdapter::class)->generate('s', 'u');
        $this->assertSame('disabled', data_get($payloads[0], 'thinking.type'));
        $this->assertArrayNotHasKey('thinking', $payloads[1]);
    }

    public function test_mistral_omits_unsupported_reasoning_controls(): void
    {
        config(['services.seo_engine.openrouter.model' => 'mistralai/mistral-medium-3.1']);
        Http::fake(fn () => Http::response($this->good()));
        $this->attempt(new BioGenerationRun);
        Http::assertSent(fn ($r) => ! array_key_exists('reasoning', $r->data()));
    }

    public function test_shared_deadline_reduces_second_timeout_and_stops_further_providers(): void
    {
        config(['services.seo_engine.openrouter.fallback_models' => ['mistralai/mistral-medium-3.1', 'qwen/qwen3.8-flash']]);
        $now = 0.0;
        $timeouts = [];
        Http::fake(function ($request, $options) use (&$now, &$timeouts) {
            $timeouts[] = $options['timeout'];
            $now += count($timeouts) === 1 ? 25 : 3;

            return Http::response(['error' => ['message' => 'unavailable']], 503);
        });
        $run = new BioGenerationRun(clock: function () use (&$now) {
            return $now;
        });
        $waterfall = ProviderWaterfall::fromConfig(null, ['openrouter', 'deepseek']);
        try {
            $waterfall->generate('s', 'u', ['_bio_run' => $run]);
        } catch (\RuntimeException) {
        }
        $this->assertSame([12.0, 3.0], $timeouts);
        Http::assertSentCount(2);
        $this->assertFalse($run->canAttempt());
        $this->assertSame(0.0, $run->readTimeout());
    }

    public function test_attempt_cap_is_shared_across_generation_and_rewrite(): void
    {
        Http::fake(fn () => Http::response($this->good()));
        $run = new BioGenerationRun;
        $this->attempt($run);
        $this->assertTrue($run->canRewrite());
        $this->attempt($run);
        $this->assertFalse($run->canRewrite());
        $this->attempt($run);
        Http::assertSentCount(2);
    }

    public function test_exact_interactive_model_choice_does_not_try_hidden_fallbacks(): void
    {
        config(['services.seo_engine.openrouter.fallback_models' => ['mistralai/mistral-medium-3.1']]);
        Http::fake(fn () => Http::response([], 503));
        $this->attempt(new BioGenerationRun, ['model' => 'mistralai/mistral-medium-3.1']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['model'] === 'mistralai/mistral-medium-3.1');
    }

    public function test_circuit_opens_for_repeated_infrastructure_failures_and_expires(): void
    {
        Http::fake(fn () => Http::response([], 503));
        foreach (range(1, 3) as $_) {
            $this->attempt(new BioGenerationRun);
        }
        Http::assertSentCount(2);
        $this->travel(61)->seconds();
        $this->attempt(new BioGenerationRun);
        Http::assertSentCount(3);
    }

    public function test_success_resets_failures_and_routing_and_credentials_are_isolated(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 503)->push($this->good())->push([], 503)->push([], 503)->push($this->good())->push($this->good())]);
        foreach (range(1, 4) as $_) {
            $this->attempt(new BioGenerationRun);
        }
        $this->attempt(new BioGenerationRun); // open after the last two infrastructure failures
        Http::assertSentCount(4);
        $this->attempt(new BioGenerationRun, ['provider_sort' => 'latency']);
        config(['services.seo_engine.openrouter.api_key' => 'different-key']);
        $this->attempt(new BioGenerationRun);
        Http::assertSentCount(6);
    }

    public function test_empty_refused_truncated_and_programming_failures_do_not_poison_shared_circuit(): void
    {
        $case = 'empty';
        $count = 0;
        Http::fake(function () use (&$case, &$count) {
            $count++;
            if ($case === 'programming') {
                throw new \LogicException('secret from private-prompt');
            }
            $data = $this->good();
            if ($case === 'empty') {
                $data['choices'][0]['message']['content'] = '';
            }
            if ($case === 'refused') {
                $data['choices'][0]['message']['refusal'] = 'private-profile';
            }
            if ($case === 'truncated') {
                $data['choices'][0]['finish_reason'] = 'length';
            }

            return Http::response($data);
        });
        foreach (['empty', 'refused', 'truncated', 'programming'] as $case) {
            Cache::flush();
            $count = 0;
            foreach (range(1, 3) as $_) {
                $this->attempt(new BioGenerationRun);
            }
            $this->assertSame(3, $count, $case);
        }
    }

    public function test_scoped_errors_and_timings_never_log_vendor_bodies_or_credentials(): void
    {
        $logs = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($name, $context) use (&$logs) {
            $logs[] = [$name, $context];
        });
        Http::fake(fn () => Http::response(['error' => ['message' => 'secret-credential private-prompt private-profile']], 500));
        $run = new BioGenerationRun;
        $this->attempt($run);
        $encoded = json_encode([$logs, $run->summary()]);
        foreach (['secret-credential', 'private-prompt', 'private-profile', 'synthetic-key'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_transport_failures_are_classified_without_exposing_exception_text(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: private-prompt secret-credential'));
        $run = new BioGenerationRun;
        $this->attempt($run);
        $this->assertSame('timeout', $run->summary()['attempts'][0]['reason']);
        $this->assertStringNotContainsString('secret-credential', json_encode($run->summary()));
    }

    public function test_final_summary_is_once_and_unavailable_logging_fails_open(): void
    {
        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('seo.bio_performance', \Mockery::on(fn ($s) => $s['status'] === 'failed' && $s['failed_stage'] === 'snapshot'));
        Log::shouldReceive('channel')->once()->with('seo_performance')->andReturn($logger);
        $run = new BioGenerationRun;
        try {
            $run->stage('snapshot', fn () => throw new \RuntimeException('private'));
        } catch (\RuntimeException) {
        }
        $run->finish('failed');
        $run->finish('failed');
        Log::shouldReceive('channel')->once()->andThrow(new \RuntimeException('unwritable'));
        (new BioGenerationRun)->finish('success');
        $this->assertTrue(true);
    }
}
