import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';

const button = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 disabled:opacity-50';
const messages = {
    original_compensation_preimage_required: 'The original site operation does not contain a complete reversible snapshot, or later service has changed it. A manager must review recovery.',
    correction_cash_allocation_requires_finance_review: 'This package changes the allocated payment amount. Finance must review the allocation before continuing.',
    consumed_or_paid_benefit_requires_review: 'A paid commission, claimed pass or rebate needs a separate reviewed correction.',
    subsidiary_sale_correction_requires_owned_benefit_review: 'The linked benefit must be reviewed with this activation before it can be reversed.',
    correction_target_conflicts_with_sibling_or_market: 'Choose a profile in the same market that is not already using another part of this payment.',
};
export default function CollectionSaleCorrection({ item, receipt, onUpdated }) {
    const [action, setAction] = useState('');
    const [term, setTerm] = useState('');
    const [target, setTarget] = useState(null);
    const [price, setPrice] = useState('');
    const [reason, setReason] = useState('');
    const [preview, setPreview] = useState(null);
    const [key, setKey] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const search = useQuery({ queryKey: ['sale-correction-clients', receipt.id, term], enabled: term.trim().length >= 2,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${receipt.id}/candidates`, { params: { q: term.trim() } })).data, retry: false });
    const options = useQuery({ queryKey: ['sale-correction-options', receipt.id, target?.id], enabled: Boolean(target),
        queryFn: async () => (await api.get(`/crm/collections/receipts/${receipt.id}/review-context`, { params: { workflow: 'manual', selected_client_id: target.id } })).data, retry: false });
    const pending = item.corrections?.find(c => c.request_key && c.state !== 'confirmed');
    async function check() {
        setBusy(true); setError('');
        try {
            const result = (await api.post(`/crm/collections/sales/items/${item.id}/correction-preview`, { item_version: item.version, action, reason,
                ...(action !== 'undo_effect' ? { client_id: target.id, product_price_id: Number(price) } : {}) })).data;
            setPreview(result); setKey(crypto.randomUUID());
        } catch (failure) { setError(failure.response?.data?.message || 'Could not prepare this correction. Your choices are retained.'); }
        finally { setBusy(false); }
    }
    async function execute(saved = preview, request = key) {
        setBusy(true); setError('');
        try {
            const result = (await api.post(`/crm/collections/sales/corrections/${saved.id}/execute`, { version: saved.version, request_key: request })).data;
            setPreview(result); await onUpdated();
            if (result.state === 'confirmed') setAction('');
        } catch (failure) { setError(failure.response?.data?.message || 'The correction needs checking. Use the saved outcome before trying again.'); }
        finally { setBusy(false); }
    }
    return <section aria-label={`Correct activation for ${item.client.name}`} className="mt-4 space-y-3 border-t border-slate-200 pt-3">
        {pending && <><p className="text-sm text-amber-900">A correction is awaiting confirmation. The saved owner continues to protect this payment and profile.</p><button type="button" className={button} disabled={busy} onClick={() => execute(pending, pending.request_key)}>Check saved correction</button></>}
        {!pending && !action && item.correction_available && <div className="flex flex-wrap gap-3"><button type="button" className={button} onClick={() => { setAction('reassign'); setTarget(null); setPrice(''); setPreview(null); }}>Change profile or package</button><button type="button" className={button} onClick={() => { setAction('undo_effect'); setPreview(null); }}>Revert this activation</button></div>}
        {!pending && !item.correction_available && <p className="text-xs text-slate-500">Correction needs verified site support and a reversible activation. A manager can check any linked benefit or uncertain outcome.</p>}
        {action && <div className="space-y-3">
            <p className="text-sm text-slate-600">The received money stays unchanged. This changes only this profile’s activation; other profiles sharing the payment keep their service.</p>
            {action !== 'undo_effect' && <><label className="block text-sm font-medium">Find the intended profile<input className="crm-input mt-1 w-full" value={term} onChange={e => { setTerm(e.target.value); setPreview(null); }} placeholder="Name, full phone or profile" /></label>
                {search.isFetching && <p role="status" className="text-sm text-slate-500">Searching permitted profiles…</p>}
                {search.isError && <p role="alert" className="text-sm text-rose-800">Search failed. <button type="button" className="underline" onClick={() => search.refetch()}>Retry</button></p>}
                {search.data?.clients?.map(client => <button type="button" key={client.id} className={`${button} mr-2`} onClick={() => { setTarget(client); setPrice(''); setPreview(null); }}>{client.name} · Profile {client.wp_post_id}</button>)}
                {target && <p className="text-sm font-medium">Selected profile: {target.name}</p>}
                {options.isError && <p role="alert" className="text-sm text-rose-800">Package options could not be loaded. <button type="button" className="underline" onClick={() => options.refetch()}>Retry</button></p>}
                {target && <label className="block text-sm font-medium">Exact purchased package<select className="crm-input mt-1 w-full" value={price} onChange={e => { setPrice(e.target.value); setPreview(null); }}><option value="">Choose package and duration</option>{options.data?.prices?.map(p => <option value={p.id} key={p.id}>{p.product.name} · {p.duration_days} days · {p.currency} {Number(p.price).toLocaleString()}</option>)}</select></label>}
            </>}
            <label className="block text-sm font-medium">Reason for the correction<textarea className="crm-input mt-1 w-full" rows={2} value={reason} onChange={e => { setReason(e.target.value); setPreview(null); }} /></label>
            {!preview?.request_key && <div className="flex gap-3"><button type="button" className={button} disabled={busy || reason.trim().length < 3 || (action !== 'undo_effect' && (!target || !price))} onClick={check}>{busy ? 'Checking…' : 'Preview correction'}</button><button type="button" className={button} disabled={busy} onClick={() => { setAction(''); setPreview(null); }}>Cancel</button></div>}
        </div>}
        {preview && <div className="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm"><p className="font-semibold">{preview.state === 'confirmed' ? 'Correction confirmed' : preview.request_key ? 'Correction in progress' : 'Review correction'}</p><p>{preview.preview.target ? `Move this payment allocation to profile ${preview.preview.target.client_id}: ${preview.preview.target.tier.toUpperCase()}, ${preview.preview.target.duration_days} days.` : 'Remove only this activation and leave this allocation unassigned for staff review.'}</p>
            {preview.preview.reasons.map(code => <p key={code} className="text-amber-900">{messages[code] || code.replaceAll('_', ' ')}</p>)}
            {preview.state !== 'confirmed' && <button type="button" className={`${button} !border-teal-700 !bg-teal-700 !text-white`} disabled={busy || !preview.preview.can_execute} onClick={() => execute()}>{busy ? 'Checking…' : preview.request_key ? 'Check correction progress' : 'Confirm correction'}</button>}
        </div>}
        {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}
    </section>;
}
