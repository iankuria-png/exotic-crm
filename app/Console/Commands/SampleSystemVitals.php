<?php

namespace App\Console\Commands;

use App\Services\Ops\DegradationEvaluator;
use App\Services\Ops\VitalsSampler;
use Illuminate\Console\Command;

/**
 * The single guaranteed writer in the observability stack.
 *
 * One sample a minute is the whole budget. Pulse's per-request recorders are
 * sampled right down in config/pulse.php precisely so that this command, and
 * not HTTP traffic, is what fills the metric tables — an observability stack
 * that becomes the load it was built to detect is worse than none.
 */
class SampleSystemVitals extends Command
{
    protected $signature = 'crm:sample-vitals {--json : Print the sample as JSON}';

    protected $description = 'Sample platform vitals into Pulse and evaluate the degradation level';

    public function handle(VitalsSampler $sampler, DegradationEvaluator $evaluator): int
    {
        $sample = $sampler->sample();
        $state = $evaluator->evaluate($sample);

        if ($this->option('json')) {
            $this->line((string) json_encode(['sample' => $sample, 'state' => $state], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[%s] level=%d (%s)%s enforcement=%s',
            $sample['sampled_at'],
            $state['level'],
            $state['level_label'],
            $state['forced'] ? ' forced' : '',
            $state['enforcement'] ? 'on' : 'observe-only'
        ));

        $this->table(
            ['Signal', 'Value', 'Watch', 'Shed', 'State'],
            array_map(fn (array $signal): array => [
                $signal['label'],
                $signal['available'] ? $signal['value'].' '.$signal['unit'] : 'Unavailable',
                $signal['watch'],
                $signal['shed'],
                $signal['state'],
            ], $sample['signals'])
        );

        return self::SUCCESS;
    }
}
