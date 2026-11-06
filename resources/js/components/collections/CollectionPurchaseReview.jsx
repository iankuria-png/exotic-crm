import React, { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';

const button = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 disabled:opacity-40';
const money = value => `${value.currency} ${Number((value.amount_minor ?? value.principal_minor ?? 0) / 100).toLocaleString()}`;
const message = error => error?.response?.data?.message || 'Could not confirm this choice. Your selections are preserved; please try again.';
const blockers = {
    account_entitlement_writes_disabled: 'This account is capturing payments only. Saving a choice leaves service unchanged.',
    exact_site_writer_acceptance_required: 'Service activation is awaiting site acceptance. You can save the purchase choice.',
    subsidiary_policy_grant_required: 'Linked-site activation needs a manager’s policy review. You can save the purchase choice.',
    linked_subsidiary_profile_required: 'Select the linked Tanzania profile before continuing.',
};

function useSearch(value) {
    const [term, setTerm] = useState(value);
    useEffect(() => { const timer = setTimeout(() => setTerm(value.trim()), 250); return () => clearTimeout(timer); }, [value]);
    return term;
}

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
    const [client, setClient] = useState(null);
    const [choice, setChoice] = useState('');
    const [subsidiaryId, setSubsidiaryId] = useState('');
    const [search, setSearch] = useState('');
    const [subsidiarySearch, setSubsidiarySearch] = useState('');
    const [changing, setChanging] = useState(false);
    const [manual, setManual] = useState(false);
    const [remember, setRemember] = useState(false);
    const [replace, setReplace] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [status, setStatus] = useState('');
    const [version, setVersion] = useState(null);
    const [historyPages, setHistoryPages] = useState([]);
    const [nextCursor, setNextCursor] = useState(null);
    const [loadingHistory, setLoadingHistory] = useState(false);
    const initialized = useRef(false);
    const request = useRef({ key: crypto.randomUUID(), body: null });
    const term = useSearch(search);
    const subsidiaryTerm = useSearch(subsidiarySearch);
    const context = useQuery({ queryKey: ['collection-review-context', user?.id, row.id, row.version],
        queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/review-context`)).data, refetchOnWindowFocus: false, retry: false });
    const data = context.data;
    useEffect(() => {
        if (!data || initialized.current) return;
        initialized.current = true;
        setVersion(data.receipt_version);
        if (data.recommended_client_id && data.selected_client) {
            setClient(data.selected_client);
            if (data.recommended_intent) {
                setChoice(`intent:${data.recommended_intent.id}`);
                setSubsidiaryId(String(data.recommended_intent.purchase.subsidiary?.client_id || ''));
            }
        } else setChanging(true);
    }, [data]);
    useEffect(() => { setHistoryPages([]); setNextCursor(data?.history?.next_cursor || null); }, [data]);
    const optionsQuery = useQuery({ queryKey: ['collection-review-options', user?.id, row.id, client?.id], enabled: !!client,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/candidates`, { params: { selected_client_id: client.id } })).data,
        refetchOnWindowFocus: false });
    const options = optionsQuery.data || (data?.selected_client?.id === client?.id ? data : null);
    useEffect(() => {
        if (!subsidiaryId && options?.subsidiary_clients?.length === 1) setSubsidiaryId(String(options.subsidiary_clients[0].id));
    }, [options, subsidiaryId]);
    const searchQuery = useQuery({ queryKey: ['collection-review-search', user?.id, row.id, term], enabled: changing && term.length >= 2,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/candidates`, { params: { search: term } })).data, retry: false });
    const subsidiaryQuery = useQuery({ queryKey: ['collection-review-subsidiary', user?.id, row.id, client?.id, subsidiaryTerm],
        enabled: !!client && !!options?.subsidiary_platform_id && subsidiaryTerm.length >= 2,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/candidates`, { params: { search: subsidiaryTerm, subsidiary_for_client_id: client.id } })).data, retry: false });
    const intent = choice.startsWith('intent:') && data?.recommended_intent?.id === choice.slice(7) ? data.recommended_intent : null;
    const quote = choice.startsWith('quote:') ? options?.quotes?.find(q => String(q.id) === choice.slice(6)) : null;
    const price = choice.startsWith('price:') ? options?.prices?.find(p => String(p.id) === choice.slice(6)) : null;
    const packageId = intent?.purchase.product_price_id || quote?.product_price_id || price?.id;
    const ready = !!client && !!packageId && (!options?.subsidiary_platform_id || !!subsidiaryId);
    const selection = { selected_client_id: client?.id, intent_id: intent?.id, product_price_id: intent ? undefined : packageId,
        deal_id: quote?.id, subsidiary_client_id: subsidiaryId ? Number(subsidiaryId) : undefined };
    const preview = useQuery({ queryKey: ['collection-review-preview', user?.id, row.id, row.version, client?.id, choice, subsidiaryId], enabled: ready,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/review-context`, { params: selection })).data,
        refetchOnWindowFocus: false, retry: false });
    const stale = version !== null && version !== row.version;
    const suggestion = !manual ? data?.suggestions?.find(c => c.id === client?.id) : null;
    const candidates = [...(data?.suggestions || []), ...(searchQuery.data?.clients || [])].filter((c, i, all) => all.findIndex(x => x.id === c.id) === i);
    const subsidiaries = [...(options?.subsidiary_clients || []), ...(subsidiaryQuery.data?.clients || [])].filter((c, i, all) => all.findIndex(x => x.id === c.id) === i);
    function chooseClient(value) {
        setClient(value); setManual(true); setChoice(''); setSubsidiaryId(''); setSubsidiarySearch(''); setChanging(false); setRemember(false); setReplace(false); setError(''); setStatus('');
    }
    async function refreshEvidence() {
        const result = await context.refetch();
        if (result.data && !result.error) { setVersion(result.data.receipt_version); setError(''); setStatus('Latest evidence loaded. Check your selections before confirming.'); }
    }
    async function submit(event) {
        event.preventDefault();
        if (!ready || stale || busy || context.isError) return;
        setBusy(true); setError(''); setStatus('');
        try {
            const fresh = (await api.get(`/crm/collections/receipts/${row.id}/review-context`, { params: selection })).data;
            if (fresh.receipt_version !== version) throw new Error('The receipt changed. Load the latest evidence and check your selections.');
            if (!manual && fresh.recommended_client_id !== client.id) {
                setChanging(true);
                throw new Error('The matching evidence changed. Select the intended client explicitly before continuing.');
            }
            let savedIntent = intent?.id;
            let savedVersion = version;
            if (!savedIntent || remember) {
                const body = { client_id: client.id, product_price_id: packageId, deal_id: intent?.deal_id || quote?.id,
                    subsidiary_client_id: subsidiaryId ? Number(subsidiaryId) : undefined, remember_payer: remember,
                    replace_saved_choice: replace, expected_version: version };
                const fingerprint = JSON.stringify(body);
                if (request.current.body !== fingerprint) request.current = { key: crypto.randomUUID(), body: fingerprint };
                const result = (await api.post(`/crm/collections/receipts/${row.id}/intents`, { ...body, request_key: request.current.key })).data;
                savedIntent = result.intent.id; savedVersion = result.receipt_version;
                setVersion(savedVersion);
            }
            if (fresh.can_activate) {
                await api.post(`/crm/collections/receipts/${row.id}/approve`, { intent_id: savedIntent, expected_version: savedVersion });
                setStatus('Purchase confirmed. Checking service activation…');
            } else setStatus('Purchase choice saved. Service remains unchanged; activation is pending.');
            onSaved();
        } catch (e) { setError(e.response ? message(e) : e.message); onSaved(); }
        finally { setBusy(false); }
    }
    const history = data?.history;
    const historyItems = [...(history?.items || []), ...historyPages].filter((item, i, all) => all.findIndex(x => x.key === item.key) === i);
    return <section aria-label="Review purchase" className="space-y-5">
        <section aria-label="Payment history" className="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
            {context.isPending && <p role="status" className="text-sm text-slate-500">Checking earlier payments and client matches…</p>}
            {context.isError && <p role="alert" className="text-sm text-rose-800">Could not load payment history and suggestions. <button type="button" className="underline" onClick={() => context.refetch()}>Retry</button></p>}
            {history && <><h3 className="font-semibold text-slate-900">{history.prior_count === null ? 'Payment history unavailable' : `${history.complete ? '' : 'At least '}${history.prior_count} prior ${history.prior_count === 1 ? 'payment' : 'payments'} using this number`}</h3>
                <p className="mt-1 text-xs leading-relaxed text-slate-500">{history.prior_count === null ? 'A full payer phone is needed to count earlier payments.' : `${history.crm_count} recorded in CRM · ${history.observed_only_count} additional captured ${history.observed_only_count === 1 ? 'receipt' : 'receipts'} · Current payment excluded`}</p>
                {!history.complete && history.prior_count !== null && <p className="mt-2 text-xs text-amber-800">Coverage is incomplete. Counts are lower bounds; choose the client explicitly.</p>}
                {history.prior_count !== null && <details className="mt-3 text-sm"><summary className="cursor-pointer font-medium text-teal-700">View payment history</summary><p className="my-2 text-xs text-slate-500">{history.scope?.label}. {history.complete ? 'Earlier records available in CRM and captured receipts.' : 'Matches in scanned records.'} Checked {new Date(history.as_of).toLocaleTimeString()}.</p>
                    {historyItems.length ? <ul className="divide-y divide-slate-200">{historyItems.map(item => <li key={item.key} className="flex flex-wrap justify-between gap-2 py-3"><span>{money(item)}<span className="mt-1 block text-xs text-slate-500">{item.source === 'crm' ? 'Recorded in CRM' : 'Observed on receiving phone'}</span></span><span className="text-right text-xs text-slate-500">{new Date(item.at).toLocaleDateString()}<span className="mt-1 block select-all font-mono">{item.reference || 'Reference unavailable'}</span></span></li>)}</ul> : <p className="py-2 text-xs text-slate-500">No earlier payments found in the available records.</p>}
                    {nextCursor && <button type="button" className="mt-2 text-sm font-medium text-teal-700 underline" disabled={loadingHistory} onClick={async () => { setLoadingHistory(true); try { const next = (await api.get(`/crm/collections/receipts/${row.id}/review-context`, { params: { cursor: nextCursor } })).data.history; setHistoryPages(pages => [...pages, ...next.items]); setNextCursor(next.next_cursor); } catch { setError('Could not load more history. Try again.'); } finally { setLoadingHistory(false); } }}>{loadingHistory ? 'Loading…' : 'Show more payments'}</button>}
                </details>}</>}
        </section>
        <form onSubmit={submit} className="space-y-4" aria-label="Confirm purchased service">
            <div><h3 className="text-base font-semibold">Confirm the purchase</h3><p className="mt-1 text-sm text-slate-500">Check the advertiser profile and the exact service purchased.</p></div>
            {data?.needs_choice && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Choose the intended profile. The evidence is shared, conflicting or incomplete, so no client has been preselected.</p>}
            {client && <div className={`rounded-xl border p-4 ${suggestion ? 'border-teal-200 bg-teal-50/60' : 'border-slate-200 bg-slate-50/60'}`}>
                <div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-semibold">{client.name}</p><p className="mt-1 text-xs text-slate-500">Profile {client.wp_post_id} · {manual ? 'Selected by you' : 'Suggested client'}</p></div>
                    {suggestion && <span className="rounded-md bg-white px-2 py-1 text-xs font-semibold text-teal-800">{suggestion.score} / 100 · {suggestion.score >= 90 ? 'High' : 'Medium'}</span>}</div>
                {suggestion && <><ul className="mt-3 space-y-1 text-sm text-slate-600">{suggestion.reasons.map(reason => <li key={reason}>{reason}</li>)}</ul><p className="mt-2 text-xs text-slate-500">Client match confidence · Rule score, not a measured probability</p></>}
                <button type="button" className="mt-3 text-sm font-medium text-teal-700 underline" onClick={() => setChanging(c => !c)}>Change client</button>
            </div>}
            {(changing || !client) && <div className="space-y-3 rounded-xl border border-slate-200 p-4"><label className="block text-sm font-medium">Find a client<input className="crm-input mt-1" placeholder="Name, profile or full phone number" value={search} onChange={e => setSearch(e.target.value)} /></label>
                {searchQuery.isFetching && <p role="status" className="text-xs text-slate-500">Searching permitted profiles…</p>}
                {searchQuery.isError && <p role="alert" className="text-sm text-rose-700">Client search failed. <button type="button" className="underline" onClick={() => searchQuery.refetch()}>Retry search</button></p>}
                {candidates.map(c => <button type="button" key={c.id} aria-label={`Select ${c.name}, profile ${c.wp_post_id}`} className="block w-full rounded-lg border border-slate-200 p-3 text-left hover:border-teal-400 focus-visible:ring-2 focus-visible:ring-teal-600" onClick={() => chooseClient(c)}><span className="font-medium">{c.name}</span><span className="ml-2 text-xs text-slate-500">Profile {c.wp_post_id}</span>{c.score !== undefined && <span className="mt-1 block text-xs text-slate-500">{c.score} / 100 · {c.reasons?.[0]}</span>}</button>)}
                {term.length >= 2 && searchQuery.isSuccess && !searchQuery.data?.clients?.length && <p className="text-sm text-slate-500">No matching profile found. Try a different name or phone number.</p>}
                {!candidates.length && term.length < 2 && <p className="text-xs text-slate-500">Enter at least two characters to search.</p>}
            </div>}
            {client && <><label className="block text-sm font-medium">Purchased service<select required className="crm-input mt-1" value={choice} onChange={e => { setChoice(e.target.value); setReplace(false); setError(''); setStatus(''); }}><option value="">Select exact package and duration</option>
                {data?.recommended_intent && data.recommended_intent.purchase.client_id === client.id && <option value={`intent:${data.recommended_intent.id}`}>Reviewed purchase · {data.recommended_intent.purchase.tier.toUpperCase()} · {data.recommended_intent.purchase.duration_days} days · {money(data.recommended_intent.purchase)}</option>}
                {options?.quotes?.map(q => <option key={`q${q.id}`} value={`quote:${q.id}`}>Existing quote · {q.plan_type.toUpperCase()} · {q.duration_days} days · {q.currency} {Number(q.amount).toLocaleString()}{q.discount_source ? ' · Negotiated' : ''}</option>)}
                {options?.prices?.map(p => <option key={p.id} value={`price:${p.id}`}>{p.product.name} · {p.duration_days} days · {p.currency} {Number(p.price).toLocaleString()}</option>)}
            </select></label>
                {optionsQuery.isFetching && <p role="status" className="text-xs text-slate-500">Loading this client’s service options…</p>}
                {optionsQuery.isError && <p role="alert" className="text-sm text-rose-800">Could not load packages. <button type="button" className="underline" onClick={() => optionsQuery.refetch()}>Retry packages</button></p>}
                <p className="text-xs leading-relaxed text-slate-500">The amount alone does not establish the package or duration. New negotiated terms need an approved quote in Client Detail.</p>
                {options?.subsidiary_platform_id && <div className="space-y-2 rounded-lg border border-slate-200 p-3"><label className="block text-sm font-medium">Linked Tanzania profile<select required className="crm-input mt-1" value={subsidiaryId} onChange={e => setSubsidiaryId(e.target.value)}><option value="">Select the linked profile</option>{subsidiaries.map(c => <option key={c.id} value={c.id}>{c.name} · Profile {c.wp_post_id}</option>)}</select></label><label className="block text-xs text-slate-500">Find a different linked profile<input className="crm-input mt-1" value={subsidiarySearch} onChange={e => setSubsidiarySearch(e.target.value)} placeholder="Name, profile or full phone number" /></label>{subsidiaryQuery.isFetching && <p role="status" className="text-xs text-slate-500">Searching linked profiles…</p>}{subsidiaryQuery.isError && <p role="alert" className="text-xs text-rose-800">Linked profile search failed. <button type="button" className="underline" onClick={() => subsidiaryQuery.refetch()}>Retry</button></p>}<p className="text-xs text-slate-500">This free exposure is linked to the same purchase; it is not a second payment.</p></div>}
                <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={remember} disabled={!row.payer?.phone} onChange={e => setRemember(e.target.checked)} />Remember this payer for this profile after review</label>
                {data?.saved_choice && (!intent || remember) && <label className="flex items-start gap-2 text-sm"><input type="checkbox" required className="mt-1" checked={replace} onChange={e => setReplace(e.target.checked)} />Replace the previously saved purchase choice</label>}
            </>}
            {data?.saved_choice && <p className="text-xs text-slate-500">Saved choice: {data.saved_choice.client.name} · {data.saved_choice.purchase.tier.toUpperCase()} · {data.saved_choice.purchase.duration_days} days{!data.saved_choice.valid ? ' · Expired or needs review' : ' · Activation pending'}</p>}
            {(data?.capture_only || (ready && preview.data && !preview.data.can_activate)) && <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"><p className="font-medium">{data?.capture_only ? 'Capture only · Service activation unavailable' : 'Service activation unavailable'}</p><p className="mt-1">{data?.capture_only ? 'Save the client and purchase choice for review. Service will remain unchanged.' : (preview.data?.blocked_reasons || []).map(reason => blockers[reason] || reason.replaceAll('_', ' ')).join(' ')}</p></div>}
            {ready && preview.isFetching && <p role="status" className="text-xs text-slate-500">Checking this purchase…</p>}
            {preview.isError && <p role="alert" className="text-sm text-rose-800">{message(preview.error)} <button type="button" className="underline" onClick={() => preview.refetch()}>Check again</button></p>}
            {stale && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">New receipt evidence is available. Your selections are preserved. <button type="button" className="font-medium underline" onClick={refreshEvidence}>Load latest evidence</button></p>}
            {error && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{error} <button type="button" className="underline" onClick={refreshEvidence}>Refresh evidence</button></p>}
            {status && <p role="status" className="rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{status}</p>}
            <button type="submit" className={`${button} !border-teal-700 !bg-teal-700 !text-white`} disabled={!ready || busy || stale || context.isError || preview.isFetching || preview.isError || !preview.data || (!!data?.saved_choice && !intent && !replace)}>{busy ? 'Confirming…' : preview.data?.can_activate ? 'Confirm client and service' : 'Save purchase choice'}</button>
            <p className="text-xs text-slate-500">This review does not authorize automatic future renewals.</p>
        </form>
    </section>;
}
