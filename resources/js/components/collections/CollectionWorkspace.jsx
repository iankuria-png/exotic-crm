import React, { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import CollectionDeviceHealthPanel from './CollectionDeviceHealthPanel';
import CollectionPolicyPanel from './CollectionPolicyPanel';
import CollectionPurchaseReview, { CollectionPayerSummary } from './CollectionPurchaseReview';
import CollectionMessageCopy from './CollectionMessageCopy';

const states = { purchase_saved: 'Choice saved · Review activation', sales_draft: 'Allocations saved · Review activation', sales_prepared: 'Waiting to activate', sales_activating: 'Activating subscriptions', sales_partial: 'Partly active', sales_confirmed: 'Active · Confirmed', sales_needs_review: 'Sale needs checking', collecting: 'Waiting for payment details', historical: 'Historical · no activation', ready_auto: 'Ready for automatic activation', dispatching: 'Activation processing', active_review_pending: 'Active · Review needed', reviewed: 'Reviewed', needs_review: 'Needs a sales decision', uncertain: 'Checking site confirmation', correcting: 'Correction in progress', unassigned: 'Money received · Unassigned', dependent_pending: 'Active · Tanzania exposure pending' };
const reasons = {
    later_paid_coverage_requires_recovery: 'A later paid purchase needs a reviewed recovery. Existing service stays unchanged.',
    primary_paid: 'Paid subscription', verification_only: 'Existing paid subscription', target_paid: 'Corrected subscription',
    prepared: 'Waiting to sync', dispatching: 'Syncing with the site', effect_unknown: 'Waiting for site confirmation', wp_verified: 'Site confirmed · Updating records',
    receiving_device_offline_or_unbound: 'The receiving phone is offline or its SIM binding needs review.', account_has_uncertain_operation: 'Another activation on this account needs site confirmation.',
    reviewed_payer_and_purchase_authority: 'Reviewed payer and authorized purchase match',
    reviewed_standing_renewal: 'Matches the reviewed renewal agreement', reviewed_current_quote: 'Matches the current reviewed quote',
    multiple_purchase_candidates: 'More than one purchase could match. Choose the intended profile and package.',
    reviewed_purchase_authority_required: 'Confirm the profile and purchased package.', reviewed_unique_payer_required: 'This payer is new, shared or needs a current review.',
    historical_receipt_no_dispatch: 'Archive evidence is available for reconciliation. It cannot activate service.',
    automatic_exposure_limit: 'The automatic allowance is full. A sales decision is needed.',
    automatic_account_acceptance_required: 'Automatic activation has not been released for this receiving account.',
    exact_site_writer_acceptance_required: 'Site activation is awaiting acceptance checks.', account_entitlement_writes_disabled: 'This receiving account is capturing evidence only.',
    wordpress_compensation_v2_required: 'This site must support verified entitlement corrections before this change can run.',
    correction_duration_policy_required: 'The correction duration policy must be approved before reassignment.',
    consumed_or_paid_benefit_requires_review: 'A pass, rebate or paid commission needs a separate reviewed correction.',
    legacy_destination_unverified: 'An existing payment has this reference. Verify its receiving destination before linking.',
    mixed_tier_coverage_not_representable: 'The current higher or different tier needs a reviewed coverage decision.',
};
const explain = value => reasons[value] || String(value || '').replaceAll('_', ' ');
const money = row => `${row.currency || ''} ${Number((row.principal_minor ?? row.amount_minor ?? 0) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
const poll = () => document.visibilityState === 'visible' ? 10000 : false;
const button = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 disabled:opacity-40';

export function useCollectionCapabilities() {
    const { user } = useAuth();
    return useQuery({ queryKey: ['collection-capabilities', user?.id], queryFn: async () => (await api.get('/crm/collections/capabilities')).data,
        enabled: ['admin', 'sub_admin', 'sales'].includes(user?.role), staleTime: 300000, retry: false });
}

export function CollectionArrivalBadge() {
    const { user } = useAuth();
    const capabilities = useCollectionCapabilities();
    const client = useQueryClient();
    const arrivals = useQuery({ queryKey: ['collection-arrivals', user?.id], enabled: !!capabilities.data?.enabled,
        queryFn: async () => (await api.get('/crm/collections/arrivals')).data, refetchInterval: poll, refetchOnWindowFocus: true });
    if (!capabilities.data?.enabled) return null;
    return <Link to="/payments?workspace=collections" className={button} onClick={() => {
        if (arrivals.data?.cursor) api.post('/crm/collections/arrivals/read', { cursor: arrivals.data.cursor }).then(() => client.invalidateQueries({ queryKey: ['collection-arrivals'] })).catch(() => {});
    }} aria-label={`Incoming payments: ${arrivals.data?.unread_receipts || 0} unread${arrivals.isError ? ', status unavailable' : arrivals.data?.device_needs_attention ? ', receiving phone needs attention' : ''}`}>
        Incoming payments <span className="ml-1 rounded bg-teal-50 px-1.5 py-0.5 text-teal-800">{arrivals.isError ? 'Status unavailable' : arrivals.data?.unread_receipts || 0}</span>
        {!!arrivals.data?.device_needs_attention && <span className="ml-1 whitespace-nowrap rounded bg-amber-100 px-1.5 py-0.5 text-amber-950">Phone needs attention</span>}
    </Link>;
}

export default function CollectionWorkspace({ platformId, clientId }) {
    const { user } = useAuth();
    const capabilities = useCollectionCapabilities();
    const [state, setState] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [searchParams, setSearchParams] = useSearchParams();
    const requestedReceipt = Number(searchParams.get('receipt'));
    const [selected, setSelected] = useState(Number.isSafeInteger(requestedReceipt) && requestedReceipt > 0 ? requestedReceipt : null);
    useEffect(() => { setSelected(Number.isSafeInteger(requestedReceipt) && requestedReceipt > 0 ? requestedReceipt : null); }, [requestedReceipt]);
    function selectReceipt(id) { setSelected(id); const next = new URLSearchParams(searchParams); if (id) next.set('receipt', String(id)); else next.delete('receipt'); setSearchParams(next, { replace: true }); }
    const [exportError, setExportError] = useState('');
    const params = { state: state || undefined, search: search || undefined, platform_id: platformId || undefined, client_id: clientId || undefined, page };
    const rows = useQuery({ queryKey: ['collection-receipts', user?.id, params], enabled: !!capabilities.data?.enabled,
        queryFn: async () => (await api.get('/crm/collections/receipts', { params })).data, refetchInterval: poll, refetchOnWindowFocus: true });
    if (!capabilities.data?.enabled) return null;
    async function exportReceipts() {
        try {
            const { data } = await api.get('/crm/collections/receipts', { params: { ...params, export: 1 }, responseType: 'blob' });
            const url = URL.createObjectURL(data); const link = document.createElement('a'); link.href = url; link.download = 'collection-receipts.csv'; link.click(); URL.revokeObjectURL(url); setExportError('');
        } catch { setExportError('Export failed. Please try again.'); }
    }
    return <section aria-label="Incoming office payments" className="crm-surface p-4 sm:p-5">
        <CollectionDeviceHealthPanel platformId={platformId} />
        {!clientId && <CollectionPolicyPanel />}
        <div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-base font-semibold text-slate-900">Incoming office payments</h2><p className="mt-1 max-w-2xl text-sm text-slate-500">Receiving messages, purchased service and sales review in one place.</p></div><button type="button" className={button} onClick={exportReceipts}>Export receipts</button></div>
        <div className="my-5 flex flex-wrap gap-3"><label className="text-sm text-slate-600">Review state<select className="crm-input mt-1" value={state} onChange={e => { setState(e.target.value); setPage(1); }}><option value="">All receipts</option>{Object.entries(states).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label><label className="text-sm text-slate-600">Receipt reference<input className="crm-input mt-1" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} placeholder="Search full or partial reference" /></label></div>
        {(rows.isError || exportError) && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{exportError || 'Incoming payments could not be refreshed. The last loaded rows may be out of date.'} <button type="button" className="underline" onClick={() => rows.refetch()}>Retry</button></p>}
        {rows.isPending ? <p role="status" className="py-8 text-sm text-slate-500">Loading receiving receipts…</p> : !rows.data?.data?.length ? <p className="py-8 text-sm text-slate-500">No incoming receipts match this view. New receiving messages will appear here.</p> : <div className="overflow-x-auto"><table className="w-full min-w-[640px] text-left text-sm"><thead className="border-b border-slate-200 text-xs text-slate-500"><tr>{['Received', 'Payer and profile', 'Subscription', 'Review'].map(x => <th key={x} className="px-3 py-3 font-medium">{x}</th>)}</tr></thead><tbody>{rows.data.data.map(row => <tr key={row.id} className="border-b border-slate-100 align-top"><td className="px-3 py-4"><p className="font-semibold text-slate-900">{money(row)}</p><p className="mt-1 text-xs text-slate-500">{row.account_label}</p><p className="mt-1 text-xs text-slate-500">{row.evidence_level === 'statement' ? 'Verified on statement' : 'Observed on receiving phone'}</p></td><td className="px-3 py-4">{row.clients?.length > 1 ? `${row.clients.length} profiles · Shared payment` : row.clients?.[0]?.name || 'Profile needs confirmation'}<p className="mt-1 break-words text-sm font-medium text-slate-800">{row.payer?.name || (row.payer?.name_state === 'conflicting' ? 'Payer names need review' : 'Name not supplied in SMS')}</p><p className="mt-1 select-all text-xs tabular-nums text-slate-600">{row.payer?.phone || 'Phone not supplied in SMS'}</p>{row.captured_payer_receipts !== null && row.captured_payer_receipts !== undefined && <p className="mt-1 text-xs text-slate-500">{row.captured_payer_receipts} captured {row.captured_payer_receipts === 1 ? 'receipt' : 'receipts'} using this number</p>}{row.purchase && <p className="mt-1 text-xs text-slate-500">{row.purchase.tier.toUpperCase()} · {row.purchase.duration_days} days</p>}</td><td className="px-3 py-4"><p className={row.decision_state === 'active_review_pending' ? 'font-medium text-teal-800' : 'text-slate-700'}>{states[row.decision_state] || explain(row.decision_state)}</p>{row.allocation?.decision_source === 'automatic' && ['active_review_pending', 'reviewed'].includes(row.decision_state) && <p className="mt-1 text-xs text-slate-500">Auto-activated</p>}{row.historical_only && <p className="mt-1 text-xs text-slate-500">Reconciliation only</p>}</td><td className="px-3 py-4"><button type="button" className={button} onClick={() => selectReceipt(row.id)}>{row.reviewed_at ? 'View receipt' : 'Review receipt'}</button></td></tr>)}</tbody></table></div>}
        <div className="mt-4 flex items-center justify-between gap-3 text-xs text-slate-500"><span>{rows.data?.total || 0} receipts · {rows.dataUpdatedAt ? `Updated ${new Date(rows.dataUpdatedAt).toLocaleTimeString()}` : 'Waiting for updates'}</span><div className="flex gap-3"><button type="button" disabled={page === 1} onClick={() => setPage(p => p - 1)}>Previous</button><span>Page {page}</span><button type="button" disabled={!rows.data?.next_page_url} onClick={() => setPage(p => p + 1)}>Next</button></div></div>
        {selected && <CollectionReceiptDrawer receiptId={selected} onClose={() => selectReceipt(null)} />}
    </section>;
}

export function CollectionReceiptDrawer({ receiptId, onClose }) {
    const { user } = useAuth();
    const dialog = useRef(null);
    const queryClient = useQueryClient();
    const [mode, setMode] = useState(null);
    const [error, setError] = useState('');
    const [preview, setPreview] = useState(null);
    const executeKey = useRef(null);
    const detail = useQuery({ queryKey: ['collection-receipt', user?.id, receiptId], queryFn: async () => (await api.get(`/crm/collections/receipts/${receiptId}`)).data, refetchInterval: poll, refetchOnWindowFocus: true });
    useEffect(() => {
        const trigger = document.activeElement;
        const modal = dialog.current;
        modal?.showModal();
        return () => { modal?.close(); if (trigger?.isConnected) trigger.focus(); };
    }, []);
    const refresh = () => { queryClient.invalidateQueries({ queryKey: ['collection-receipt', user?.id, receiptId] }); queryClient.invalidateQueries({ queryKey: ['collection-receipts'] }); };
    const action = useMutation({ mutationFn: async ({ path, body }) => (await api.post(path, body)).data, onSuccess: () => { setError(''); refresh(); }, onError: e => { setError(e.response?.data?.message || 'The action could not be confirmed. Refresh to check its saved progress.'); refresh(); } });
    const row = detail.data;
    const trapTab = event => {
        if (event.key !== 'Tab') return;
        const controls = Array.from(dialog.current?.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex="0"]') || [])
            .filter(element => {
                if (!element.getClientRects().length) return false;
                // Chromium can retain layout boxes inside a closed details element.
                // Only its summary participates in keyboard navigation until it opens.
                for (let parent = element.parentElement; parent && parent !== dialog.current; parent = parent.parentElement) {
                    if (parent.tagName === 'DETAILS' && !parent.open && !parent.querySelector(':scope > summary')?.contains(element)) return false;
                }
                return true;
            });
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.current)) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };
    async function previewCorrection(intentId, selectedAction, reason) {
        try {
            const result = await action.mutateAsync({ path: `/crm/collections/receipts/${receiptId}/correction-preview`, body: { target_intent_id: intentId || undefined, action: selectedAction, reason, expected_version: row.allocation.version } });
            setPreview(result); executeKey.current = crypto.randomUUID();
        } catch { /* Error stays beside the preserved form. */ }
    }
    return <dialog ref={dialog} onKeyDown={trapTab} onCancel={onClose} onClose={onClose} onClick={event => { if (event.target === event.currentTarget) onClose(); }} aria-labelledby="collection-receipt-title" className="m-0 ml-auto h-dvh max-h-dvh w-full max-w-3xl border-0 bg-white p-0 text-slate-900 shadow-xl backdrop:bg-slate-900/35">
        <div className="flex h-full flex-col" onClick={e => e.stopPropagation()}><header className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4"><div><p className="text-xs font-medium uppercase tracking-wide text-slate-500">Incoming payment</p><h2 id="collection-receipt-title" className="mt-1 text-xl font-semibold">{row ? money(row) : 'Receipt details'}</h2></div><button type="button" className={button} onClick={onClose} autoFocus>Close</button></header>
        <div className="flex-1 space-y-5 overflow-y-auto p-5">
            {detail.isPending && <p role="status" className="text-sm text-slate-500">Loading receipt evidence…</p>}
            {(detail.isError || error) && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{error || 'Receipt could not be refreshed.'} <button className="underline" type="button" onClick={() => detail.refetch()}>Refresh receipt</button></p>}
            {row && <><div className="border-b border-slate-100 pb-4"><p className="font-medium text-teal-800">{states[row.decision_state] || explain(row.decision_state)}</p><p className="mt-1 text-sm text-slate-500">{row.account_label}</p><p className="mt-1 text-sm text-slate-500">{row.evidence_level === 'statement' ? 'Funds verified on a statement' : 'Funds observed on the receiving phone; statement verification is separate.'}</p>{row.historical_only && <p className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Historical archive receipt. Activation is disabled for this evidence.</p>}</div>
                <CollectionPayerSummary payer={row.payer} />
                <CollectionMessageCopy key={`messages-${row.id}`} receiptId={row.id} />
                {!row.allocation && !row.historical_only && <CollectionPurchaseReview key={row.id} row={row} onSaved={refresh} />}
                {row.allocation && row.clients?.map(c => <div key={c.id} className="flex items-center justify-between gap-3"><Link className="font-semibold text-teal-700 underline" to={`/clients/${c.id}?tab=payments`}>{c.name}</Link><span className="text-xs text-slate-500">{row.purchase?.subsidiary?.client_id === c.id ? 'Linked free exposure' : 'Paid profile'}</span></div>)}
                {row.allocation && row.purchase && <p className="text-sm text-slate-700">{row.purchase.tier.toUpperCase()} · {row.purchase.duration_days} days · {money({ amount_minor: row.purchase.amount_minor, currency: row.purchase.currency })}<span className="mt-1 block text-xs text-slate-500">Saved purchase authority · {row.purchase.discount_source ? 'Approved negotiated price' : 'Selected price option'}</span></p>}
                {!!row.reasons?.filter(reason => reason !== 'reviewed_purchase_authority_required').length && <ul className="space-y-2 text-sm text-slate-600">{row.reasons.filter(reason => reason !== 'reviewed_purchase_authority_required').map(reason => <li key={reason}>{explain(reason)}</li>)}</ul>}
                {!!row.allocation?.effects?.length && <div className="space-y-2 border-y border-slate-100 py-4"><h3 className="text-sm font-semibold">Service progress</h3>{row.allocation.effects.filter(e => !e.superseded_at).map(e => <div key={e.id} className="flex flex-wrap justify-between gap-2 text-sm"><span>{['subsidiary_free', 'target_subsidiary'].includes(e.kind) ? 'Tanzania free exposure' : explain(e.kind)}</span><span className={e.state === 'succeeded' ? 'text-teal-800' : 'text-amber-800'}>{e.state === 'succeeded' ? 'Confirmed on site' : explain(e.state)}</span>{e.last_error_code && <p className="w-full text-xs text-slate-500">{explain(e.last_error_code)}</p>}</div>)}</div>}
                <div className="flex flex-wrap gap-2">{row.decision_state === 'active_review_pending' && <button type="button" className={`${button} !border-teal-700 !bg-teal-700 !text-white`} disabled={action.isPending} onClick={() => action.mutate({ path: `/crm/collections/receipts/${receiptId}/confirm`, body: { expected_version: row.version } })}>Confirm correct</button>}
                    {row.allocation && !row.historical_only && <button type="button" className={button} onClick={() => { setMode('reassign'); setPreview(null); }}>Change client or package</button>}
                    {row.allocation && !row.historical_only && <button type="button" className={button} onClick={() => { setMode('undo_effect'); setPreview(null); }}>Undo this activation</button>}
                </div>
                {mode && !preview && <CollectionChoiceForm row={row} mode={mode} busy={action.isPending} onCancel={() => setMode(null)} onSubmit={async ({ intentId, reason, action: selectedAction }) => {
                    if (mode === 'approve') { try { await action.mutateAsync({ path: `/crm/collections/receipts/${receiptId}/approve`, body: { intent_id: intentId, expected_version: row.version } }); setMode(null); } catch {} }
                    else await previewCorrection(intentId, selectedAction || mode, reason);
                }} />}
                {preview && <section className="rounded-lg border border-teal-200 bg-teal-50/40 p-4" aria-label="Correction preview"><h3 className="font-semibold">Review correction</h3><p className="mt-2 text-sm">Money received stays unchanged. One cash payment remains.</p><p className="mt-3 text-sm">From: <strong>{preview.preview.source_client?.name || `Profile ${preview.preview.source_purchase?.client_id || ''}`}</strong></p>{preview.preview.target_client && <p className="mt-1 text-sm">To: <strong>{preview.preview.target_client.name}</strong> · {preview.preview.target_purchase?.tier?.toUpperCase()} · {preview.preview.target_purchase?.duration_days} days</p>}{preview.reason && <p className="mt-2 text-sm text-slate-600">Reason: {preview.reason}</p>}<p className="mt-2 text-sm">{preview.preview.target_purchase ? `Corrected profile receives ${preview.preview.target_purchase.duration_days} purchased days under the approved stacking policy.` : 'Remove only this activation. Keep the funds unassigned for a later reviewed purchase.'}</p>{preview.preview.source_steps.map(step => <p key={step.source_effect_id} className="mt-2 text-sm text-slate-600">Source profile {step.client_id}: preserve earlier and later legitimate coverage. Site changes require exact confirmation.</p>)}{preview.preview.reasons.map(reason => <p className="mt-3 text-sm text-amber-900" key={reason}>{explain(reason)}</p>)}<div className="mt-4 flex gap-3"><button type="button" className={`${button} !border-teal-700 !bg-teal-700 !text-white`} disabled={!preview.preview.can_execute || action.isPending} onClick={async () => { try { const result = await action.mutateAsync({ path: `/crm/collections/corrections/${preview.id}/execute`, body: { preview_version: preview.version, idempotency_key: executeKey.current } }); setPreview(result); if (result.state === 'verified') { setMode(null); setPreview(null); } } catch {} }}>{action.isPending ? 'Checking…' : preview.request_key ? 'Check correction progress' : 'Confirm correction'}</button><button type="button" className={button} disabled={!!preview.request_key} onClick={() => { setPreview(null); setMode(null); }}>Cancel</button></div></section>}
                <CollectionSettlementSummary accountId={row.account_id} />
                <details className="border-t border-slate-100 pt-4 text-sm"><summary className="cursor-pointer text-slate-600">Receipt reference and correction history</summary><p className="mt-3 select-all font-mono text-xs">{row.reference}</p><button type="button" className="mt-2 text-xs text-teal-700 underline" onClick={() => navigator.clipboard?.writeText(row.reference)}>Copy reference</button><p className="mt-3 text-xs text-slate-500">Provider time: {row.provider_at ? new Date(row.provider_at).toLocaleString() : 'Unknown'}</p>{row.corrections?.map(c => <div key={c.id} className="mt-3 border-t border-slate-100 pt-3"><p>{explain(c.action)} · {explain(c.state)}</p><p className="mt-1 text-xs text-slate-500">{c.reason}</p>{c.request_key && !['verified', 'previewed'].includes(c.state) && <button className="mt-2 text-xs text-teal-700 underline" type="button" onClick={() => { setPreview(c); executeKey.current = c.request_key; }}>Check saved correction</button>}</div>)}</details>
            </>}
        </div></div>
    </dialog>;
}

function CollectionChoiceForm({ row, mode, busy, onSubmit, onCancel }) {
    const { user } = useAuth();
    const [search, setSearch] = useState('');
    const [clientId, setClientId] = useState('');
    const [priceId, setPriceId] = useState('');
    const [quoteId, setQuoteId] = useState('');
    const [intentId, setIntentId] = useState(row.candidates?.length === 1 ? row.candidates[0].intent_id : '');
    const [reason, setReason] = useState('');
    const [remember, setRemember] = useState(false);
    const [standing, setStanding] = useState(false);
    const [subsidiaryId, setSubsidiaryId] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const choices = useQuery({ queryKey: ['collection-client-search', user?.id, row.id, search], enabled: search.trim().length >= 2 && mode !== 'undo_effect', queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/candidates`, { params: { search } })).data });
    const selectedClient = choices.data?.clients?.find(c => String(c.id) === clientId);
    const prices = choices.data?.prices?.filter(p => Number(p.product?.platform_id) === Number(selectedClient?.platform_id)) || [];
    async function submit(event) {
        event.preventDefault(); setError(''); setSaving(true);
        try {
            let selectedIntent = intentId;
            if (mode !== 'undo_effect' && !selectedIntent) {
                const validUntil = new Date(Date.now() + (standing ? 30 : 1) * 86400000).toISOString();
                let payerLink;
                if (remember || standing) payerLink = (await api.post(`/crm/collections/receipts/${row.id}/payer-links`, { client_id: Number(clientId), auto_allowed: standing, valid_until: validUntil })).data;
                const quote = choices.data?.quotes?.find(q => String(q.id) === quoteId);
                const intent = (await api.post(`/crm/collections/receipts/${row.id}/intents`, { client_id: Number(clientId), product_price_id: Number(quote?.product_price_id || priceId), deal_id: quote ? quote.id : undefined,
                    payer_link_id: payerLink?.id, authority: standing ? 'standing_agreement' : 'reviewed_quote', max_renewals: standing ? 3 : 1, valid_until: validUntil, subsidiary_client_id: subsidiaryId ? Number(subsidiaryId) : undefined })).data;
                selectedIntent = intent.id; setIntentId(intent.id);
            }
            await onSubmit({ intentId: selectedIntent, reason, action: mode === 'reassign' && selectedClient?.id === row.purchase?.client_id ? 'change_package' : mode });
        } catch (e) { setError(e.response?.data?.message || 'Could not save the purchase choice. Please check it and try again.'); }
        finally { setSaving(false); }
    }
    return <form onSubmit={submit} className="space-y-4 rounded-lg border border-slate-200 p-4" aria-label="Purchase choice"><h3 className="font-semibold">{mode === 'undo_effect' ? 'Undo this activation' : 'Confirm the purchased service'}</h3>
        {mode !== 'undo_effect' && <><label className="block text-sm">Reviewed purchase<select className="crm-input mt-1" value={intentId} onChange={e => setIntentId(e.target.value)}><option value="">Choose a profile and exact price option</option>{row.candidates?.map(c => <option key={c.intent_id} value={c.intent_id}>Profile {c.client_id} · {c.tier.toUpperCase()} · {c.duration_days} days · {money(c)}</option>)}</select></label>
            {!intentId && <><label className="block text-sm">Find client by name, profile, advert phone or payment phone<input className="crm-input mt-1" value={search} minLength={2} onChange={e => setSearch(e.target.value)} /></label>{choices.isFetching && <p role="status" className="text-xs text-slate-500">Searching permitted profiles…</p>}{choices.isError && <p role="alert" className="text-sm text-rose-700">Client search failed. Change the search to try again.</p>}
            <label className="block text-sm">Client<select required className="crm-input mt-1" value={clientId} onChange={e => { setClientId(e.target.value); setQuoteId(''); setPriceId(''); }}><option value="">Select profile</option>{choices.data?.clients?.map(c => <option key={c.id} value={c.id}>{c.name} · Profile {c.wp_post_id}</option>)}</select></label>
            <label className="block text-sm">Existing approved quote<select className="crm-input mt-1" value={quoteId} onChange={e => { setQuoteId(e.target.value); setPriceId(''); }}><option value="">Use a catalogue price option</option>{choices.data?.quotes?.filter(q => String(q.client_id) === clientId).map(q => <option key={q.id} value={q.id}>{q.plan_type.toUpperCase()} · {q.duration_days} days · {q.currency} {q.amount}{q.discount_source ? ' · Negotiated' : ''}</option>)}</select></label>
            {!!clientId && <details className="text-sm text-slate-600"><summary className="cursor-pointer">Recent subscription context</summary><p className="mt-2 text-xs text-slate-500">History helps explain a purchase; it does not authorize the next renewal.</p>{choices.data?.recent_subscriptions?.filter(q => String(q.client_id) === clientId).slice(0, 3).map(q => <p className="mt-2 text-xs" key={q.id}>{q.plan_type.toUpperCase()} · {q.duration_days || 'Unknown'} days · {q.currency} {q.amount}{q.discount_source ? ' · Negotiated' : ''} · {new Date(q.activated_at).toLocaleDateString()}</p>)}</details>}
            {!quoteId && <label className="block text-sm">Exact price and duration<select required className="crm-input mt-1" value={priceId} onChange={e => setPriceId(e.target.value)}><option value="">Select purchased package</option>{prices.map(p => <option key={p.id} value={p.id}>{p.product.name} · {p.duration_days} days · {p.currency} {p.price}</option>)}</select></label>}
            <p className="text-xs text-slate-500">For a new negotiated price, save an approved quote in Client Detail first. Receiving the same amount does not establish the purchased duration.</p>
            {Number(selectedClient?.platform_id) === 6 && <label className="block text-sm">Reviewed linked Tanzania profile ID<input type="number" min="1" required className="crm-input mt-1" value={subsidiaryId} onChange={e => setSubsidiaryId(e.target.value)} /></label>}
            <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={remember} onChange={e => setRemember(e.target.checked)} />Remember this observed payer for this profile after review</label>
            {['admin', 'sub_admin'].includes(user?.role) && <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={standing} onChange={e => setStanding(e.target.checked)} />Authorize this exact renewal for up to 3 payments over 30 days. Automatic account limits still apply.</label>}</>}
        </>}
        {mode !== 'approve' && <label className="block text-sm">Correction reason<textarea required minLength={3} maxLength={1000} className="crm-input mt-1" value={reason} onChange={e => setReason(e.target.value)} /></label>}
        {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}<div className="flex flex-wrap gap-3"><button type="submit" className={`${button} !border-teal-700 !bg-teal-700 !text-white`} disabled={busy || saving}>{saving ? 'Saving…' : mode === 'approve' ? 'Review and activate' : 'Preview correction'}</button><button type="button" className={button} disabled={saving} onClick={onCancel}>Cancel</button></div>
    </form>;
}

function CollectionSettlementSummary({ accountId }) {
    const { user } = useAuth();
    const [open, setOpen] = useState(false);
    const query = useQuery({ queryKey: ['collection-settlement', user?.id, accountId], enabled: open,
        queryFn: async () => (await api.get(`/crm/collections/accounts/${accountId}/settlement`)).data, refetchOnWindowFocus: true });
    const data = query.data;
    return <details className="border-t border-slate-100 pt-4 text-sm" onToggle={event => setOpen(event.currentTarget.open)}>
        <summary className="cursor-pointer text-slate-600">Collector reconciliation</summary>
        <p className="mt-2 text-xs text-slate-500">Customer payment and remittance to Exotic are separate. Totals below belong to this receiving account and currency.</p>
        {query.isFetching && <p role="status" className="mt-3 text-xs text-slate-500">Checking reconciliation…</p>}
        {query.isError && <p role="alert" className="mt-3 text-xs text-rose-800">Reconciliation could not be loaded. <button className="underline" type="button" onClick={() => query.refetch()}>Retry</button></p>}
        {data && <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3">{[['Received', 'gross_received_minor'], ['Statement credits', 'statement_gross_minor'], ['Remitted to Exotic', 'remitted_minor'], ['Closing amount due', 'closing_due_minor']].map(([label, field]) => <div key={field}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 font-medium">{money({ currency: data.currency, amount_minor: data[field] })}</dd></div>)}</dl>}
    </details>;
}
