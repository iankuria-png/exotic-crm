<?php

namespace Tests\Unit\Kyc;

use App\Services\Kyc\Ai\KycAiPolicy;
use App\Services\Kyc\Ai\KycObservationSchema;
use PHPUnit\Framework\TestCase;

class KycAiPolicyTest extends TestCase
{
    public static function clean(): array
    {
        return ['legal_name' => 'Test Person', 'dob' => '1995-04-20', 'nationality' => 'Kenyan', 'document_number_last4' => '1234', 'readable' => true, 'document_type_matches' => true, 'expired' => false, 'tamper_suspected' => false, 'screen_or_copy' => false, 'single_face' => true, 'poses_consistent' => true, 'face_match' => 'likely_same', 'face_match_confidence' => .98, 'quality_problems' => []];
    }

    private function settings(): array
    {
        return ['approve_threshold' => .95, 'reject_threshold' => .98, 'reject_requires_second_opinion' => true, 'auto_reject_enabled' => true];
    }

    public function test_policy_guardrails_and_mode_matrix(): void
    {
        $policy = new KycAiPolicy;
        $cases = [
            [[], 'approve'], [['dob' => null], 'human'], [['dob' => '2020-01-01'], 'human_urgent'],
            [['dob' => '1990-02-31'], 'human'], [['dob' => '2099-01-01'], 'human'],
            [['face_match' => 'declined'], 'human'], [['expired' => true], 'human'],
            [['tamper_suspected' => true], 'human'], [['poses_consistent' => false], 'human'],
            [['face_match_confidence' => .8], 'human'],
            [['quality_problems' => [['kind' => 'id_front', 'reason' => 'blur']]], 'retake'],
            [['face_match' => 'likely_different'], 'human'],
        ];
        foreach ($cases as [$change, $expected]) {
            $decision = $policy->decide(array_replace(self::clean(), $change), $this->settings());
            $this->assertSame($expected, $decision['recommendation'], json_encode($change));
            foreach (['off', 'shadow', 'advisory', 'auto_approve', 'full_auto'] as $mode) {
                $action = $policy->action($mode, $expected, $this->settings());
                if (in_array($mode, ['off', 'shadow', 'advisory'])) {
                    $this->assertSame('none', $action);
                } else {
                    $this->assertSame(['approve' => 'approved', 'retake' => 'info_requested'][$expected] ?? 'flagged', $action);
                }
            }
        }
    }

    public function test_rejection_requires_independent_agreement_and_enabled_full_auto(): void
    {
        $policy = new KycAiPolicy;
        $o = array_replace(self::clean(), ['face_match' => 'likely_different']);
        $this->assertSame('reject', $policy->decide($o, $this->settings(), $o)['recommendation']);
        $this->assertSame('human', $policy->decide($o, $this->settings(), self::clean())['recommendation']);
        $this->assertSame('flagged', $policy->action('auto_approve', 'reject', $this->settings()));
        $this->assertSame('rejected', $policy->action('full_auto', 'reject', $this->settings()));
        $this->assertSame('flagged', $policy->action('full_auto', 'reject', array_replace($this->settings(), ['auto_reject_enabled' => false])));
    }

    public function test_schema_rejects_injected_fields_and_full_document_numbers(): void
    {
        $this->assertSame(self::clean(), KycObservationSchema::validate(self::clean()));
        foreach ([['approve_now' => true], ['document_number_last4' => '1234567890'], ['readable' => 'yes']] as $extra) {
            try {
                KycObservationSchema::validate(array_replace(self::clean(), $extra));
                $this->fail('Invalid schema accepted');
            } catch (\RuntimeException $e) {
                $this->assertSame('invalid_observation_schema', $e->getMessage());
            }
        }
    }
}
