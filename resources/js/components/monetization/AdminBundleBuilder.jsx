import React, { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';

const input = 'min-h-11 rounded-md border border-slate-300 bg-white px-3 text-sm';
const clock = s => { const n = Number(s) || 0; return n ? `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}` : ''; };
const money = (n, c) => `${c} ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** Equal share per distinct escort in minor units; remainder by ascending CRM client ID (mirrors PurchaseAllocationService). */
export function splitShares(amount, clientIds) {
    const ids = Array.from(new Set(clientIds.map(Number))).sort((a, b) => a - b), total = Math.round(Number(amount || 0) * 100);
    if (!ids.length) return [];
    const base = Math.floor(total / ids.length), remainder = total - base * ids.length;
    return ids.map((id, i) => ({ client_id: id, amount_minor: base + (i < remainder ? 1 : 0) }));
}

export default function AdminBundleBuilder({ platforms, defaultMarket, onClose, onPublished }) {
    const [market, setMarket] = useState(defaultMarket || platforms?.[0]?.id || ''), [search, setSearch] = useState(''), [term, setTerm] = useState('');
    const [selected, setSelected] = useState([]), [title, setTitle] = useState(''), [amount, setAmount] = useState(''), [touched, setTouched] = useState(false), [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [attempt] = useState(() => crypto.randomUUID());
    useEffect(() => { const timer = setTimeout(() => setTerm(search.trim()), 300); return () => clearTimeout(timer); }, [search]);
    useEffect(() => { setSelected([]); setAmount(''); setTouched(false); }, [market]);
    const candidates = useQuery({ queryKey: ['monetization-bundle-candidates', market, term], enabled: !!market, queryFn: async () => (await api.get('/crm/monetization/content/bundle-candidates', { params: { platform_id: market, q: term || undefined } })).data.items });
    const [known, setKnown] = useState({});
    useEffect(() => { if (candidates.data) setKnown(k => ({ ...k, ...Object.fromEntries(candidates.data.map(i => [i.public_id, i])) })); }, [candidates.data]);
    const chosen = selected.map(id => known[id]).filter(Boolean);
    const suggested = useMemo(() => Math.round(chosen.reduce((sum, i) => sum + Number(i.item_price || 0), 0)), [chosen]);
    useEffect(() => { if (!touched) setAmount(suggested ? String(suggested) : ''); }, [suggested, touched]);
    const creators = Array.from(new Map(chosen.map(i => [i.client_id, i.creator])).entries()).sort((a, b) => a[0] - b[0]);
    const shares = splitShares(amount, creators.map(([id]) => id));
    const currency = platforms?.find(p => String(p.id) === String(market))?.currency_code || '';
    const scope = creators.length > 1 ? 'multi_creator' : 'single_creator';
    const toggle = id => setSelected(list => list.includes(id) ? list.filter(x => x !== id) : [...list, id]);

    async function publish(e) {
        e.preventDefault(); setBusy(true); setMessage('');
        try {
            const { data } = await api.post('/crm/monetization/content/bundles', { platform_id: Number(market), title, asset_public_ids: selected, amount: Number(amount), scope, attempt });
            onPublished(`${data.scope === 'multi_creator' ? 'Exotic collection' : 'Same-escort bundle'} published · ${data.item_count} items · ${data.creator_count} escort${data.creator_count === 1 ? '' : 's'}.`);
        } catch (err) { setMessage(Object.values(err.response?.data?.errors || {}).flat()[0] || err.response?.data?.message || 'Could not publish. Nothing was changed.'); } finally { setBusy(false); }
    }

    return <form onSubmit={publish} className="space-y-4 rounded-lg border border-slate-200 bg-white p-5" aria-labelledby="bundle-builder-title">
        <div className="flex flex-wrap items-end justify-between gap-3"><div><h3 id="bundle-builder-title" className="text-lg font-semibold text-slate-950">Create content bundle</h3><p className="mt-1 text-sm text-slate-500">Ready protected items stay for sale individually; the bundle is an additional offer.</p></div><div className="flex flex-wrap items-end gap-2"><label className="text-xs font-medium text-slate-500">Market<select className={`${input} mt-1 block`} value={market} onChange={e => setMarket(e.target.value)}>{platforms?.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label><button type="button" className={input} onClick={onClose}>Close</button></div></div>
        <div className="grid gap-4 lg:grid-cols-[1.35fr_.65fr]">
            <div className="space-y-3">
                <label className="block text-xs font-medium text-slate-500">Find ready items<input className={`${input} mt-1 w-full`} value={search} onChange={e => setSearch(e.target.value)} placeholder="Escort name, phone or CRM client ID" /></label>
                {candidates.isPending && <p role="status" className="text-sm text-slate-500">Loading ready items…</p>}
                {candidates.data && !candidates.data.length && <p className="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No ready protected items match.</p>}
                <ul className="grid max-h-96 gap-2 overflow-auto sm:grid-cols-2">{(candidates.data || []).map(i => <li key={i.public_id}><label className={`flex min-h-11 items-center gap-3 rounded-md border p-2 text-sm ${selected.includes(i.public_id) ? 'border-slate-900 bg-slate-50' : 'border-slate-200'}`}><input type="checkbox" className="h-4 w-4 accent-slate-900" checked={selected.includes(i.public_id)} onChange={() => toggle(i.public_id)} /><img src={i.preview_url} alt="" className="h-11 w-11 rounded-md object-cover" /><span className="min-w-0 flex-1"><span className="block truncate font-medium text-slate-900">{i.creator}</span><span className="block text-xs text-slate-500">{i.media_type === 'video' ? `Video${i.duration_seconds ? ` · ${clock(i.duration_seconds)}` : ''}` : 'Photo'}{i.origin === 'expiry_automation' ? ' · moved at expiry' : ''}</span></span>{i.item_price && <span className="text-xs font-semibold text-slate-700">{money(i.item_price, currency)}</span>}</label></li>)}</ul>
            </div>
            <aside className="space-y-4 rounded-md border border-slate-200 p-4">
                <p className="text-sm text-slate-600">{chosen.length} item{chosen.length === 1 ? '' : 's'} · {creators.length} escort{creators.length === 1 ? '' : 's'} · <strong>{creators.length > 1 ? 'Exotic collection' : 'Same-escort bundle'}</strong></p>
                <label className="block text-sm">Bundle name<input required minLength={2} maxLength={120} className={`${input} mt-1 w-full`} value={title} onChange={e => setTitle(e.target.value)} /></label>
                <label className="block text-sm">Bundle price · {currency}<input required type="number" min="1" step="1" className={`${input} mt-1 w-full`} value={amount} onChange={e => { setAmount(e.target.value); setTouched(true); }} /><small className="text-xs text-slate-500">{suggested ? `Suggested from item prices: ${money(suggested, currency)}.` : 'Suggested from item prices once items are selected.'}</small></label>
                <div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Earnings · equal per escort</p>{shares.length ? <ul className="mt-2 divide-y divide-slate-100 text-sm">{shares.map(s => <li key={s.client_id} className="flex justify-between py-1.5"><span>{creators.find(([id]) => id === s.client_id)?.[1] || `#${s.client_id}`}</span><span className="font-semibold tabular-nums">{money(s.amount_minor / 100, currency)}</span></li>)}</ul> : <p className="mt-2 text-sm text-slate-500">Select items to preview shares.</p>}{shares.length > 1 && <p className="mt-2 text-xs text-slate-500">{shares.length} shares · totals exactly {money(shares.reduce((t, s) => t + s.amount_minor, 0) / 100, currency)}. Provider fees stay with Exotic.</p>}</div>
                <button disabled={busy || chosen.length < 2 || !title || !amount} className="min-h-11 w-full rounded-md bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Publishing…' : 'Publish bundle'}</button>
                <p role="status" aria-live="polite" className="text-sm text-slate-700">{message}</p>
            </aside>
        </div>
    </form>;
}
