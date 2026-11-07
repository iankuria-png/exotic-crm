import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import CollectionSalesFlow from './CollectionSalesFlow';
import { receiptTime } from './CollectionReceiptEvidence';

export function CollectionPayerSummary({ payer }) {
    const [copied, setCopied] = useState('');
    return <section aria-label="SMS payer" className="space-y-1">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Paid by · Receiving SMS</h3>
        <p className="break-words text-lg font-semibold text-slate-900">{payer?.name || (payer?.name_state === 'conflicting' ? 'Payer names differ between messages' : 'Name not supplied in this SMS')}</p>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1"><p className="font-medium tabular-nums text-slate-700">{payer?.phone || 'Phone not supplied in this SMS'}</p>
            {payer?.phone && <button type="button" className="text-xs font-medium text-teal-700 underline" onClick={async () => { try { await navigator.clipboard.writeText(payer.phone); setCopied('Number copied'); } catch { setCopied('Select the number to copy it.'); } }}>Copy number</button>}
            {copied && <span role="status" className="text-xs text-slate-500">{copied}</span>}</div>
        {!payer?.name && payer?.party && <p className="break-words text-xs text-slate-500">SMS sender details: {payer.party}</p>}
        <p className="text-xs text-slate-500">The person paying may have a different name or number from the advertiser.</p>
    </section>;
}

export default function CollectionPurchaseReview({ row, onSaved }) {
    const { user } = useAuth();
    const [more, setMore] = useState([]);
    const [cursor, setCursor] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const context = useQuery({ queryKey: ['collection-manual-context', user?.id, row.id, row.version],
        queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/review-context`, { params: { workflow: 'manual' } })).data,
        refetchOnWindowFocus: false, retry: false });
    const data = context.data;
    const history = data?.history;
    const blocked = row.usage && ['already_recorded', 'conflict'].includes(row.usage.state);
    const seed = data?.saved_choice ? { client: data.saved_choice.client, client_id: data.saved_choice.client.id,
        saved_intent_id: data.saved_choice.id, purchase: data.saved_choice.purchase, product_price_id: data.saved_choice.purchase.product_price_id,
        deal_id: data.recommended_intent?.deal_id } : data?.selected_client ? { client: data.selected_client, client_id: data.selected_client.id } : null;
    return <section aria-label="Review purchase" className="space-y-4">
        {context.isPending && !row.sale && <p role="status" className="text-sm text-slate-500">Checking this payment and profile matches…</p>}
        {context.isError && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">Could not load payment details. <button type="button" className="underline" onClick={() => context.refetch()}>Try again</button></p>}
        {(data || row.sale) && !blocked && <CollectionSalesFlow key={row.id} row={row} initialSeed={seed} suggestions={data?.suggestions || []} needsChoice={data?.needs_choice} direct onSaved={onSaved} />}
        {history && <details className="border-t border-slate-200 pt-4"><summary className="cursor-pointer py-2 text-sm font-medium text-teal-800">Earlier payments {history.prior_count === null ? '' : `(${history.prior_count}${history.complete ? '' : '+'})`}</summary><div className="mt-2 space-y-3 text-sm">
            <p className="text-xs text-slate-500">{history.scope?.label}. {history.complete ? 'Current payment excluded.' : 'Available history is incomplete; counts are lower bounds.'}</p>
            {[...(history.items || []), ...more].filter((item, i, all) => all.findIndex(x => x.key === item.key) === i).map(item => <div key={item.key} className="flex justify-between gap-3 border-b border-slate-100 py-2"><span>{item.currency} {Number(item.amount_minor / 100).toLocaleString()}<span className="block text-xs text-slate-500">{item.source === 'crm' ? 'Recorded in CRM' : 'Captured receipt'}</span></span><span className="text-right text-xs text-slate-600">{receiptTime(item.at)}<span className="block select-all">{item.reference || 'Reference unavailable'}</span></span></div>)}
            {history.prior_count === 0 && <p>No earlier payments found.</p>}
            {(cursor || (!more.length && history.next_cursor)) && <button type="button" className="py-2 font-medium text-teal-800 underline" disabled={loading} onClick={async () => { setLoading(true); setError(''); try { const next = (await api.get(`/crm/collections/receipts/${row.id}/review-context`, { params: { workflow: 'manual', cursor: cursor || history.next_cursor } })).data.history; setMore(rows => [...rows, ...next.items]); setCursor(next.next_cursor); } catch { setError('Could not load earlier payments. Try again.'); } finally { setLoading(false); } }}>{loading ? 'Loading…' : 'Show more payments'}</button>}
            {error && <p role="alert" className="text-rose-800">{error}</p>}
        </div></details>}
    </section>;
}
