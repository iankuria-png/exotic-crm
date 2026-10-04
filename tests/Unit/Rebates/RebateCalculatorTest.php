<?php

namespace Tests\Unit\Rebates;

use App\Services\Rebates\RebateCalculator;
use App\Services\Rebates\RebateProgramService;
use PHPUnit\Framework\TestCase;

class RebateCalculatorTest extends TestCase
{
    public function test_shared_quotes(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/../../Fixtures/rebates/quotes.json'), true);
        foreach ($fixtures as $f) {
            $rules = (new RebateProgramService)->defaults();
            foreach ($f['overrides'] as $key => $value) {
                data_set($rules, $key, $value);
            }
            $quote = (new RebateCalculator)->quote($rules, $f['input']);
            $this->assertEquals($f['total'], $quote['total'], $f['name']);
            $this->assertSame($f['reason'], $quote['reason'], $f['name']);
            $this->assertEquals($quote['total'], array_sum(array_column($quote['lines'], 'amount')), $f['name']);
        }
    }
}
