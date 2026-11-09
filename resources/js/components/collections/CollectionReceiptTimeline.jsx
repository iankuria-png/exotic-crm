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
    return <details className="border-t border-slate-200 pt-4"><summary className="cursor-pointer py-2 text-sm font-semibold text-slate-800">Payment activity · staff and system</summary>
        {timeline.items?.length ? <ol className="mt-3 space-y-4">{[...(timeline.items || []), ...more].filter((item, i, all) => all.findIndex(row => row.id === item.id) === i).map(item => <li key={item.id} className="border-l-2 border-slate-200 pl-3 text-sm">
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
