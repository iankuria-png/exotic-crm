export const reasonCopy = {
    dob_unreadable: 'The date of birth could not be read.',
    dob_invalid: 'The date of birth needs a manual check.',
    under_18: 'The document indicates an age below 18. Treat this as a priority review.',
    document_checks: 'At least one document, image integrity or pose check was inconclusive.',
    face_inconclusive: 'The face comparison did not meet the decision threshold.',
    selfie_mismatch: 'The AI found a likely face mismatch between the document and selfies.',
    second_opinion_required: 'A face mismatch was not confirmed by the required second opinion.',
    missing_poses: 'The required selfie poses were not all available.',
    model_declined: 'The AI provider declined the comparison. No automated identity decision was made.',
    invalid_observation_schema: 'The AI response could not be validated. No automated decision was made.',
    incomplete_response: 'The AI response was incomplete. No automated decision was made.',
    provider_not_configured: 'The AI provider is not configured for this market.',
    provider_unavailable: 'The AI provider did not return a usable result.',
    unsupported_document_format: 'A file format could not be processed. Inspect the originals.',
    invalid_image: 'A photo could not be processed. Inspect the originals.',
    worker_timeout: 'The automated check did not finish. This is a processing failure, not an identity finding.',
    human_decision_already_final: 'A reviewer already decided this case; the unused AI attempt was cancelled.',
    disabled_or_superseded: 'Automation was paused or newer photos replaced this attempt.',
    daily_cap: 'The daily AI budget was reached before this check could start.',
    glare: 'Glare obscures part of a photo.',
    blur: 'A photo is too blurred to assess.',
    cropped: 'Part of the required evidence is cut off.',
};

export function aiLabel(review, subjectStatus) {
    if (!review) return subjectStatus === 'approved' ? 'Approved without an AI result' : 'AI not run';
    if (review.stale || review.error === 'superseded') return 'Older photos · result not used';
    if (review.status === 'stalled') return 'AI check delayed';
    if (review.status === 'queued') return 'Waiting to start';
    if (review.status === 'running') return 'AI reviewing photos';
    if (review.mode === 'shadow') return 'Calibration check · no decision';
    if (review.status === 'skipped_cap') return 'AI budget reached';
    if (review.status === 'cancelled') return 'AI check cancelled';
    if (review.status !== 'completed') return 'AI check failed';
    if (review.human_decision) return 'Reviewer decision recorded';
    if (review.action_taken === 'approved') return review.qa_sample ? 'AI approved · staff spot-check' : 'AI approved';
    if (review.action_taken === 'info_requested') return 'AI requested photo retakes';
    if (review.action_taken === 'rejected') return 'AI rejected';
    return { approve: 'AI suggests approval', retake: 'AI suggests photo retakes', reject: 'Possible mismatch · staff decide', human_urgent: 'Priority staff review', human: 'Staff review needed' }[review.recommendation] || 'Staff review needed';
}

export function reviewSummary(review, subject = {}, automation = {}) {
    const label = aiLabel(review, subject.status);
    const reasons = (review?.reason_codes || []).map(code => reasonCopy[code] || `Review flag: ${String(code).replaceAll('_', ' ')}.`);
    let detail = reasons.join(' ');
    let next = 'Inspect the original photos and make a reviewer decision.';
    if (!review) {
        detail = 'There is no recorded AI result for these photos.';
    } else if (review.stale || review.error === 'superseded') {
        detail = 'These findings belong to a different submission. They cannot support a decision on the current photos.';
    } else if (review.status === 'queued') {
        detail = 'The photos are in the queue. The AI has not examined them yet.';
        next = 'The result will appear here automatically. You can also review the photos now.';
    } else if (review.status === 'running') {
        detail = 'The automated review has started. No final result is available yet.';
        next = 'Wait for the result here or complete a staff review.';
    } else if (review.status === 'stalled') {
        detail = 'No processing update has been recorded for more than 10 minutes. There is no completed AI result.';
        next = 'Review the photos now, or start a fresh AI check when automation is available.';
    } else if (review.mode === 'shadow') {
        detail = 'This check is for calibration only. Its findings are withheld and it cannot approve or reject this applicant.';
    } else if (review.status !== 'completed') {
        detail = reasonCopy[review.error] || (review.status === 'skipped_cap' ? reasonCopy.daily_cap : 'This attempt did not produce a usable AI result.');
    } else if (review.human_decision) {
        detail = 'A reviewer has recorded a decision after this AI check. The current case status is shown above.';
        next = 'Use the decision history to understand the final outcome.';
    } else if (review.action_taken === 'approved') {
        detail = 'The submission passed the required checks and the face comparison met the approval threshold. AI approval was applied.';
        next = review.qa_sample ? 'Spot-check the original photos and report any incorrect finding. The applicant is already approved.' : 'No routine reviewer decision is required. Review the evidence if a concern is raised.';
    } else if (review.action_taken === 'info_requested') {
        detail = detail || 'One or more photos need to be replaced. The applicant has been asked for retakes.';
        next = 'Wait for the requested photos; the replacement submission will receive a new review.';
    } else if (review.action_taken === 'rejected') {
        detail = detail || 'The automated rejection rules were met and rejection was applied.';
        next = 'Inspect the evidence and recorded policy rules if this decision is disputed.';
    } else if (review.recommendation === 'approve') {
        detail = 'The AI suggests approval, but it has not applied an approval. A staff decision is required.';
    } else if (!detail) {
        detail = 'The evidence did not meet every requirement for an automatic decision.';
    }
    if (subject.status === 'approved' && !review?.action_taken?.includes('approved')) {
        next = 'This applicant is already approved. No new AI check is needed.';
    }
    const blocked = {
        not_in_review: subject.status === 'info_requested' ? 'Waiting for replacement photos before another AI check.' : 'AI checks can start only on submissions awaiting review.',
        submission_required: 'A complete photo submission is needed before AI can review it.',
        consent_required: 'AI review requires the applicant’s recorded consent. Staff can still review the photos.',
        photos_changed: 'Photos changed after submission. Request a fresh completed submission.',
        automation_off: 'Automation is switched off for this market. Staff review is available.',
        worker_paused: 'Automated processing is paused. Staff can review this case now.',
        provider_not_configured: reasonCopy.provider_not_configured,
    }[automation.blocked_reason];
    return { label, detail, next, blocked };
}

export function policyCopy(automation = {}) {
    if (automation.mode === 'off') return 'AI automation is off for this market. Staff decide each case.';
    if (automation.mode === 'shadow') return 'Calibration only: AI runs independently and does not change applicant status.';
    if (automation.mode === 'advisory') return 'AI gives a recommendation. Staff make every approval, retake and rejection decision.';
    if (!['auto_approve', 'full_auto'].includes(automation.mode)) return 'Automation settings are unavailable. Use the recorded decision history for this attempt.';
    const threshold = Math.round(Number(automation.approve_threshold ?? 0.95) * 100);
    return `Eligible submissions are approved automatically only when all checks pass and the model’s face-match confidence reaches ${threshold}%. Photo-quality issues can trigger retakes. ${automation.auto_reject_enabled ? 'Automatic rejection is enabled under the configured mismatch rules.' : 'Possible mismatches and uncertain results go to staff; AI does not automatically reject them.'}`;
}
