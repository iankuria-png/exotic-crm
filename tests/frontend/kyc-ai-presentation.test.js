import test from 'node:test';
import assert from 'node:assert/strict';
import { aiLabel, reviewSummary, policyCopy, evaluationScore } from '../../resources/js/components/kyc/kycAiPresentation.js';

test('queue, active processing and stalled attempts have distinct truthful labels', () => {
    assert.equal(aiLabel({ status: 'queued' }), 'Waiting to start');
    assert.equal(aiLabel({ status: 'running' }), 'AI reviewing photos');
    assert.match(reviewSummary({ status: 'queued' }).detail, /has not examined/);
    assert.match(reviewSummary({ status: 'stalled' }).detail, /no completed AI result/);
});
test('applied approvals differ from advisory recommendations and staff overrides', () => {
    assert.equal(aiLabel({ status: 'completed', recommendation: 'approve', action_taken: 'none' }), 'AI suggests approval');
    assert.equal(aiLabel({ status: 'completed', recommendation: 'approve', action_taken: 'approved' }), 'AI approved');
    assert.equal(aiLabel({ status: 'completed', action_taken: 'approved', human_decision: 'reject' }), 'Reviewer decision recorded');
    assert.match(reviewSummary(null, { status: 'approved' }).next, /already approved/);
});
test('failed processing is not an identity mismatch and consent has an actionable explanation', () => {
    assert.match(reviewSummary({ status: 'failed', error: 'worker_timeout' }).detail, /processing failure, not an identity finding/);
    assert.match(reviewSummary(null, {}, { blocked_reason: 'consent_required' }).blocked, /recorded consent/);
});
test('stale and calibration findings cannot be presented as current evidence', () => {
    assert.match(reviewSummary({ status: 'completed', action_taken: 'approved', stale: true }).detail, /cannot support a decision/);
    assert.match(reviewSummary({ status: 'completed', mode: 'shadow', recommendation: 'approve' }).detail, /cannot approve or reject/);
});
test('policy text matches auto-approve, advisory and automatic rejection settings', () => {
    assert.match(policyCopy({ mode: 'auto_approve', approve_threshold: 0.97 }), /all checks pass.*97%/);
    assert.match(policyCopy({ mode: 'auto_approve' }), /does not automatically reject/);
    assert.match(policyCopy({ mode: 'advisory' }), /Staff make every/);
    assert.match(policyCopy({ mode: 'full_auto', auto_reject_enabled: true }), /Automatic rejection is enabled/);
});
test('specific rule failures explain why staff must act and QA is an applied approval', () => {
    assert.match(reviewSummary({ status: 'completed', recommendation: 'human', reason_codes: ['second_opinion_required'] }).detail, /not confirmed/);
    assert.match(reviewSummary({ status: 'completed', action_taken: 'approved', qa_sample: true }).next, /already approved/);
});

test('self-assessment accepts zero and suppresses unavailable or invalid scores', () => {
    const review = { status: 'completed', mode: 'auto_approve', evaluation_confidence: .86 };
    assert.equal(evaluationScore(review), 86);
    assert.equal(evaluationScore({ ...review, evaluation_confidence: 0 }), 0);
    assert.equal(evaluationScore({ status: 'completed', observations: { evaluation_confidence: .9 } }), 90);
    for (const evaluation_confidence of [null, undefined, -1, 1.01, '0.9', NaN, Infinity]) {
        assert.equal(evaluationScore({ ...review, evaluation_confidence }), null);
    }
    for (const change of [{ status: 'failed' }, { status: 'queued' }, { stale: true }, { mode: 'shadow' }, { error: 'superseded' }]) {
        assert.equal(evaluationScore({ ...review, ...change }), null);
    }
});
