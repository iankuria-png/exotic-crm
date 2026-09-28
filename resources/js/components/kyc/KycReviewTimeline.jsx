import React, { useState } from 'react';

const reasonCopy = {
    model_declined: 'The provider declined this comparison; no fallback was used to bypass that refusal.',
    invalid_observation_schema: 'The provider response did not meet the required observation format.',
    incomplete_response: 'The provider stopped before returning a complete response.',
    provider_not_configured: 'No approved provider is configured for this market.',
    provider_unavailable: 'The provider could not be reached or did not return usable output.',
    worker_timeout: 'The queue worker did not finish this attempt in time.',
    disabled_or_superseded: 'Settings changed or a newer document set replaced this attempt.',
    daily_cap: 'The daily automation budget had already been used.',
};

function formatDate(value) {
    const date = value ? new Date(value) : null;
    return date && !Number.isNaN(date.getTime()) ? date.toLocaleString() : 'Time unavailable';
}

function title(value) {
    return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function EventDetail({ event }) {
    const meta = event.metadata || {};
    const reason = meta.reason && (reasonCopy[meta.reason] || title(meta.reason));
    const documents = meta.documents || meta.document_versions || [];
    return <div className="mt-2 space-y-2 text-xs leading-5 text-slate-600">
        {reason ? <p>{reason}</p> : null}
        {meta.model ? <p>Model: <span className="font-medium text-slate-800">{meta.model}</span>{meta.fallback ? ' · fallback' : ''}</p> : null}
        {meta.effective_mode ? <p>Effective mode: <span className="font-medium text-slate-800">{title(meta.effective_mode)}</span>{meta.approve_threshold != null ? ` · approve at ${Math.round(Number(meta.approve_threshold) * 100)}%` : ''}{meta.reject_threshold != null ? ` · reject at ${Math.round(Number(meta.reject_threshold) * 100)}%` : ''}</p> : null}
        {meta.rule_codes?.length ? <p>Applied rules: <span className="font-medium text-slate-800">{meta.rule_codes.map(title).join(', ')}</span></p> : null}
        {meta.retake?.length ? <p>Requested photos: <span className="font-medium text-slate-800">{meta.retake.map(title).join(', ')}</span></p> : null}
        {documents.length ? <p>Checked document versions: <span className="font-medium text-slate-800">{documents.map((document) => `${title(document.kind)}${document.sequence ? ` ${document.sequence + 1}` : ''} (v${document.id})`).join(', ')}</span></p> : null}
        {meta.queue_delay_seconds ? <p>Scheduled queue delay: {meta.queue_delay_seconds}s.</p> : null}
    </div>;
}

export default function KycReviewTimeline({ events = [], telemetryAvailable = false, review }) {
    const [expanded, setExpanded] = useState(false);
    const visible = expanded ? events : events.slice(0, 6);

    return <section className="rounded-xl border border-slate-200 bg-white" aria-label="Verification activity timeline">
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
            <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Recorded verification activity</p>
                <h4 className="mt-1 text-sm font-semibold text-slate-900">Decision trail</h4>
            </div>
            {review ? <span className="text-right text-[11px] tabular-nums text-slate-500">{review.latency_ms ? `${(review.latency_ms / 1000).toFixed(1)}s` : '—'} · {review.input_tokens || review.output_tokens ? `${Number(review.input_tokens || 0).toLocaleString()} in / ${Number(review.output_tokens || 0).toLocaleString()} out` : 'Usage unavailable'}<br />${Number(review.cost_usd || 0).toFixed(4)} cost</span> : null}
        </div>
        {!telemetryAvailable ? <div className="px-4 py-4 text-sm leading-6 text-slate-600">No durable event timeline is available for this historical case. Earlier review activity was not recorded in this format; only the current stored status and attempt fields can be shown.</div> : (
            <ol className="divide-y divide-slate-100">
                {visible.map((event) => <li key={event.id} className="px-4 py-3">
                    <div className="flex items-start justify-between gap-4"><p className={`text-sm font-medium ${event.level === 'warning' ? 'text-amber-800' : 'text-slate-800'}`}>{event.summary}</p><time className="shrink-0 text-[11px] tabular-nums text-slate-400">{formatDate(event.occurred_at)}</time></div>
                    <EventDetail event={event} />
                </li>)}
            </ol>
        )}
        {events.length > 6 ? <div className="border-t border-slate-100 px-4 py-3"><button type="button" onClick={() => setExpanded((value) => !value)} className="text-xs font-semibold text-teal-700 underline underline-offset-4">{expanded ? 'Show recent activity' : `Show all ${events.length} recorded events`}</button></div> : null}
    </section>;
}
