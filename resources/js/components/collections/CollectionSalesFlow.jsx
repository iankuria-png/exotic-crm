import React, { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import CollectionSalesResult from './CollectionSalesResult';
import SubsidiaryTrialSection from '../clients/SubsidiaryTrialSection';

const button = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-teal-600 disabled:opacity-40';
const primary = `${button} !border-teal-700 !bg-teal-700 !text-white`;
const money = (minor, currency) => `${currency} ${Number(minor / 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
const explanations = {
    manual_activation_connection_required: 'An administrator must finish the activation connection for this receiving account and market. Your payment and allocations are saved.',
    saved_same_period_benefit_adapter_required: 'This saved purchase includes same-period subsidiary exposure. Its approved terms need the shared subsidiary connection before activation.',
};
const failureMessages = {
    receipt_version_changed: 'New payment details arrived. Refresh the saved version and review your selections.',
    sale_version_changed: 'Another staff member updated this sale. Refresh its saved version before continuing.',
    activation_preview_changed: 'The service or customer details changed. Review activation again before continuing.',
    refresh_activation_preview: 'This review expired. Review activation again before continuing.',
    refresh_subsidiary_preview: 'The subsidiary preview expired. Reopen its details to check the profile again.',
    subsidiary_sale_context_changed: 'The payment or selected profile changed. Refresh the subsidiary details before continuing.',
    existing_cash_requires_receipt_attachment: 'This reference is already recorded as a payment. A manager must verify and link the existing payment; do not record it again.',
    existing_quote_payment_requires_receipt_review: 'This quote already has payment or activation records. Check the existing subscription before continuing.',
    quote_already_activated_requires_review: 'This quote already activated a subscription. Choose a current unpaid quote or ask a manager to review it.',
    existing_subscription_payment_requires_review: 'This profile has another payment awaiting confirmation. Check that payment before activating this receipt.',
    prepared_purchase_terms_changed: 'The saved service terms changed. Ask a manager to review this sale before activation.',
    only_never_dispatched_sale_can_retry: 'This activation has already started. Check its saved outcome to avoid activating it twice.',
    manual_activation_connection_required: explanations.manual_activation_connection_required,
};
const failureMessage = error => failureMessages[error.response?.data?.reason] || error.response?.data?.message || 'Could not complete this step. Your selections are preserved.';
const labels = { draft: 'Not activated', prepared: 'Waiting to activate', dispatching: 'Checking activation', unknown: 'Needs checking', needs_review: 'Needs checking', confirmed: 'Active', partial: 'Partly active', activating: 'Activating', canceled: 'Canceled' };
function draft(row) {
    return { key: crypto.randomUUID(), client: row?.client || null, client_id: row?.client_id || null,
        product_price_id: row?.purchase?.product_price_id || row?.product_price_id || '',
        deal_id: row?.purchase?.source_deal_id || row?.deal_id || null,
        saved_intent_id: row?.purchase?.saved_intent_id || row?.saved_intent_id || null,
        subsidiary_client_id: row?.purchase?.subsidiary?.client_id || null, purchase: row?.purchase || null,
        replace_saved_benefit: Boolean(row?.purchase?.benefit_amendment), subscription_lifecycle: row?.purchase?.lifecycle?.subscription_lifecycle || '', subscription_lifecycle_reason: row?.purchase?.lifecycle?.subscription_lifecycle_reason || '',
        subsidiary_trial: row && 'subsidiary_trial' in row ? { ...(row.subsidiary_trial || { enabled: false }), request_key: crypto.randomUUID(), preview_token: '', pin: '' } : {}, subsidiary_ready: false };
}
export default function CollectionSalesFlow({ row, shared = false, onBack, onSaved }) {
    const { user } = useAuth();
    const [items, setItems] = useState([]);
    const [version, setVersion] = useState(0);
    const [leave, setLeave] = useState(false);
    const [reason, setReason] = useState('');
    const [preview, setPreview] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [handoff, setHandoff] = useState(null);
    const [summaryCopy, setSummaryCopy] = useState('');
    const [summaryFallback, setSummaryFallback] = useState('');
    const root = useRef(null);
    const initialized = useRef(false);
    const request = useRef({});
    const started = useRef(Date.now());
    const result = useQuery({ queryKey: ['collection-sales', user?.id, row.id], queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/sales`)).data,
        retry: false, staleTime: 0, refetchInterval: q => document.visibilityState === 'visible' && Date.now() - started.current < 90000 && ['prepared', 'activating'].includes(q.state.data?.session?.state) ? 5000 : false });
    const session = result.data?.session;
    const locked = session && session.state !== 'draft';
    useEffect(() => { if (preview || locked) root.current?.scrollIntoView({ block: 'start', behavior: 'auto' }); }, [Boolean(preview), locked]);
    useEffect(() => {
        if (!result.data || initialized.current) return;
        initialized.current = true;
        setVersion(session?.version || 0);
        const initial = session?.items?.length ? session.items.map(draft) : [draft(result.data.seed)];
        if (shared && initial.length === 1) initial.push(draft());
        setItems(initial); setLeave(Boolean(session?.remainder_minor)); setReason(session?.remainder_reason || '');
    }, [result.data]);
    function change(key, patch) {
        const current = items.find(i => i.key === key);
        const values = typeof patch === 'function' ? patch(current) : patch;
        if (!current || Object.entries(values).every(([k, v]) => current[k] === v)) return;
        setItems(rows => rows.map(i => i.key === key ? { ...i, ...(typeof patch === 'function' ? patch(i) : patch) } : i));
        setPreview(null); setError(''); setNotice('');
    }
    const total = items.reduce((sum, item) => sum + (item.purchase?.amount_minor || 0), 0);
    const remainder = row.principal_minor - total;
    const complete = items.length > 0 && items.every(i => i.client_id && i.product_price_id && i.purchase && (i.saved_intent_id && i.purchase.subsidiary && !i.replace_saved_benefit || i.subsidiary_ready)) && new Set(items.map(i => i.client_id)).size === items.length && remainder >= 0 && (!remainder || (leave && reason.trim().length >= 3));
    function body() {
        return { receipt_version: row.version, session_version: version, items: items.map(i => ({ client_id: i.client_id, product_price_id: Number(i.product_price_id),
            ...(i.deal_id ? { deal_id: Number(i.deal_id) } : {}), ...(i.saved_intent_id ? { saved_intent_id: i.saved_intent_id } : {}),
            ...(i.subsidiary_client_id ? { subsidiary_client_id: Number(i.subsidiary_client_id) } : {}), replace_saved_benefit: i.replace_saved_benefit, ...(i.subscription_lifecycle ? { subscription_lifecycle: i.subscription_lifecycle, subscription_lifecycle_reason: i.subscription_lifecycle_reason } : {}),
            ...(i.subsidiary_trial && typeof i.subsidiary_trial.enabled === 'boolean' ? { subsidiary_trial: { ...i.subsidiary_trial,
                ...(i.subsidiary_trial.platform_id ? { platform_id: Number(i.subsidiary_trial.platform_id) } : {}),
                ...(i.subsidiary_trial.duration_days ? { duration_days: Number(i.subsidiary_trial.duration_days) } : {}),
                client_id: Number(i.subsidiary_trial.client_id) || null, manual_client_id: Number(i.subsidiary_trial.manual_client_id) || null } } : {}) })),
        leave_remainder: leave, ...(remainder > 0 && leave ? { remainder_reason: reason.trim() } : {}) };
    }
    async function act(action) {
        setBusy(true); setError(''); setNotice('');
        try {
            const payload = body();
            if (action !== 'preview') {
                const digest = JSON.stringify({ action, ...payload });
                if (request.current.digest !== digest) request.current = { digest, key: crypto.randomUUID() };
                payload.request_key = request.current.key;
            }
            if (action === 'activate') payload.preview_token = preview.preview_token;
            const data = (await api.post(`/crm/collections/receipts/${row.id}/sales/${action}`, payload)).data;
            if (action === 'preview') setPreview(data);
            else {
                setVersion(data.session.version); setPreview(null);
                setNotice(action === 'save' ? 'Allocations saved. Review activation when you are ready.' : 'Activation started. You can close this receipt and check its progress later.');
                await result.refetch(); onSaved();
            }
        } catch (e) { setError(failureMessage(e)); }
        finally { setBusy(false); }
    }
    async function copyOfficeSummary() {
        const text = `${money(row.principal_minor, row.currency)} received\nReference: ${row.reference}\n${session.items.filter(i => i.state === 'confirmed').length} of ${session.items.length} subscriptions confirmed${session.remainder_minor ? `\nUnallocated: ${money(session.remainder_minor, session.currency)}` : ''}`;
        try { await navigator.clipboard.writeText(text); setSummaryCopy('Office summary copied'); setSummaryFallback(''); }
        catch { setSummaryFallback(text); setSummaryCopy(''); }
    }
    if (handoff) return <CollectionSalesResult dealId={handoff} onBack={() => setHandoff(null)} />;
    return <section ref={root} aria-label="Activate from received payment" className="scroll-mt-4 space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="text-lg font-semibold">{locked ? 'Subscription progress' : preview ? 'Review activations' : 'Allocate this payment'}</h3><p className="mt-1 text-xs text-slate-500">{locked ? 'Each profile keeps its own confirmed outcome.' : 'Payment → Profiles and services → Activate → Customer message'}</p></div><button type="button" className={button} onClick={onBack}>Back</button></div>
        {result.isPending && <p role="status">Loading saved allocations…</p>}
        {result.isError && <p role="alert" className="text-sm text-rose-800">Could not load the sales workflow. <button type="button" className="underline" onClick={() => result.refetch()}>Try again</button></p>}
        {locked ? <><div className="rounded-lg border border-teal-200 bg-teal-50 p-4"><p className="font-semibold">{labels[session.state] || 'Needs checking'}</p><p className="mt-1 text-sm">{session.items.filter(i => i.state === 'confirmed').length} of {session.items.length} subscriptions confirmed</p></div>{session.items.map(item => <section key={item.id} className="rounded-lg border border-slate-200 p-4"><div className="flex flex-wrap justify-between gap-2"><h4 className="font-semibold">{item.client.name}</h4><span className={`text-sm ${item.state === 'confirmed' ? 'text-teal-800' : 'text-amber-900'}`}>{labels[item.state] || 'Needs checking'}</span></div><p className="mt-1 text-sm">{item.purchase.tier.toUpperCase()} · {item.purchase.duration_days} days · {money(item.amount_minor, session.currency)}</p><div className="mt-3 flex flex-wrap gap-4"><Link className="text-sm font-medium text-teal-700 underline" to={`/clients/${item.client_id}?tab=deals`}>View subscription</Link>{item.state === 'confirmed' && item.deal_id && <button type="button" className="text-sm font-medium text-teal-700 underline" onClick={() => setHandoff(item.deal_id)}>Customer message and links</button>}</div>{['unknown', 'needs_review'].includes(item.state) && <SaleReviewAction session={session} item={item} reference={row.reference} onUpdated={() => { result.refetch(); onSaved(); }} />}{item.last_error_code && <p className="mt-2 text-xs text-amber-900">This activation needs checking. The payment remains attached to this receipt.</p>}</section>)}<button type="button" className={button} disabled={result.isFetching} onClick={() => result.refetch()}>{result.isFetching ? 'Checking…' : 'Check status'}</button><button type="button" className={button} onClick={copyOfficeSummary}>Copy office summary</button>{summaryCopy && <p role="status" className="text-sm text-teal-800">{summaryCopy}</p>}{summaryFallback && <label className="block text-sm">Select and copy the office summary<textarea className="crm-input mt-1 w-full" rows={4} readOnly value={summaryFallback} onFocus={e => e.target.select()} /></label>}</> : result.isSuccess && <>
            <div className="flex flex-wrap items-baseline justify-between gap-2 rounded-lg bg-slate-50 p-3"><span className="text-sm text-slate-600">Received · {row.reference}</span><strong>{money(row.principal_minor, row.currency)}</strong></div>
            {preview ? <div className="space-y-3">{preview.items.map(item => <div key={item.client_id} className="rounded-lg border border-slate-200 p-4"><h4 className="font-semibold">{item.client.name}</h4><p className="mt-1 text-sm">{item.purchase.tier.toUpperCase()} · {item.purchase.duration_days} days · {money(item.amount_minor, row.currency)}</p>{item.lifecycle && <p className="mt-2 text-xs text-slate-600">{item.lifecycle.subscription_lifecycle === 'renewal' ? 'Renewal' : 'New subscriber'} · {item.lifecycle.operator_overridden ? 'Selected by sales' : 'Based on this profile’s subscription history'}</p>}{item.subsidiary_trial && <p className="mt-2 text-sm text-teal-800">{item.subsidiary_trial.market_name || 'Subsidiary market'} · {item.subsidiary_trial.duration_days} day free trial · {item.subsidiary_trial.resolution_kind === 'already_active' ? 'Already active; no new grant' : 'Current approval policy applies'}</p>}{item.replaces_saved_benefit && <p className="mt-2 text-xs text-amber-900">Replaces the saved same-period benefit with the trial selection reviewed here.</p>}<p className="mt-2 text-xs text-slate-500">Uses this received payment. No additional charge.</p></div>)}{preview.blocked_reasons.map(code => <p key={code} className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{explanations[code] || 'An administrator must review this activation connection.'}</p>)}</div> : <>{items.map((item, index) => <AllocationRow key={item.key} row={row} item={item} index={index} duplicate={items.some(i => i.key !== item.key && i.client_id && i.client_id === item.client_id)} onChange={patch => change(item.key, patch)} onRemove={items.length > 1 ? () => { setItems(rows => rows.filter(i => i.key !== item.key)); setPreview(null); } : null} disabled={busy} />)}{items.length < 20 && <button type="button" className={button} disabled={busy} onClick={() => { setItems(rows => [...rows, draft()]); setPreview(null); }}>Add another profile</button>}</>}
            <div className={`rounded-lg border p-3 ${remainder < 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50'}`}><div className="flex flex-wrap justify-between gap-2 text-sm"><span>Allocated {money(total, row.currency)}</span><strong>{remainder === 0 ? 'Fully allocated' : remainder > 0 ? `${money(remainder, row.currency)} remaining` : `${money(-remainder, row.currency)} over received total`}</strong></div>{remainder > 0 && !preview && <div className="mt-3 space-y-2"><label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={leave} onChange={e => setLeave(e.target.checked)} />Leave remainder for Finance</label>{leave && <label className="block text-xs">Reason<input className="crm-input mt-1" value={reason} onChange={e => setReason(e.target.value)} maxLength={1000} /></label>}</div>}</div>
            <div className="sticky bottom-0 -mx-1 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white px-1 py-4"><span className="text-xs text-slate-500">{preview ? 'Check every profile before activating' : 'Not activated yet · No new charge'}</span><div className="flex flex-wrap gap-2">{preview ? <><button type="button" className={button} disabled={busy} onClick={() => setPreview(null)}>Change allocations</button><button type="button" className={primary} disabled={busy || !preview.can_activate} onClick={() => act('activate')}>{busy ? 'Starting…' : `Activate ${items.length === 1 ? 'subscription' : `${items.length} subscriptions`}`}</button></> : <><button type="button" className={button} disabled={!complete || busy || !result.data.storage_ready} onClick={() => act('save')}>Save allocations</button><button type="button" className={primary} disabled={!complete || busy || !result.data.storage_ready} onClick={() => act('preview')}>{busy ? 'Checking…' : 'Review activation'}</button></>}</div></div>
            {!result.data.storage_ready && <p role="status" className="text-sm text-amber-900">The sales workflow is being installed. Your receipt and purchase choice are saved.</p>}
        </>}
        {notice && <p role="status" className="rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{notice}</p>}
        {error && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{error} <button type="button" className="underline" onClick={async () => { const fresh = await result.refetch(); if (fresh.data?.session?.state === 'draft' || !fresh.data?.session) { setVersion(fresh.data?.session?.version || 0); setPreview(null); setNotice('Latest saved version loaded. Your selections are preserved; review them before continuing.'); } }}>Refresh saved version</button></p>}
    </section>;
}
function AllocationRow({ row, item, index, duplicate, onChange, onRemove, disabled }) {
    const { user } = useAuth();
    const [search, setSearch] = useState('');
    const [term, setTerm] = useState('');
    useEffect(() => { const timer = setTimeout(() => setTerm(search.trim()), 250); return () => clearTimeout(timer); }, [search]);
    const results = useQuery({ queryKey: ['collection-sale-search', user?.id, row.id, term], enabled: term.length >= 2,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/candidates`, { params: { search: term } })).data, retry: false });
    const options = useQuery({ queryKey: ['collection-sale-options', user?.id, row.id, item.client_id], enabled: !!item.client_id,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/candidates`, { params: { selected_client_id: item.client_id } })).data, retry: false });
    function price(value) {
        const quote = value.startsWith('quote:') ? options.data?.quotes.find(q => String(q.id) === value.slice(6)) : null;
        const id = quote ? quote.product_price_id : Number(value);
        const option = options.data?.prices.find(p => p.id === id);
        onChange({ product_price_id: id || '', deal_id: quote?.id || null, saved_intent_id: null,
            subsidiary_trial: {}, subsidiary_ready: false, purchase: option ? { product_price_id: id, tier: option.product.tier, duration_days: option.duration_days, currency: option.currency, amount_minor: Math.round(Number(quote?.amount || option.price) * 100) } : null });
    }
    return <fieldset disabled={disabled} className="space-y-3 rounded-lg border border-slate-200 p-4"><legend className="px-1 text-xs font-semibold text-slate-500">PROFILE {index + 1}</legend><div className="flex flex-wrap items-center justify-between gap-2">{item.client ? <h4 className="font-semibold">{item.client.name} <span className="text-xs font-normal text-slate-500">· Profile {item.client.wp_post_id}</span></h4> : <h4 className="text-sm font-medium">Choose a profile</h4>}{onRemove && <button type="button" className="text-xs font-medium text-slate-600 underline" onClick={onRemove}>Remove</button>}</div>
        <label className="block text-sm">{item.client ? 'Find a different profile' : 'Find client by name, phone or profile'}<input className="crm-input mt-1" value={search} onChange={e => setSearch(e.target.value)} placeholder="Name, full phone number or profile" /></label>
        {results.isFetching && <p role="status" className="text-xs">Searching…</p>}{results.isError && <p role="alert" className="text-sm text-rose-800">Search failed. <button type="button" className="underline" onClick={() => results.refetch()}>Try again</button></p>}
        {term.length >= 2 && results.data?.clients?.map(client => <button key={client.id} type="button" className="block w-full rounded-lg border border-slate-200 bg-white p-3 text-left text-sm focus-visible:ring-2 focus-visible:ring-teal-600" onClick={() => { onChange({ client, client_id: client.id, product_price_id: '', deal_id: null, saved_intent_id: null, subsidiary_client_id: null, purchase: null, replace_saved_benefit: false, subscription_lifecycle: '', subscription_lifecycle_reason: '', subsidiary_trial: {}, subsidiary_ready: false }); setSearch(''); setTerm(''); }}>{client.name} · Profile {client.wp_post_id}</button>)}
        {term.length >= 2 && results.isSuccess && !results.data?.clients?.length && <p className="text-xs text-slate-500">No matching profile. Try a different name or number.</p>}
        {duplicate && <p role="alert" className="text-sm text-rose-800">This profile is already included. Select another profile or remove this row.</p>}
        {item.client && <label className="block text-sm font-medium">Purchased service<select className="crm-input mt-1" value={item.deal_id ? `quote:${item.deal_id}` : item.product_price_id || ''} onChange={e => price(e.target.value)}><option value="">Choose exact package and duration</option>{item.saved_intent_id && item.purchase && <option value={item.deal_id ? `quote:${item.deal_id}` : item.product_price_id}>Saved purchase · {item.purchase.tier.toUpperCase()} · {item.purchase.duration_days} days · {money(item.purchase.amount_minor, row.currency)}</option>}{options.data?.quotes?.filter(q => q.status === 'pending' && (!item.saved_intent_id || q.id !== item.deal_id)).map(q => <option key={`q${q.id}`} value={`quote:${q.id}`}>Existing quote · {q.plan_type.toUpperCase()} · {q.duration_days} days · {q.currency} {Number(q.amount).toLocaleString()}</option>)}{options.data?.prices?.filter(p => !item.saved_intent_id || p.id !== Number(item.product_price_id)).map(p => <option value={p.id} key={p.id}>{p.product.name} · {p.duration_days} days · {p.currency} {Number(p.price).toLocaleString()}</option>)}</select></label>}
        {item.client && <div className="grid gap-3 sm:grid-cols-2"><label className="block text-xs text-slate-600">Subscriber type<select className="crm-input mt-1" value={item.subscription_lifecycle || ''} onChange={e => onChange({ subscription_lifecycle: e.target.value, subscription_lifecycle_reason: '' })}><option value="">Use subscription history</option><option value="new">New</option><option value="renewal">Renewal</option></select></label>{item.subscription_lifecycle && <label className="block text-xs text-slate-600">Reason for subscriber type<input className="crm-input mt-1" value={item.subscription_lifecycle_reason || ''} onChange={e => onChange({ subscription_lifecycle_reason: e.target.value })} maxLength={1000} /></label>}</div>}
        {options.isError && <p role="alert" className="text-sm text-rose-800">Packages could not be loaded. <button type="button" className="underline" onClick={() => options.refetch()}>Try again</button></p>}
        {item.saved_intent_id && item.purchase?.subsidiary && <label className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"><input type="checkbox" checked={item.replace_saved_benefit} onChange={e => onChange({ replace_saved_benefit: e.target.checked, subsidiary_trial: {}, subsidiary_ready: false })} /><span>Replace the saved same-period subsidiary benefit with the current trial options. Confirm the revised benefit terms with this customer before continuing.</span></label>}
        {item.client_id && item.product_price_id && (!item.saved_intent_id || !item.purchase?.subsidiary || item.replace_saved_benefit) && <SubsidiaryTrialSection endpointBase={`/crm/collections/receipts/${row.id}/sales`} requestContext={{ source_client_id: item.client_id, product_price_id: Number(item.product_price_id), receipt_version: row.version }} value={item.subsidiary_trial || {}} onChange={patch => onChange(current => ({ subsidiary_trial: { ...(current.subsidiary_trial || {}), ...patch } }))} onReadyChange={ready => onChange({ subsidiary_ready: ready })} disabled={disabled} />}
        {item.saved_intent_id && item.purchase?.subsidiary && !item.replace_saved_benefit && options.data?.subsidiary_platform_id && <div className="rounded-lg border border-teal-100 bg-teal-50/40 p-3"><label className="block text-sm font-medium">Linked subsidiary profile<select className="crm-input mt-1" value={item.subsidiary_client_id || ''} onChange={e => onChange({ subsidiary_client_id: Number(e.target.value) || null })}><option value="">Choose linked profile</option>{options.data.subsidiary_clients.map(c => <option key={c.id} value={c.id}>{c.name} · Profile {c.wp_post_id}</option>)}</select></label><p className="mt-2 text-xs text-slate-500">Saved same-period exposure keeps its original approved terms. The completed subsidiary workflow will handle its activation.</p></div>}
    </fieldset>;
}

function SaleReviewAction({ session, item, reference, onUpdated }) {
    const { user } = useAuth();
    const [reason, setReason] = useState('Check the saved activation outcome');
    const [evidence, setEvidence] = useState(reference);
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState('');
    const request = useRef({});
    if (!['admin', 'sub_admin'].includes(user?.role)) return <p className="mt-3 text-xs text-slate-500">A manager can check this saved activation. No second payment is needed.</p>;
    async function check() {
        setBusy(true); setStatus('');
        const input = { session_version: session.version, item_version: item.version, reason: reason.trim(), evidence_reference: evidence.trim() };
        const digest = JSON.stringify(input);
        if (request.current.digest !== digest) request.current = { digest, key: crypto.randomUUID() };
        try {
            const data = (await api.post(`/crm/collections/sales/${session.id}/items/${item.id}/reconcile`, { ...input, request_key: request.current.key })).data;
            setStatus(data.session.items.find(i => i.id === item.id)?.state === 'confirmed' ? 'Activation confirmed from the saved site operation.' : 'The outcome is still being checked. Its payment and profile remain reserved.');
            onUpdated();
        } catch (e) { setStatus(e.response?.data?.message || 'The site could not be checked. The saved operation is unchanged.'); }
        finally { setBusy(false); }
    }
    return <details className="mt-3 rounded-lg border border-amber-200 p-3"><summary className="cursor-pointer text-sm font-medium text-amber-900">Manager: check saved activation</summary><div className="mt-3 space-y-3"><p className="text-xs text-slate-500">Reads the original site operation. This action cannot start another activation or release an unknown outcome.</p><label className="block text-xs">Review reason<input className="crm-input mt-1" value={reason} onChange={e => setReason(e.target.value)} maxLength={1000} /></label><label className="block text-xs">Evidence reference<input className="crm-input mt-1" value={evidence} onChange={e => setEvidence(e.target.value)} maxLength={191} /></label><button type="button" className={button} disabled={busy || reason.trim().length < 3 || evidence.trim().length < 3} onClick={check}>{busy ? 'Checking saved operation…' : 'Check saved site operation'}</button>{status && <p role="status" className="text-sm text-slate-700">{status}</p>}</div></details>;
}
