import React, { useState } from 'react';
import api from '../../services/api';

const actions = {
    payer_relationship_reviewed: 'Payer relationship reviewed', purchase_authority_reviewed: 'Exact purchase approved',
    purchase_choice_saved: 'Purchase choice saved', receipt_sale_prepared: 'Activation prepared', receipt_sale_confirmed: 'Site confirmed activation',
    correction_reserved: 'Correction started', correction_verified: 'Correction confirmed', correction_held: 'Correction needs checking',
    correction_owner_release_pending: 'Service corrected; site ownership release pending', collection_fulfillment_held: 'Activation held for review',
    automatic_match_reserved: 'Automatic match and purchase reserved', receipt_sale_outcome_reviewed: 'Saved activation checked',
    receipt_purchase_authority_consumed: 'Saved purchase linked to activation',
};
export default function CollectionReceiptTimeline({ timeline, receiptId }) {
    const [more, setMore] = useState([]);
    const [cursor, setCursor] = useState(undefined);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    if (!timeline) return null;
    return <details className="group pb-1"><summary className="flex cursor-pointer list-none items-center justify-between gap-3 py-3 text-sm font-medium text-slate-800 [&::-webkit-details-marker]:hidden">Payment activity · staff and system<svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.7" className="h-4 w-4 text-slate-400 transition-transform group-open:rotate-90"><path d="M7.5 5l5 5-5 5" /></svg></summary>
        {timeline.items?.length ? <ol className="relative mb-3 ml-1.5 space-y-4 border-l border-slate-200">{[...(timeline.items || []), ...more].filter((item, i, all) => all.findIndex(row => row.id === item.id) === i).map(item => <li key={item.id} className="relative pl-5 text-sm"><span aria-hidden="true" className={`absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full ring-2 ring-white ${item.actor === 'System' || /automatic/.test(item.action) ? 'bg-teal-600' : 'bg-slate-400'}`} />
            <p className="font-medium text-slate-800">{actions[item.action] || item.action.replaceAll('_', ' ')}</p>
            <p className="mt-1 text-xs text-slate-500">{item.actor} · {new Date(String(item.at).replace(' ', 'T') + (/(?:Z|[+-]\d\d:\d\d)$/.test(item.at) ? '' : 'Z')).toLocaleString('en-GB', { timeZone: 'Africa/Dar_es_Salaam' })} EAT</p>
            {item.reason && <p className="mt-1 text-slate-600">{item.reason.replaceAll('_', ' ')}</p>}
            {item.package && <p className="mt-1 text-slate-600">{item.package.tier?.toUpperCase()} · {item.package.duration_days} days · {item.package.currency} {Number(item.package.amount_minor / 100).toLocaleString()}</p>}
            {item.match_score != null && <p className="mt-1 text-xs text-slate-600">Match rule score {item.match_score} / 100 · policy {item.policy_version}</p>}
        </li>)}</ol> : <p className="mt-2 text-sm text-slate-500">No activation or staff review has been recorded for this payment.</p>}
        {(cursor === undefined ? timeline.next_before : cursor) && <button type="button" disabled={loading} className="mt-3 py-2 text-sm font-medium text-teal-800 underline" onClick={async () => {
            setLoading(true); setError('');
            try { const next = (await api.get(`/crm/collections/receipts/${receiptId}`, { params: { timeline_before: cursor === undefined ? timeline.next_before : cursor } })).data.timeline; setMore(rows => [...rows, ...next.items]); setCursor(next.next_before); }
            catch { setError('Earlier activity could not be loaded. Try again.'); }
            finally { setLoading(false); }
        }}>{loading ? 'Loading…' : 'Show earlier activity'}</button>}
        {error && <p role="alert" className="mt-2 text-sm text-rose-800">{error}</p>}
    </details>;
}
