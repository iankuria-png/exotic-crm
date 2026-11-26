import React from 'react';
import { evaluationScore } from './kycAiPresentation';

export default function KycConfidenceScore({ review, compact = false }) {
    const score = evaluationScore(review);
    if (compact) return score === null ? null : <span className="mt-1 block text-xs tabular-nums text-slate-500" title="The model’s self-assessment of its evaluation. Uncalibrated; separate from face-match confidence.">Self-confidence {score}/100</span>;
    return <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3" aria-label="AI self-confidence">
        <div className="flex items-baseline justify-between gap-3"><p className="text-xs font-semibold text-slate-700">AI self-confidence</p><p className="text-xl font-semibold tabular-nums text-slate-900">{score === null ? <span className="text-sm font-medium text-slate-500">Not recorded</span> : <>{score}<span className="text-xs font-normal text-slate-500"> / 100</span></>}</p></div>
        {score !== null ? <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200" aria-hidden="true"><div className="h-full rounded-full bg-slate-600" style={{ width: `${score}%` }} /></div> : null}
        <p className="mt-2 text-xs leading-5 text-slate-500">{score === null ? 'This check did not record a self-assessment score.' : 'Model self-assessment · uncalibrated. Describes confidence in the evaluation, including uncertainty or a need for retakes. Separate from face-match confidence.'}</p>
    </div>;
}
