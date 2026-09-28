import React, { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import kyc from '../../services/kyc';

export function aiLabel(review) {
    if (!review) return 'Not checked';
    if (review.mode === 'shadow') return 'Shadow check';
    if (review.stale || review.error === 'superseded') return 'Photos changed';
    if (['queued', 'running'].includes(review.status)) return 'Checking photos';
    if (review.status === 'skipped_cap') return 'Daily budget reached';
    if (review.status !== 'completed') return 'Needs human review';
    if (review.qa_sample) return 'Approved · QA sample';
    return { approve: 'Recommends approval', retake: 'Photo retake needed', reject: 'Identity mismatch', human_urgent: 'Priority human review', human: 'Needs human review' }[review.recommendation] || 'Needs human review';
}

function unavailableExplanation(review) {
    if (!review) return 'No automated attempt has been recorded for this submission. A reviewer can inspect the original evidence and decide.';
    if (review.stale || review.error === 'superseded') return 'These findings belong to older photos and are not being used for this submission.';
    if (review.status === 'skipped_cap') return 'The daily automation budget was reached before this check could start. This submission remains in the human queue.';
    if (['queued', 'running'].includes(review.status)) return 'This attempt is waiting for, or being processed by, the approved provider. You can continue the human review now.';
    const reasons = {
        model_declined: 'The provider declined the comparison. We did not use another provider to work around that refusal, so a human must review the evidence.',
        invalid_observation_schema: 'The provider response was not in the required format. No automated decision was made.',
        incomplete_response: 'The provider stopped before returning a complete result. No automated decision was made.',
        provider_not_configured: 'No approved provider is configured for this market.',
        unsupported_document_format: 'One or more submitted files cannot be processed by the automated reviewer. Inspect the originals or request replacement photos.',
        invalid_image: 'One or more submitted images could not be safely processed. Inspect the originals or request replacement photos.',
        worker_timeout: 'The review worker did not finish in time. The attempt is left for a human rather than silently retried.',
    };
    return reasons[review.error] || 'This attempt did not produce a usable automated decision. Review the original evidence and recorded activity below.';
}

export default function KycAiFindings({ review, subjectId, canReview, onRefresh, onRequestInfo }) {
    const [feedbackOpen, setFeedbackOpen] = useState(false);
    const [note, setNote] = useState('');
    const [notice, setNotice] = useState('');
    const run = useMutation({
        mutationFn: () => kyc.runAi(subjectId),
        onSuccess: () => { setNotice('Check queued. You can keep reviewing while it runs.'); onRefresh(); },
    });
    const feedback = useMutation({
        mutationFn: () => kyc.aiFeedback(subjectId, { review_id: review.id, note }),
        onSuccess: () => { setFeedbackOpen(false); setNote(''); setNotice('Feedback saved for calibration. The advertiser’s status has not changed.'); onRefresh(); },
    });
    const error = run.error || feedback.error;
    const shadow = review?.mode === 'shadow';
    const complete = review?.status === 'completed' && !shadow && !review.stale && review.error !== 'superseded';
    const o = review?.observations || {};
    const checks = [
        ['Document quality', o.readable && o.document_type_matches ? 'Readable · correct document type' : 'Check the document manually', o.readable && o.document_type_matches],
        ['Date of birth', o.dob || 'Not readable — human review required', Boolean(o.dob) && review?.recommendation !== 'human_urgent'],
        ['Document validity', o.expired === false ? 'No expiry issue identified' : o.expired ? 'Document appears expired' : 'Expiry not established', o.expired === false],
        ['Image integrity', o.tamper_suspected === false && o.screen_or_copy === false ? 'No copy or alteration signs identified' : 'Possible copy or alteration — inspect originals', o.tamper_suspected === false && o.screen_or_copy === false],
        ['Pose photos', o.single_face && o.poses_consistent ? 'One face · consistent pose photos' : 'Presence needs a human check', o.single_face && o.poses_consistent],
        ['Face comparison', `${String(o.face_match || 'inconclusive').replaceAll('_', ' ')}${o.face_match_confidence != null ? ` · ${Math.round(o.face_match_confidence * 100)}% model confidence` : ''}`, o.face_match === 'likely_same'],
    ];
    return <section className="overflow-hidden rounded-xl border border-slate-200 bg-white" aria-label="Automated verification findings">
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 bg-slate-50/80 px-5 py-4">
            <div><p className="text-[10px] font-bold uppercase tracking-widest text-slate-500">Verification assistant</p><h4 className="mt-1 text-base font-semibold text-slate-900">{aiLabel(review)}</h4><p className="mt-1 max-w-lg text-xs leading-5 text-slate-500">{shadow ? 'Findings stay hidden during review so your decision remains independent. Results appear in the calibration report.' : 'Evidence to support your judgement. Confidence is a model estimate, not proof of identity.'}</p></div>
            {canReview && subjectId ? <button type="button" disabled={run.isPending || ['queued', 'running'].includes(review?.status)} onClick={() => { setNotice(''); run.mutate(); }} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50">{run.isPending ? 'Queueing…' : review ? 'Run another check' : 'Run automated check'}</button> : null}
        </div>
        {error ? <p role="alert" className="m-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{error.response?.data?.message || 'The check could not be started. Please try again.'}</p> : null}
        {notice ? <p role="status" className="px-5 pt-4 text-sm text-teal-800">{notice}</p> : null}
        {complete ? <>
            <dl className="divide-y divide-slate-100 px-5">{checks.map(([label, detail, pass]) => <div key={label} className="grid gap-1 py-3 sm:grid-cols-[145px_1fr]"><dt className="text-xs font-medium text-slate-500">{label}</dt><dd className="flex items-start gap-2 text-sm text-slate-800"><span className={pass ? 'text-emerald-600' : 'text-amber-600'} aria-label={pass ? 'Pass' : 'Check'}>{pass ? '✓' : '!'}</span>{detail}</dd></div>)}</dl>
            {review.retake?.length ? <div className="mx-5 mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3"><p className="text-sm text-amber-900">{review.advertiser_message || `Retake: ${review.retake.join(', ').replaceAll('_', ' ')}`}</p>{canReview ? <button type="button" onClick={onRequestInfo} className="mt-2 text-xs font-semibold text-amber-900 underline underline-offset-4">Review message and request photos</button> : null}</div> : null}
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3"><p className="text-[11px] leading-5 text-slate-500">{review.model} · {review.prompt_version} · {(review.latency_ms / 1000).toFixed(1)}s · ${Number(review.cost_usd || 0).toFixed(4)}{review.fallback_used ? ' · fallback used' : ''}<br />{review.completed_at ? new Date(review.completed_at).toLocaleString() : ''}</p>{canReview ? <button type="button" onClick={() => setFeedbackOpen(!feedbackOpen)} className="text-xs font-medium text-slate-600 underline underline-offset-4">Report an incorrect finding</button> : null}</div>
        </> : <p className="px-5 py-5 text-sm leading-6 text-slate-600">{shadow ? 'Review the original photos and make your decision as usual. Shadow findings remain separate until calibration review.' : unavailableExplanation(review)}</p>}
        {review?.human_agreed === false ? <p className="border-t border-slate-100 px-5 py-3 text-xs text-slate-600">A reviewer has flagged this result for calibration.</p> : null}
        {feedbackOpen ? <form className="space-y-3 border-t border-slate-200 bg-slate-50 p-5" onSubmit={(event) => { event.preventDefault(); feedback.mutate(); }}><label className="block text-sm font-medium text-slate-700">What did the check get wrong?<textarea autoFocus required minLength={5} maxLength={1000} value={note} onChange={(event) => setNote(event.target.value)} className="crm-textarea mt-2 w-full" rows={3} /></label><div className="flex justify-end gap-3"><button type="button" onClick={() => setFeedbackOpen(false)} className="px-3 py-2 text-sm text-slate-600">Cancel</button><button type="submit" disabled={feedback.isPending || note.trim().length < 5} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{feedback.isPending ? 'Saving…' : 'Save feedback'}</button></div></form> : null}
    </section>;
}
