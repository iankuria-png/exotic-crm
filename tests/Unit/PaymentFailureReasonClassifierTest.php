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
            'subscriber unavailable' => [
                'Customers SIM card is offline or their SIM card is too old to support mobile money payments.',
                'subscriber_unavailable',
            ],
            'pin approval failed' => [
                'The customer did not approve the payment because an incorrect PIN was entered.',
                'payment_not_approved',
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
            'provider rejected' => [
                'Provider verified the payment as failed.',
                'provider_rejected',
            ],
        ];
    }

    public function test_broad_payment_not_approved_code_uses_the_more_specific_message(): void
    {
        $result = $this->classifier->classify([
            'codes' => ['PAYMENT_NOT_APPROVED'],
            'messages' => ['Customers SIM card is offline or their SIM card is too old.'],
        ]);

        $this->assertSame('subscriber_unavailable', $result['code']);
        $this->assertTrue($result['classified']);
    }

    public function test_processing_stage_does_not_override_the_failure_reason(): void
    {
        $result = $this->classifier->classify([
            'codes' => ['callback_processing'],
            'messages' => ['The customer did not authorize the payment in time.'],
        ]);

        $this->assertSame('authorization_timeout', $result['code']);
    }

    public function test_unknown_recorded_wording_is_kept_as_an_other_provider_response(): void
    {
        $result = $this->classifier->classify([
            'codes' => ['provider_specific_9471'],
            'messages' => ['Unexpected terminal state alpha.'],
        ]);

        $this->assertSame('other_provider_response', $result['code']);
        $this->assertFalse($result['classified']);
        $this->assertTrue($result['recorded']);
    }

    public function test_missing_provider_detail_is_reported_separately(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertSame('reason_unavailable', $result['code']);
        $this->assertFalse($result['classified']);
        $this->assertFalse($result['recorded']);
    }
}
