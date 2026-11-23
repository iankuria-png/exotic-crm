<?php

namespace Tests\Unit;

use App\Services\Collections\CollectionException;
use App\Services\Collections\Kopokopo\KopokopoReceiptParser;
use Tests\TestCase;

class KopokopoParserTest extends TestCase
{
    public function test_invalid_calendar_dates_and_masked_phone_are_not_invented(): void
    {
        $parser = app(KopokopoReceiptParser::class);
        $this->assertNull($parser->phone('2547***001'));
        $this->assertNull($parser->phone('letters254700000001'));
        $this->assertSame('254700000001', $parser->phone('+254 700 000 001'));
        $this->expectException(CollectionException::class);
        $parser->timestamp('2026-02-30T12:00:00+03:00');
    }

    public function test_malformed_status_is_a_typed_rejection(): void
    {
        config(['collection_kopokopo.till' => '268491']);
        $this->expectException(CollectionException::class);
        app(KopokopoReceiptParser::class)->resource(['till_number' => '268491', 'currency' => 'KES', 'reference' => 'UKXFIX0001',
            'id' => 'resource-1', 'status' => ['Received']], KopokopoReceiptParser::RECEIVED);
    }
}
