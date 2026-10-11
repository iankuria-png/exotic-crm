<?php

namespace Tests\Unit;

use App\Http\Controllers\API\PaymentController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LegacyPaymentPhoneTest extends TestCase
{
    /** @dataProvider marketPhones */
    public function test_legacy_payment_phone_keeps_market_and_digits(string $phone, string $prefix, string $expected): void
    {
        $controller = (new ReflectionClass(PaymentController::class))->newInstanceWithoutConstructor();
        $normalize = new \ReflectionMethod($controller, 'normalizePhone');
        $valid = new \ReflectionMethod($controller, 'validMarketSmsPhone');
        $actual = $normalize->invoke($controller, $phone, $prefix);
        $this->assertSame($expected, $actual);
        $this->assertTrue($valid->invoke($controller, $actual, $prefix));
        $this->assertFalse($valid->invoke($controller, $actual, $prefix === '254' ? '233' : '254'));
        $checkout = (new ReflectionClass(\App\Services\ContactUnlockCheckoutService::class))->newInstanceWithoutConstructor();
        $this->assertSame($expected, $checkout->normalizePhone($phone, $prefix));
    }

    public static function marketPhones(): array
    {
        return [
            ['0712345678', '254', '254712345678'],
            ['00254712345678', '254', '254712345678'],
            ['520123456', '254', '254520123456'],
            ['0241234567', '233', '233241234567'],
            ['+233 24 123 4567', '233', '233241234567'],
            ['00233241234567', '233', '233241234567'],
            ['08012345678', '234', '2348012345678'],
            ['+234 801 234 5678', '234', '2348012345678'],
        ];
    }
}
