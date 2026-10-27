<?php

namespace Tests\Unit;

use App\Services\Payments\PaymentIdentityTokenizer;
use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PaymentPhoneNormalizationTest extends TestCase
{
    public function test_sync_and_matching_share_country_aware_parser(): void
    {
        foreach ([
            ['0722123456', '254', '254722123456'], ['+254(0)722123456', '254', '254722123456'], ['0195992000', '229', '2290195992000'], ['+2290195992000', '229', '2290195992000'], ['22995992000', '229', null], ['+254722123456', '254', '254722123456'], ['00254722123456', '254', '254722123456'],
            ['771234567', '221', '221771234567'], ['+221771234567', '221', '221771234567'],
            ['0701020304', '225', '2250701020304'], ['+2250701020304', '225', '2250701020304'], ['002250701020304', '225', '2250701020304'],
            ['+233201234567', '254', '233201234567'], ['233201234567', '254', '233201234567'], ['221771234567', '225', '221771234567'], ['not a phone', '221', null], ['225701020304', '225', null],
        ] as [$input,$country,$expected]) {
            $this->assertSame($expected, PhoneNormalizer::normalize($input, $country), $input);
            $this->assertSame($expected, (new PaymentIdentityTokenizer)->canonicalPhone($input, $country), $input);
        }
    }
}
