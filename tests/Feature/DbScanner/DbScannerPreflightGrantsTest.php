<?php

namespace Tests\Feature\DbScanner;

use App\Services\DbScanner\Engine\Preflight;
use Tests\TestCase;

class DbScannerPreflightGrantsTest extends TestCase
{
    private function evaluate(array $grants): array
    {
        return app(Preflight::class)->evaluateGrants($grants, 'market_db', 'mysql');
    }

    public function test_schema_scoped_select_is_accepted(): void
    {
        $this->assertSame([true, null], $this->evaluate([
            'GRANT USAGE ON *.* TO `scan_reader`@`localhost`',
            'GRANT SELECT ON `market_db`.* TO `scan_reader`@`localhost`',
        ]));
        $this->assertTrue($this->evaluate(["GRANT SELECT, SHOW VIEW ON `market\\_db`.* TO 'r'@'%'"])[0]);
    }

    public function test_writable_global_cross_schema_and_role_grants_are_rejected(): void
    {
        foreach ([
            ['GRANT ALL PRIVILEGES ON `market_db`.* TO `u`@`%`'],
            ['GRANT SELECT, INSERT, UPDATE ON `market_db`.* TO `u`@`%`'],
            ['GRANT SELECT ON *.* TO `u`@`%`'],
            ['GRANT FILE ON *.* TO `u`@`%`', 'GRANT SELECT ON `market_db`.* TO `u`@`%`'],
            ['GRANT SELECT ON `other_db`.* TO `u`@`%`'],
            ['GRANT SELECT, TRIGGER ON `market_db`.* TO `u`@`%`'],
            ['GRANT SELECT, CREATE TEMPORARY TABLES ON `market_db`.* TO `u`@`%`'],
            ['GRANT SELECT ON `market_db`.* TO `u`@`%` WITH GRANT OPTION'],
            ['GRANT `reporting_role` TO `u`@`%`'],
            ['GRANT USAGE ON *.* TO `u`@`%`'],
            [],
        ] as $grants) {
            $this->assertFalse($this->evaluate($grants)[0], json_encode($grants));
        }
    }
}
