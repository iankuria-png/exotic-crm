<?php

namespace Tests\Unit;

use App\Services\PaymentFailureReasonClassifier;
use PHPUnit\Framework\TestCase;

class PaymentFailureReasonClassifierTest extends TestCase
{
    private PaymentFailureReasonClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new PaymentFailureReasonClassifier();
    }

    public function test_structured_code_takes_precedence_over_message(): void
    {
        $result = $this->classifier->classify([
            'codes' => ['insufficient_funds'],
            'messages' => ['Customer declined the request.'],
        ]);

        $this->assertSame('insufficient_funds', $result['code']);
        $this->assertTrue($result['classified']);
    }

    /**
     * @dataProvider representativeMessages
     */
    public function test_representative_messages_are_normalized(string $message, string $expectedCode): void
    {
        $result = $this->classifier->classify(['messages' => [$message]]);

        $this->assertSame($expectedCode, $result['code']);
    }

    public static function representativeMessages(): array
    {
        return [
            'authorization timeout' => [
                'The customer did not authorize the payment in time.',
                'authorization_timeout',
            ],
            'customer declined' => [
                'Request canceled by user.',
                'customer_declined',
            ],
            'insufficient funds' => [
                'The payer has insufficient funds.',
                'insufficient_funds',
            ],
            'invalid phone' => [
                'Invalid MSISDN supplied for this transaction.',
                'invalid_phone_account',
            ],
            'provider unavailable' => [
                'The upstream provider is unavailable.',
                'provider_network_unavailable',
            ],
            'limits' => [
                'Daily limit exceeded for this account.',
                'limits_compliance',
            ],
            'configuration' => [
                'Provider is not configured for this market.',
                'configuration_routing',
            ],
        ];
    }

    public function test_unknown_wording_remains_unclassified(): void
    {
        $result = $this->classifier->classify([
            'codes' => ['provider_specific_9471'],
            'messages' => ['Unexpected terminal state alpha.'],
        ]);

        $this->assertSame(PaymentFailureReasonClassifier::UNCLASSIFIED, $result['code']);
        $this->assertFalse($result['classified']);
    }
}
