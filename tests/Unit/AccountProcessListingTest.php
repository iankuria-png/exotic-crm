<?php

namespace Tests\Unit;

use App\Services\Ops\AccountProcessListing;
use PHPUnit\Framework\TestCase;

class AccountProcessListingTest extends TestCase
{
    public function test_foreign_php_and_schedulers_are_excluded_from_account_pressure(): void
    {
        $own = '/opt/cpanel/ea-php82/root/usr/bin/php artisan crm:sample-vitals';
        $output = " UID COMMAND\n 1024 $own\n 1025 php-fpm: pool neighbour\n 1025 php artisan schedule:run\n 1024 php-fpm: pool crm\n 1024 php artisan schedule:run\n 0 php-fpm: master process\n";
        $this->assertSame([$own, 'php-fpm: pool crm', 'php artisan schedule:run'], AccountProcessListing::parse($output, 1024, $own));
    }

    public function test_missing_own_process_and_unqualified_output_are_unavailable(): void
    {
        $this->assertNull(AccountProcessListing::parse('ps: unsupported argument', 1024, 'php artisan'));
        $this->assertNull(AccountProcessListing::parse("1025 php artisan\n1024 bash", 1024, 'php artisan'));
        $this->assertNull(AccountProcessListing::parse("php artisan\nphp-fpm: pool crm", 1024, 'php artisan'));
    }

    public function test_bsd_style_whitespace_and_single_owned_process_are_valid(): void
    {
        $this->assertSame(['php artisan'], AccountProcessListing::parse(" 501   php artisan  \n502 php artisan", 501, 'php artisan'));
    }
}
