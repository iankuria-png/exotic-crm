import React, { useId } from 'react';
import { Icon, relative, secondaryButton } from './collectionUi';

export default function CollectionReversalAlerts({ reversals = [], onOpen }) {
    const titleId = useId();
    if (!reversals.length) return null;
    const pending = reversals.filter(item => !item.reviewed).length;
    const reviewed = reversals.length - pending;
    return <details aria-labelledby={titleId} className={`group mb-4 overflow-hidden rounded-xl border ${pending ? 'border-rose-200 bg-white' : 'border-slate-200 bg-white'}`}>
        <summary className="flex min-h-14 cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-600 [&::-webkit-details-marker]:hidden">
            <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                <span id={titleId} className="font-semibold text-slate-900">Reversed &amp; held payments</span>
                <span className={`rounded-full px-2 py-0.5 text-xs font-medium tabular-nums ${pending ? 'bg-rose-50 text-rose-800' : 'bg-slate-100 text-slate-600'}`}>{pending ? `${pending} to review` : 'All reviewed'}</span>
                {reviewed > 0 && <span className="text-xs text-slate-500">{reviewed} reviewed</span>}
            </span>
            <span className="ml-auto inline-flex shrink-0 items-center gap-2 text-xs font-medium text-slate-600"><span className="group-open:hidden">Show details</span><span className="hidden group-open:inline">Hide details</span><Icon name="chevron" className="h-4 w-4 transition-transform group-open:rotate-90 motion-reduce:transition-none" /></span>
        </summary>
        <div className="border-t border-slate-200 bg-slate-50/60 p-3 sm:p-4">
            <p className="mb-3 text-xs text-slate-600">Provider reversals and holds reported in the last 30 days for this market view.</p>
            <ul className="space-y-2">{reversals.map(item => <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3">
                <div className="min-w-0 flex-1 text-sm">
                    {item.account_label && <p className="mb-1 text-xs text-slate-500">{item.account_label}</p>}
                    <div className="flex flex-wrap items-baseline gap-2"><span className="font-semibold tabular-nums text-slate-900">{item.currency} {(item.principal_minor / 100).toLocaleString()}</span><span className="break-all rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700">{item.reference}</span></div>
                    <p className="mt-1 text-xs text-slate-600">{item.funds_state === 'reversed' ? 'Reversed' : 'On hold'} {relative(item.flagged_at)}</p>
                    <p className={`mt-1 break-words text-xs ${!item.reviewed && item.service_active ? 'font-medium text-rose-800' : 'text-slate-600'}`}>{item.reviewed ? `Reviewed · ${item.reviewed.decision}` : item.service_active ? 'Service still active · Review required' : 'No active service'}</p>
                </div>
                <button type="button" aria-label={`${item.reviewed ? 'View' : 'Review'} payment ${item.reference}`} className={`${secondaryButton} min-h-11 shrink-0 ${!item.reviewed ? '!border-rose-200 !text-rose-800 hover:!bg-rose-50' : ''}`} onClick={() => onOpen(item.id)}>{item.reviewed ? 'View' : 'Review'}</button>
            </li>)}</ul>
        </div>
    </details>;
}
