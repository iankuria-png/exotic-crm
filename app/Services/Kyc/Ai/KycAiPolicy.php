<?php

namespace App\Services\Kyc\Ai;

use Carbon\CarbonImmutable;

class KycAiPolicy
{
    public function decide(array $o, array $settings, ?array $second = null): array
    {
        $result = fn ($recommendation, $reasons = [], $retake = []) => compact('recommendation', 'reasons', 'retake');
        if (($o['face_match'] ?? 'declined') === 'declined') {
            return $result('human', ['model_declined']);
        }
        $dob = $o['dob'] ?? null;
        if (! is_string($dob) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            return $result('human', ['dob_unreadable']);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $dob);
            if ($date->format('Y-m-d') !== $dob || $date->isFuture() || $date->age > 120) {
                return $result('human', ['dob_invalid']);
            }
        } catch (\Throwable) {
            return $result('human', ['dob_invalid']);
        }
        if ($date->age < 18) {
            return $result('human_urgent', ['under_18']);
        }
        $problems = $o['quality_problems'] ?? [];
        if ($problems !== []) {
            return $result('retake', array_values(array_unique(array_column($problems, 'reason'))), array_values(array_unique(array_column($problems, 'kind'))));
        }
        $clean = ($o['readable'] ?? false) === true
            && ($o['document_type_matches'] ?? false) === true
            && ($o['expired'] ?? null) === false
            && ($o['tamper_suspected'] ?? null) === false
            && ($o['screen_or_copy'] ?? null) === false
            && ($o['single_face'] ?? false) === true
            && ($o['poses_consistent'] ?? false) === true;
        if (! $clean) {
            return $result('human', ['document_checks']);
        }
        if ($o['face_match'] === 'likely_same' && $o['face_match_confidence'] >= $settings['approve_threshold']) {
            return $result('approve');
        }
        if ($o['face_match'] === 'likely_different' && $o['face_match_confidence'] >= $settings['reject_threshold']) {
            if ($settings['reject_requires_second_opinion'] && (! $second || ($this->decide($second, array_replace($settings, ['reject_requires_second_opinion' => false]))['recommendation'] ?? '') !== 'reject')) {
                return $result('human', ['second_opinion_required']);
            }

            return $result('reject', ['selfie_mismatch']);
        }

        return $result('human', ['face_inconclusive']);
    }

    public function action(string $mode, string $recommendation, array $settings): string
    {
        if (! in_array($mode, ['auto_approve', 'full_auto'], true)) {
            return 'none';
        }
        if ($recommendation === 'approve') {
            return 'approved';
        }
        if ($recommendation === 'retake') {
            return 'info_requested';
        }
        if ($mode === 'full_auto' && $settings['auto_reject_enabled'] && $recommendation === 'reject') {
            return 'rejected';
        }

        return 'flagged';
    }
}
