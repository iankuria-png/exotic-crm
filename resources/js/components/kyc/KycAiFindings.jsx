import React, { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import kyc from '../../services/kyc';

import { reviewSummary, policyCopy } from './kycAiPresentation';
export { aiLabel } from './kycAiPresentation';

export default function KycAiFindings({ review, subject = {}, automation = {}, subjectId, canReview, onRefresh, onRequestInfo }) {
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
    const summary = reviewSummary(review, subject, automation);
    const active = ['queued', 'running'].includes(review?.status);
    const canRun = canReview && subjectId && automation.can_run === true;
    const complete = review?.status === 'completed' && !shadow && !review.stale && review.error !== 'superseded';
    const o = review?.observations || {};
    const checks = [
        ['Document quality', o.readable && o.document_type_matches ? 'Readable · correct document type' : 'Check the document manually', o.readable && o.document_type_matches],
        ['Date of birth', o.dob || 'Not readable — human review required', Boolean(o.dob) && !['under_18', 'dob_invalid', 'dob_unreadable'].some(code => review?.reason_codes?.includes(code))],
        ['Document validity', o.expired === false ? 'No expiry issue identified' : o.expired ? 'Document appears expired' : 'Expiry not established', o.expired === false],
        ['Image integrity', o.tamper_suspected === false && o.screen_or_copy === false ? 'No copy or alteration signs identified' : o.tamper_suspected === true || o.screen_or_copy === true ? 'Possible copy or alteration — inspect originals' : 'Image integrity not established', o.tamper_suspected === false && o.screen_or_copy === false],
        ['Pose photos', o.single_face && o.poses_consistent ? 'One face · consistent pose photos' : 'Presence needs a human check', o.single_face && o.poses_consistent],
        ['Face comparison', `${String(o.face_match || 'inconclusive').replaceAll('_', ' ')}${o.face_match_confidence != null ? ` · ${Math.round(o.face_match_confidence * 100)}% model confidence` : ''}`, o.face_match === 'likely_same' && Number(o.face_match_confidence) >= Number(automation.approve_threshold ?? 0.95)],
    ];
    return <section className="overflow-hidden rounded-xl border border-slate-200 bg-white" aria-label="Automated verification findings">
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 bg-slate-50/80 px-5 py-4">
            <div><p className="text-[10px] font-bold uppercase tracking-widest text-slate-500">Verification assistant</p><h4 className="mt-1 text-base font-semibold text-slate-900">{summary.label}</h4><p className="mt-1 max-w-lg text-xs leading-5 text-slate-500">{summary.detail}</p></div>
            {canRun ? <button type="button" disabled={run.isPending || active} onClick={() => { setNotice(''); run.mutate(); }} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50">{run.isPending ? 'Queueing…' : review ? 'Retry AI check' : 'Start AI check'}</button> : null}
        </div>
        {error ? <p role="alert" className="m-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{error.response?.data?.message || 'The check could not be started. Please try again.'}</p> : null}
        {notice ? <p role="status" className="px-5 pt-4 text-sm text-teal-800">{notice}</p> : null}
        <div className="space-y-3 px-5 py-4">
            <div className="border-l-2 border-teal-600 pl-3"><p className="text-xs font-semibold text-slate-800">Next step</p><p className="mt-1 text-sm leading-6 text-slate-600">{summary.next}</p></div>
            {summary.blocked ? <p className="text-xs leading-5 text-amber-800">{summary.blocked}</p> : null}
            {active ? <ol aria-label="AI review progress" className="grid grid-cols-3 gap-2 text-xs">
                {['Waiting to start', 'AI reviewing', 'Result recorded'].map((stage, index) => <li key={stage} aria-current={(review.status === 'queued' ? index === 0 : index === 1) ? 'step' : undefined} className={`border-t-2 pt-2 ${index <= (review.status === 'queued' ? 0 : 1) ? 'border-teal-600 text-teal-800' : 'border-slate-200 text-slate-500'}`}>{stage}</li>)}
            </ol> : null}
            <details className="rounded-lg border border-slate-200 px-3 py-2">
                <summary className="cursor-pointer text-xs font-semibold text-slate-700">How AI reviews this KYC</summary>
                <div className="mt-3 space-y-3 text-xs leading-5 text-slate-600">
                    <p>{policyCopy(automation)}</p>
                    <ol className="list-decimal space-y-2 pl-4">
                        <li>Use the applicant’s consented submission and its exact photo versions.</li>
                        <li>Check document readability, type, expiry, date of birth and signs of copying or alteration.</li>
                        <li>Compare the ID face with the selfie poses and check that the poses are consistent. These photos alone do not prove liveness.</li>
                        <li>Apply the configured rules: approval, photo retakes, or staff review. A processing failure never counts as an identity mismatch.</li>
                    </ol>
                    <p>Confidence is the model’s estimate, not a measured probability that the person’s identity is correct. Some AI approvals are selected for staff spot-checks.</p>
                </div>
            </details>
        </div>
        {complete ? <>
            <dl className="divide-y divide-slate-100 px-5">{checks.map(([label, detail, pass]) => <div key={label} className="grid gap-1 py-3 sm:grid-cols-[145px_1fr]"><dt className="text-xs font-medium text-slate-500">{label}</dt><dd className="flex items-start gap-2 text-sm text-slate-800"><span className={pass ? 'text-emerald-600' : 'text-amber-600'} aria-label={pass ? 'Pass' : 'Check'}>{pass ? '✓' : '!'}</span>{detail}</dd></div>)}</dl>
            {review.retake?.length ? <div className="mx-5 mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3"><p className="text-sm text-amber-900">{review.advertiser_message || `Retake: ${review.retake.join(', ').replaceAll('_', ' ')}`}</p>{canReview && review.action_taken !== 'info_requested' ? <button type="button" onClick={onRequestInfo} className="mt-2 text-xs font-semibold text-amber-900 underline underline-offset-4">Review message and request photos</button> : null}</div> : null}
            <div className="flex flex-wrap items-start justify-between gap-3 border-t border-slate-100 px-5 py-3">
                <details className="max-w-full text-xs leading-5 text-slate-500"><summary className="cursor-pointer font-medium text-slate-700">AI attempt details</summary><p className="mt-2 break-words">Model: {review.model || 'Not recorded'}<br />Prompt: {review.prompt_version || 'Not recorded'}<br />Processing time: {review.latency_ms != null ? `${(review.latency_ms / 1000).toFixed(1)}s` : 'Not recorded'}<br />Cost: {review.cost_usd != null ? `$${Number(review.cost_usd).toFixed(4)}` : 'Not recorded'}{review.fallback_used ? ' · fallback used' : ''}<br />Completed: {review.completed_at ? new Date(review.completed_at).toLocaleString() : 'Not recorded'}</p></details>
                {canReview ? <button type="button" onClick={() => setFeedbackOpen(!feedbackOpen)} className="text-xs font-medium text-slate-600 underline underline-offset-4">Report an incorrect finding</button> : null}
            </div>
        </> : null}
        {review?.human_agreed === false ? <p className="border-t border-slate-100 px-5 py-3 text-xs text-slate-600">A reviewer has flagged this result for calibration.</p> : null}
        {feedbackOpen ? <form className="space-y-3 border-t border-slate-200 bg-slate-50 p-5" onSubmit={(event) => { event.preventDefault(); feedback.mutate(); }}><label className="block text-sm font-medium text-slate-700">What did the check get wrong?<textarea autoFocus required minLength={5} maxLength={1000} value={note} onChange={(event) => setNote(event.target.value)} className="crm-textarea mt-2 w-full" rows={3} /></label><div className="flex justify-end gap-3"><button type="button" onClick={() => setFeedbackOpen(false)} className="px-3 py-2 text-sm text-slate-600">Cancel</button><button type="submit" disabled={feedback.isPending || note.trim().length < 5} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{feedback.isPending ? 'Saving…' : 'Save feedback'}</button></div></form> : null}
    </section>;
}
