import React, { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';

export default function CollectionStandingAgreement({ receipt }) {
    const { user } = useAuth();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [client, setClient] = useState(null);
    const [price, setPrice] = useState('');
    const [secondary, setSecondary] = useState('');
    const [until, setUntil] = useState('');
    const [renewals, setRenewals] = useState('');
    const [reason, setReason] = useState('');
    const [clear, setClear] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [result, setResult] = useState(null);
    const request = useRef({});
    const manager = ['admin', 'sub_admin'].includes(user?.role);
    const candidates = useQuery({ queryKey: ['standing-clients', user?.id, receipt.id, search], enabled: manager && open && search.trim().length >= 2,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${receipt.id}/candidates`, { params: { q: search.trim() } })).data, retry: false });
    const context = useQuery({ queryKey: ['standing-terms', user?.id, receipt.id, client?.id], enabled: manager && open && Boolean(client),
        queryFn: async () => (await api.get(`/crm/collections/receipts/${receipt.id}/review-context`, { params: { selected_client_id: client.id } })).data, retry: false });
    if (!manager) return null;
    const exclusions = context.data?.match_exclusions || [];
    const complete = client && price && until && Number(renewals) >= 1 && Number(renewals) <= 100 && reason.trim().length >= 3
        && (!context.data?.subsidiary_platform_id || secondary) && (!exclusions.length || clear);
    async function save() {
        setBusy(true); setError('');
        try {
            const payload = { receipt_version: receipt.version, client_id: client.id, product_price_id: Number(price),
                ...(secondary ? { subsidiary_client_id: Number(secondary) } : {}), valid_until: new Date(until).toISOString(), max_renewals: Number(renewals), reason: reason.trim(),
                clear_exclusions: clear ? exclusions.map(e => ({ id: e.id, version: e.version })) : [] };
            const digest = JSON.stringify(payload);
            if (request.current.digest !== digest) request.current = { digest, key: crypto.randomUUID() };
            const data = (await api.post(`/crm/collections/receipts/${receipt.id}/standing-agreements`, { ...payload, request_key: request.current.key })).data;
            setResult(data);
        } catch (failure) { setError(failure.response?.data?.message || 'The agreement could not be saved. Your choices are retained.'); }
        finally { setBusy(false); }
    }
    return <details className="border-t border-slate-200 pt-3" onToggle={event => setOpen(event.currentTarget.open)}><summary className="cursor-pointer py-2 text-sm font-medium text-slate-700">Approve future renewal agreement</summary>
        <p className="mt-2 text-sm text-slate-600">Approve this payer for one exact profile, package and price on future payments. This does not activate the current receipt. Automatic activation also requires a unique match and all receiving-account checks.</p>
        {result ? <p role="status" className="mt-3 rounded-lg bg-teal-50 p-3 text-sm text-teal-900">Agreement saved for profile {result.client_id}: {result.purchase.tier.toUpperCase()} · {result.purchase.duration_days} days · {result.purchase.currency} {Number(result.purchase.amount_minor / 100).toLocaleString()}. Up to {result.max_renewals} future payments, until {new Date(result.valid_until).toLocaleDateString('en-GB')}.</p> : <div className="mt-3 space-y-3">
            <label className="block text-sm font-medium">Find the advertiser<input className="crm-input mt-1 w-full" value={search} onChange={e => setSearch(e.target.value)} placeholder="Name, full phone or profile" /></label>
            {candidates.isFetching && <p role="status" className="text-sm text-slate-500">Searching permitted profiles…</p>}
            {candidates.isError && <p role="alert" className="text-sm text-rose-800">Search could not be loaded. <button type="button" className="underline" onClick={() => candidates.refetch()}>Retry</button></p>}
            <div className="flex flex-wrap gap-2">{candidates.data?.clients?.map(c => <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm" type="button" key={c.id} onClick={() => { setClient(c); setPrice(''); setSecondary(''); setClear(false); }}>{c.name} · Profile {c.wp_post_id}</button>)}</div>
            {client && <p className="text-sm font-semibold">Selected profile: {client.name}</p>}
            {context.isError && <p role="alert" className="text-sm text-rose-800">Exact purchase options could not be loaded. <button type="button" className="underline" onClick={() => context.refetch()}>Retry</button></p>}
            {client && <label className="block text-sm font-medium">Exact renewal package<select className="crm-input mt-1 w-full" value={price} onChange={e => setPrice(e.target.value)}><option value="">Choose package and duration</option>{context.data?.prices?.map(p => <option value={p.id} key={p.id}>{p.product.name} · {p.duration_days} days · {p.currency} {Number(p.price).toLocaleString()}</option>)}</select></label>}
            {context.data?.subsidiary_platform_id && <label className="block text-sm font-medium">Linked profile for the included exposure<select className="crm-input mt-1 w-full" value={secondary} onChange={e => setSecondary(e.target.value)}><option value="">Choose the verified linked profile</option>{context.data.subsidiary_clients?.map(c => <option value={c.id} key={c.id}>{c.name} · Profile {c.wp_post_id}</option>)}</select></label>}
            <div className="grid gap-3 sm:grid-cols-2"><label className="text-sm font-medium">Agreement valid until (your local time)<input type="datetime-local" className="crm-input mt-1 w-full" value={until} onChange={e => setUntil(e.target.value)} /></label><label className="text-sm font-medium">Maximum future renewals<input type="number" min="1" max="100" className="crm-input mt-1 w-full" value={renewals} onChange={e => setRenewals(e.target.value)} /></label></div>
            <label className="block text-sm font-medium">Reviewed purchase instruction<textarea rows={2} className="crm-input mt-1 w-full" value={reason} onChange={e => setReason(e.target.value)} /></label>
            {!!exclusions.length && <div className="space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-950"><p>A previous correction rejected this payer-to-profile match.</p>{exclusions.map(e => <p key={e.id}>{e.reason}</p>)}<label className="flex gap-2"><input type="checkbox" checked={clear} onChange={e => setClear(e.target.checked)} />I have freshly reviewed the identity and exact purchase above, and explicitly approve clearing these named exclusions.</label></div>}
            {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}
            <button type="button" className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={busy || !complete} onClick={save}>{busy ? 'Saving…' : 'Approve future agreement'}</button>
        </div>}
    </details>;
}
