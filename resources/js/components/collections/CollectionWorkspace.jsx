import CollectionSubsidiaryProgress from './CollectionSubsidiaryProgress';
import CollectionStandingAgreement from './CollectionStandingAgreement';
import CollectionReceiptTimeline from './CollectionReceiptTimeline';
import React, { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import CollectionDeviceHealthPanel from './CollectionDeviceHealthPanel';
import CollectionPolicyPanel, { CollectionAutomationSummary } from './CollectionPolicyPanel';
import CollectionPurchaseReview, { CollectionPayerSummary, CollectionMatchEvidence } from './CollectionPurchaseReview';
import CollectionMessageCopy from './CollectionMessageCopy';
import CollectionReceiptEvidence, { receiptTime } from './CollectionReceiptEvidence';
import { ActivatedBy, ActivationSpeedSummary, Icon, StatusPill, TimeToActivate, WalletAfter, WalletBalanceSummary, relative, tones } from './collectionUi';

const states = { purchase_saved: 'Choice saved · Review activation', sales_draft: 'Allocations saved · Review activation', sales_prepared: 'Waiting to activate', sales_activating: 'Activating subscriptions', sales_partial: 'Partly active', sales_confirmed: 'Active · Confirmed', sales_needs_review: 'Sale needs checking', collecting: 'Waiting for payment details', historical: 'Historical · no activation', ready_auto: 'Ready for automatic activation', dispatching: 'Activation processing', active_review_pending: 'Active · Review needed', reviewed: 'Reviewed', needs_review: 'Needs a sales decision', uncertain: 'Checking site confirmation', correcting: 'Correction in progress', unassigned: 'Money received · Unassigned', dependent_pending: 'Active · Tanzania exposure pending' };
const reasons = {
    multiple_exact_package_matches: 'More than one configured package matches this amount. Choose the purchased package.',
    no_exact_catalogue_package_match: 'No current configured package exactly matches this payment. Review the agreed price and service.',
    approved_default_package_unavailable: 'The approved automatic package is no longer available at this price. Staff must review the payment.',
    client_match_below_threshold_or_conflicting: 'The profile match is below the automatic threshold or has conflicting evidence. Choose the intended profile.',
    receipt_outside_automatic_age: 'This payment is older than the approved automatic window. It remains available for staff review.',
    receipt_outside_automation_epoch: 'This payment arrived before the current automatic policy took effect. Review it manually.',
    corrected_payer_match_requires_fresh_review: 'A staff correction rejected this payer-to-profile match. A fresh explicit review is required.',
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
const accordion = 'flex cursor-pointer list-none items-center justify-between gap-3 py-3 text-sm font-medium text-slate-800 [&::-webkit-details-marker]:hidden';
const poll = () => document.visibilityState === 'visible' ? 10000 : false;
const button = 'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 disabled:cursor-not-allowed disabled:opacity-40';

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
    const [sort, setSort] = useState('newest');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [page, setPage] = useState(1);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [searchParams, setSearchParams] = useSearchParams();
    const requestedReceipt = Number(searchParams.get('receipt'));
    const [selected, setSelected] = useState(Number.isSafeInteger(requestedReceipt) && requestedReceipt > 0 ? requestedReceipt : null);
    useEffect(() => { setSelected(Number.isSafeInteger(requestedReceipt) && requestedReceipt > 0 ? requestedReceipt : null); }, [requestedReceipt]);
    function selectReceipt(id) { setSelected(id); const next = new URLSearchParams(searchParams); if (id) next.set('receipt', String(id)); else next.delete('receipt'); setSearchParams(next, { replace: true }); }
    const [exportError, setExportError] = useState('');
    const params = { sort, date_from: dateFrom || undefined, date_to: dateTo || undefined, state: state || undefined, search: search || undefined, platform_id: platformId || undefined, client_id: clientId || undefined, page };
    const rows = useQuery({ queryKey: ['collection-receipts', user?.id, params], enabled: !!capabilities.data?.enabled,
        queryFn: async () => (await api.get('/crm/collections/receipts', { params })).data, refetchInterval: poll, refetchOnWindowFocus: true });
    if (!capabilities.data?.enabled) return null;
    async function exportReceipts() {
        try {
            const { data } = await api.get('/crm/collections/receipts', { params: { ...params, export: 1 }, responseType: 'blob' });
            const url = URL.createObjectURL(data); const link = document.createElement('a'); link.href = url; link.download = 'collection-receipts.csv'; link.click(); URL.revokeObjectURL(url); setExportError('');
        } catch { setExportError('Export failed. Please try again.'); }
    }
    const filtered = !!(search || dateFrom || dateTo || (state && !['needs_action', 'already_recorded'].includes(state)));
    const clearFilters = () => { setSearch(''); setDateFrom(''); setDateTo(''); if (!['needs_action', 'already_recorded'].includes(state)) setState(''); setPage(1); };
    const total = rows.data?.total || 0;
    const from = rows.data?.from || 0;
    const to = rows.data?.to || 0;
    const needsAction = rows.data?.counts?.needs_action;
    return <section aria-label="Incoming office payments" className="crm-surface p-4 sm:p-5">
        <CollectionDeviceHealthPanel platformId={platformId} compact />
        {!clientId && <CollectionAutomationSummary onConfigure={() => setSettingsOpen(true)} />}
        <div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-base font-semibold text-slate-900">Incoming office payments</h2><p className="mt-1 max-w-2xl text-sm text-slate-500">SMS received → client matched → package matched → subscription activated.</p><p className="mt-1 text-xs text-slate-500">Eligible new payments follow the automatic policy. Review exceptions here.</p></div><button type="button" className={button} onClick={exportReceipts}><Icon name="download" />Export receipts</button></div>
        <div className="my-5 space-y-3">
            <div className="inline-flex max-w-full flex-wrap gap-1 rounded-xl bg-slate-100 p-1" aria-label="Payment views">{[['', 'All payments'], ['needs_action', 'Needs action'], ['already_recorded', 'Already recorded']].map(([value, label]) => <button key={value} type="button" aria-pressed={state === value} className={`inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 ${state === value ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900'}`} onClick={() => { setState(value); setPage(1); }}>{label}{value === 'needs_action' && needsAction > 0 && <span className="rounded-full bg-amber-100 px-1.5 text-xs font-semibold tabular-nums text-amber-900" aria-label={`${needsAction} need action`}>{needsAction}</span>}</button>)}</div>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]"><label className="text-sm text-slate-600">Reference or payer phone<span className="relative mt-1 block"><Icon name="search" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><input className="crm-input w-full !pl-9" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} placeholder="Code or full phone number" /></span></label><label className="text-sm text-slate-600">Sort payments<select className="crm-input mt-1 w-full" value={sort} onChange={e => { setSort(e.target.value); setPage(1); }}><option value="newest">Newest payment first</option><option value="oldest">Oldest payment first</option><option value="amount_high">Amount: high to low, by currency</option><option value="amount_low">Amount: low to high, by currency</option></select></label><label className="text-sm text-slate-600">Paid from (EAT)<input type="date" className="crm-input mt-1 w-full" value={dateFrom} onChange={e => { setDateFrom(e.target.value); setPage(1); }} /></label><label className="text-sm text-slate-600">Paid to (EAT)<input type="date" className="crm-input mt-1 w-full" value={dateTo} min={dateFrom || undefined} onChange={e => { setDateTo(e.target.value); setPage(1); }} /></label></div>
            <div className="flex flex-wrap items-center gap-x-4"><details><summary className="cursor-pointer py-2 text-sm font-medium text-slate-600">More status filters</summary><label className="mt-2 block max-w-sm text-sm text-slate-600">Review state<select className="crm-input mt-1 w-full" value={state} onChange={e => { setState(e.target.value); setPage(1); }}><option value="">All receipts</option><option value="needs_action">Needs action</option><option value="already_recorded">Already recorded</option>{Object.entries(states).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label></details>
                {filtered && <button type="button" className="py-2 text-sm font-medium text-teal-800 underline" onClick={clearFilters}>Clear filters</button>}</div>
        </div>
        {(rows.isError || exportError) && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{exportError || 'Incoming payments could not be refreshed. The last loaded rows may be out of date.'} <button type="button" className="underline" onClick={() => rows.refetch()}>Retry</button></p>}
        {rows.isPending ? <div role="status" aria-label="Loading receiving receipts" className="divide-y divide-slate-100 rounded-xl border border-slate-200">{[0, 1, 2, 3].map(i => <div key={i} className="grid gap-4 p-4 sm:grid-cols-4"><div className="h-10 rounded bg-slate-100 motion-safe:animate-pulse" /><div className="h-10 rounded bg-slate-100 motion-safe:animate-pulse" /><div className="h-10 rounded bg-slate-100 motion-safe:animate-pulse" /><div className="h-10 rounded bg-slate-100 motion-safe:animate-pulse" /></div>)}<span className="sr-only">Loading receiving receipts…</span></div>
            : !rows.data?.data?.length ? <div className="flex flex-col items-center rounded-xl border border-dashed border-slate-300 px-6 py-12 text-center"><span className="flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 text-slate-500"><Icon name="inbox" className="h-5 w-5" /></span><p className="mt-3 font-medium text-slate-900">{state === 'needs_action' && !filtered ? 'Nothing needs action' : 'No payments in this view'}</p><p className="mt-1 max-w-sm text-sm text-slate-500">{filtered ? 'No incoming receipts match these filters.' : state === 'needs_action' ? 'Every captured payment is matched, activated or already recorded.' : 'No incoming receipts match this view. New receiving messages will appear here.'}</p>{filtered && <button type="button" className={`${button} mt-4`} onClick={clearFilters}>Clear filters</button>}</div>
            : <>{!clientId && <WalletBalanceSummary balances={rows.data.wallet_balances} />}{!clientId && <ActivationSpeedSummary rows={rows.data.data} />}<div className="overflow-hidden rounded-xl border border-slate-200 max-md:border-0 max-md:overflow-visible"><table className="w-full text-left text-sm max-md:block"><thead className="bg-slate-50 text-xs text-slate-500 max-md:sr-only"><tr>{['Received', 'Payer and profile', 'Subscription', 'Review'].map((x, i) => <th key={x} scope="col" className={`px-4 py-3 font-medium ${i === 3 ? 'text-right' : ''}`}>{x}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-100 max-md:block max-md:space-y-3 max-md:divide-y-0">{rows.data.data.map(row => <ReceiptRow key={row.id} row={row} onOpen={() => selectReceipt(row.id)} />)}</tbody></table></div></>}
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500"><span className="inline-flex items-center gap-2 tabular-nums">{rows.isFetching && !rows.isPending ? <span aria-hidden="true" className="h-2 w-2 rounded-full bg-sky-500 motion-safe:animate-pulse" /> : <span aria-hidden="true" className="h-2 w-2 rounded-full bg-teal-500" />}{total && from ? `${from}–${to} of ${total} receipts` : `${total} ${total === 1 ? 'receipt' : 'receipts'}`} · {rows.dataUpdatedAt ? `Updated ${new Date(rows.dataUpdatedAt).toLocaleTimeString()}` : 'Waiting for updates'}</span>
            <div className="flex items-center gap-2"><button type="button" className={button} disabled={page === 1} onClick={() => setPage(p => p - 1)}>Previous</button><span className="px-1 tabular-nums">Page {page}{rows.data?.last_page ? ` of ${rows.data.last_page}` : ''}</span><button type="button" className={button} disabled={!rows.data?.next_page_url} onClick={() => setPage(p => p + 1)}>Next</button></div></div>
        {selected && <CollectionReceiptDrawer receiptId={selected} onClose={() => selectReceipt(null)} />}
        {!clientId && <CollectionPolicyPanel open={settingsOpen} onClose={() => setSettingsOpen(false)} />}
    </section>;
}

const progressing = ['dispatching', 'uncertain', 'correcting', 'dependent_pending', 'sales_activating', 'sales_partial', 'ready_auto', 'sales_prepared'];
export function receiptTone(row) {
    if (row.usage && ['already_recorded'].includes(row.usage.state)) return { tone: 'neutral', label: 'Already recorded' };
    if (row.usage?.state === 'conflict') return { tone: 'danger', label: 'Code needs checking' };
    if (row.historical_only) return { tone: 'neutral', label: states.historical };
    const label = states[row.decision_state] || explain(row.decision_state);
    if (['reviewed', 'sales_confirmed'].includes(row.decision_state)) return { tone: 'live', label };
    if (row.decision_state === 'active_review_pending') return { tone: 'live', label };
    if (progressing.includes(row.decision_state)) return { tone: 'busy', label, pulse: true };
    if (row.decision_state === 'sales_needs_review') return { tone: 'danger', label };
    return { tone: 'action', label: row.decision_state ? label : 'Needs a sales decision' };
}

function ReceiptRow({ row, onOpen }) {
    const status = receiptTone(row);
    const automatic = row.allocation?.decision_source === 'automatic' && ['active_review_pending', 'reviewed'].includes(row.decision_state);
    const recorded = row.usage?.state === 'already_recorded';
    const actionLabel = row.reviewed_at || recorded || row.historical_only || ['reviewed', 'sales_confirmed'].includes(row.decision_state) ? 'View receipt' : ['dispatching', 'uncertain', 'correcting', 'dependent_pending', 'sales_prepared', 'sales_activating', 'sales_partial'].includes(row.decision_state) ? 'View progress' : ['active_review_pending', 'sales_needs_review'].includes(row.decision_state) ? 'Review activation' : 'Match & activate';
    const primary = ['Match & activate', 'Review activation'].includes(actionLabel) && !row.historical_only;
    const when = row.provider_at || row.received_at || row.created_at;
    const match = row.usage?.matches?.[0];
    return <tr className={`group border-l-4 align-top transition-colors hover:bg-slate-50/80 max-md:grid max-md:grid-cols-1 max-md:gap-3 max-md:rounded-xl max-md:border max-md:border-l-4 max-md:border-y-slate-200 max-md:border-r-slate-200 max-md:p-4 ${tones[status.tone].edge}`}>
        <td className="px-4 py-4 max-md:p-0"><p className="text-base font-semibold tabular-nums text-slate-900">{money(row)}</p><p className="mt-1 text-xs tabular-nums text-slate-700">{row.provider_at ? `Paid ${receiptTime(row.provider_at)}` : row.received_at ? `Phone arrival ${receiptTime(row.received_at)}` : `Recorded ${receiptTime(row.created_at)}`}</p><p className="mt-0.5 text-xs text-slate-500">{relative(when)}</p><p className="mt-2 inline-block select-all rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700">{row.reference}</p><p className="mt-1.5 text-xs text-slate-500">{row.account_label} · {row.evidence_level === 'statement' ? 'Verified on statement' : 'Observed on receiving phone'}</p><WalletAfter balance={row.wallet_balance} className="mt-2" /></td>
        <td className="px-4 py-4 max-md:p-0"><p className="break-words font-medium text-slate-900">{row.payer?.name || (row.payer?.name_state === 'conflicting' ? 'Payer names need review' : 'Name not supplied in SMS')}</p><p className="mt-0.5 select-all text-xs tabular-nums text-slate-600">{row.payer?.phone || 'Phone not supplied in SMS'}</p>{row.captured_payer_receipts !== null && row.captured_payer_receipts !== undefined && <p className="mt-0.5 text-xs text-slate-500">{row.captured_payer_receipts} captured {row.captured_payer_receipts === 1 ? 'receipt' : 'receipts'} using this number</p>}
            <div className="mt-3 border-t border-slate-100 pt-2">{row.clients?.length > 1 ? <p className="text-sm font-medium text-slate-800">{row.clients.length} profiles · Shared payment</p> : row.clients?.[0] ? <Link to={`/clients/${row.clients[0].id}`} className="font-medium text-teal-800 underline decoration-teal-800/30 underline-offset-2 hover:decoration-teal-800">{row.clients[0].name}</Link> : <p className="text-sm text-slate-600">Profile needs confirmation</p>}
                {!row.clients?.length && row.match_suggestions?.map(candidate => <p key={candidate.id} className="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-600"><Link to={`/clients/${candidate.id}`} className="font-medium text-teal-800 underline">{candidate.name}</Link><span className={`rounded-full px-1.5 py-0.5 font-semibold tabular-nums ${candidate.score > 80 ? 'bg-teal-50 text-teal-900' : 'bg-amber-50 text-amber-900'}`}>Saved match score {candidate.score} / 100</span><span>{row.match_needs_choice ? 'Staff choice required' : 'Suggested profile'}</span></p>)}
                {row.purchase && <p className="mt-1 text-xs text-slate-500">{row.purchase.tier.toUpperCase()} · {row.purchase.duration_days} days</p>}</div></td>
        <td className="px-4 py-4 max-md:p-0"><StatusPill tone={status.tone} pulse={status.pulse}>{status.label}</StatusPill>
            {row.subsidiary_obligation && !['succeeded', 'already_active', 'canceled'].includes(row.subsidiary_obligation.state) && <p className="mt-2 text-xs font-medium text-amber-900">TZ active · Tanzania {row.subsidiary_obligation.state === 'copying' ? 'copying' : 'needs completion'}</p>}
            {!row.activation && automatic && <p className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-teal-800"><Icon name="bolt" className="h-3.5 w-3.5" />Auto-activated</p>}
            {recorded && match && <p className="mt-2 text-xs text-slate-600">{match.client && <Link className="font-medium text-teal-800 underline" to={`/clients/${match.client.id}?tab=deals`}>{match.client.name}</Link>}{match.deal && <span className="block mt-0.5">{match.deal.plan_type?.toUpperCase()} · {match.deal.status === 'active' && new Date(match.deal.expires_at).getTime() > Date.now() ? 'Active' : match.deal.status} · expires {receiptTime(match.deal.expires_at)}</span>}</p>}
            <ActivatedBy activation={row.activation} />
            <TimeToActivate row={row} />
            {row.usage?.state === 'conflict' && <p className="mt-2 text-xs text-rose-800">An existing payment uses this code. Check it before recording another.</p>}
            {row.historical_only && <p className="mt-1 text-xs text-slate-500">Reconciliation only</p>}</td>
        <td className="px-4 py-4 text-right max-md:p-0 max-md:text-left"><button type="button" className={primary ? `${button} whitespace-nowrap !border-teal-700 !bg-teal-700 !text-white hover:!bg-teal-800 max-md:w-full` : `${button} whitespace-nowrap max-md:w-full`} onClick={onOpen}>{actionLabel}</button></td>
    </tr>;
}

export function CollectionReceiptDrawer({ receiptId, onClose }) {
    const { user } = useAuth();
    const dialog = useRef(null);
    const [footerTarget, setFooterTarget] = useState(null);
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
    const headTone = row ? receiptTone(row) : null;
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
    return <dialog ref={dialog} onKeyDown={trapTab} onCancel={onClose} onClose={onClose} onClick={event => { if (event.target === event.currentTarget) onClose(); }} aria-labelledby="collection-receipt-title" className="m-0 ml-auto h-dvh max-h-dvh w-full max-w-3xl border-0 bg-slate-50 p-0 text-slate-900 shadow-2xl backdrop:bg-slate-900/40">
        <div className="flex h-full flex-col" onClick={e => e.stopPropagation()}><header className="border-b border-slate-200 bg-white px-5 py-4"><div className="flex items-start justify-between gap-4"><div className="min-w-0"><p className="text-xs font-medium uppercase tracking-wide text-slate-500">Incoming payment{row ? ` · ${row.account_label}` : ''}</p><h2 id="collection-receipt-title" className="mt-1 text-2xl font-semibold tabular-nums tracking-tight">{row ? money(row) : 'Receipt details'}</h2></div><button type="button" className={button} onClick={onClose} autoFocus><Icon name="close" />Close</button></div>
            {row && <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2"><StatusPill tone={headTone.tone} pulse={headTone.pulse}>{row.usage?.state === 'already_recorded' ? 'Existing payment found' : headTone.label}</StatusPill>{row.activation && <TimeToActivate row={row} className="!mt-0" />}<WalletAfter balance={row.wallet_balance} />{row.activation ? <ActivatedBy activation={row.activation} compact /> : row.allocation?.decision_source === 'automatic' && <span className="inline-flex items-center gap-1 text-xs font-medium text-teal-800"><Icon name="bolt" className="h-3.5 w-3.5" />Auto-activated</span>}<span className="text-xs tabular-nums text-slate-500">{row.provider_at ? `Paid ${receiptTime(row.provider_at)}` : `Phone arrival ${receiptTime(row.received_at)}`} · {relative(row.provider_at || row.received_at)}</span><span className="select-all rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700">{row.reference}</span></div>}</header>
        <div className="flex-1 space-y-4 overflow-y-auto p-4 sm:p-5">
            {detail.isPending && <p role="status" className="text-sm text-slate-500">Loading receipt evidence…</p>}
            {(detail.isError || error) && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{error || 'Receipt could not be refreshed.'} <button className="underline" type="button" onClick={() => detail.refetch()}>Refresh receipt</button></p>}
            {row && <>{row.historical_only && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Historical archive receipt. Activation is disabled for this evidence.</p>}
                <CollectionMatchEvidence key={`match-${row.id}`} row={row} />
                <CollectionReceiptEvidence usage={row.usage} />
                {!row.allocation && !row.historical_only && <div className="rounded-xl border border-slate-200 bg-white p-4 sm:p-5"><CollectionPurchaseReview key={row.id} row={row} footerTarget={footerTarget} onSaved={refresh} /></div>}
                {row.allocation && <section aria-label="Activation" className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5"><h3 className="text-sm font-semibold text-slate-900">Activation</h3>
                {row.allocation && row.clients?.map(c => <div key={c.id} className="flex items-center justify-between gap-3"><Link className="font-semibold text-teal-700 underline" to={`/clients/${c.id}?tab=payments`}>{c.name}</Link><span className="text-xs text-slate-500">{row.purchase?.subsidiary?.client_id === c.id ? 'Linked free exposure' : 'Paid profile'}</span></div>)}
                {row.allocation && row.purchase && <p className="text-sm text-slate-700">{row.purchase.tier.toUpperCase()} · {row.purchase.duration_days} days · {money({ amount_minor: row.purchase.amount_minor, currency: row.purchase.currency })}<span className="mt-1 block text-xs text-slate-500">Saved purchase authority · {row.purchase.discount_source ? 'Approved negotiated price' : 'Selected price option'}</span></p>}
                {!!row.reasons?.filter(reason => reason !== 'reviewed_purchase_authority_required').length && <ul className="space-y-2 text-sm text-slate-600">{row.reasons.filter(reason => reason !== 'reviewed_purchase_authority_required').map(reason => <li key={reason}>{explain(reason)}</li>)}</ul>}
                {!!row.allocation?.effects?.length && <div className="space-y-2 rounded-lg bg-slate-50 p-3"><h3 className="text-sm font-semibold">Service progress</h3>{row.allocation.effects.filter(e => !e.superseded_at).map(e => <div key={e.id} className="flex flex-wrap justify-between gap-2 text-sm"><span>{['subsidiary_free', 'target_subsidiary'].includes(e.kind) ? 'Tanzania free exposure' : explain(e.kind)}</span><span className={e.state === 'succeeded' ? 'text-teal-800' : 'text-amber-800'}>{e.state === 'succeeded' ? 'Confirmed on site' : explain(e.state)}</span>{e.last_error_code && <p className="w-full text-xs text-slate-500">{explain(e.last_error_code)}</p>}</div>)}</div>}
                <div className="flex flex-wrap gap-2">{row.decision_state === 'active_review_pending' && <button type="button" className={`${button} !border-teal-700 !bg-teal-700 !text-white`} disabled={action.isPending} onClick={() => action.mutate({ path: `/crm/collections/receipts/${receiptId}/confirm`, body: { expected_version: row.version } })}>Confirm correct</button>}
                    {row.allocation && !row.historical_only && <button type="button" className={button} onClick={() => { setMode('reassign'); setPreview(null); }}>Change client or package</button>}
                    {row.allocation && !row.historical_only && <button type="button" className={button} onClick={() => { setMode('undo_effect'); setPreview(null); }}>Undo this activation</button>}
                </div>
                {mode && !preview && <CollectionChoiceForm row={row} mode={mode} busy={action.isPending} onCancel={() => setMode(null)} onSubmit={async ({ intentId, reason, action: selectedAction }) => {
                    if (mode === 'approve') { try { await action.mutateAsync({ path: `/crm/collections/receipts/${receiptId}/approve`, body: { intent_id: intentId, expected_version: row.version } }); setMode(null); } catch {} }
                    else await previewCorrection(intentId, selectedAction || mode, reason);
                }} />}
                {preview && <section className="rounded-lg border border-teal-200 bg-teal-50/40 p-4" aria-label="Correction preview"><h3 className="font-semibold">Review correction</h3><p className="mt-2 text-sm">Money received stays unchanged. One cash payment remains.</p><p className="mt-3 text-sm">From: <strong>{preview.preview.source_client?.name || `Profile ${preview.preview.source_purchase?.client_id || ''}`}</strong></p>{preview.preview.target_client && <p className="mt-1 text-sm">To: <strong>{preview.preview.target_client.name}</strong> · {preview.preview.target_purchase?.tier?.toUpperCase()} · {preview.preview.target_purchase?.duration_days} days</p>}{preview.reason && <p className="mt-2 text-sm text-slate-600">Reason: {preview.reason}</p>}<p className="mt-2 text-sm">{preview.preview.target_purchase ? `Corrected profile receives ${preview.preview.target_purchase.duration_days} purchased days under the approved stacking policy.` : 'Remove only this activation. Keep the funds unassigned for a later reviewed purchase.'}</p>{preview.preview.source_steps.map(step => <p key={step.source_effect_id} className="mt-2 text-sm text-slate-600">Source profile {step.client_id}: preserve earlier and later legitimate coverage. Site changes require exact confirmation.</p>)}{preview.preview.reasons.map(reason => <p className="mt-3 text-sm text-amber-900" key={reason}>{explain(reason)}</p>)}<div className="mt-4 flex gap-3"><button type="button" className={`${button} !border-teal-700 !bg-teal-700 !text-white`} disabled={!preview.preview.can_execute || action.isPending} onClick={async () => { try { const result = await action.mutateAsync({ path: `/crm/collections/corrections/${preview.id}/execute`, body: { preview_version: preview.version, idempotency_key: executeKey.current } }); setPreview(result); if (result.state === 'verified') { setMode(null); setPreview(null); } } catch {} }}>{action.isPending ? 'Checking…' : preview.request_key ? 'Check correction progress' : 'Confirm correction'}</button><button type="button" className={button} disabled={!!preview.request_key} onClick={() => { setPreview(null); setMode(null); }}>Cancel</button></div></section>}
                </section>}
                {row.subsidiary_obligation && <CollectionSubsidiaryProgress row={row} onChanged={refresh} />}
                <section aria-label="Details and history" className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white px-4">
                <details className="group py-1"><summary className={accordion}>Payer, receiving message &amp; payment details<Icon name="chevron" className="h-4 w-4 text-slate-400 transition-transform group-open:rotate-90" /></summary><div className="space-y-4 pb-4 pt-1"><CollectionPayerSummary payer={row.payer} /><CollectionMessageCopy key={`messages-${row.id}`} receiptId={row.id} /><dl className="grid gap-3 text-sm sm:grid-cols-3"><div><dt className="text-xs text-slate-500">Payment code</dt><dd className="mt-0.5 select-all font-mono font-medium">{row.reference}</dd></div><div><dt className="text-xs text-slate-500">Paid at</dt><dd className="mt-0.5">{receiptTime(row.provider_at)}</dd></div><div><dt className="text-xs text-slate-500">First received on phone</dt><dd className="mt-0.5">{receiptTime(row.received_at)}</dd></div></dl><p className="text-xs text-slate-500">{row.evidence_level === 'statement' ? 'Funds verified on a statement.' : 'Funds observed on the receiving phone; statement verification is separate.'}</p></div></details>
                {row.timeline && <div className="py-1"><CollectionReceiptTimeline key={`timeline-${row.id}`} receiptId={row.id} timeline={row.timeline} /></div>}
                <details className="group py-1"><summary className={accordion}>Optional renewal agreement<Icon name="chevron" className="h-4 w-4 text-slate-400 transition-transform group-open:rotate-90" /></summary><div className="pb-4"><CollectionStandingAgreement key={`agreement-${row.id}`} receipt={row} /></div></details>
                <div className="py-1"><CollectionSettlementSummary accountId={row.account_id} /></div>
                <details className="group py-1 text-sm"><summary className={accordion}>Receipt reference and correction history<Icon name="chevron" className="h-4 w-4 text-slate-400 transition-transform group-open:rotate-90" /></summary><p className="mt-3 select-all font-mono text-xs">{row.reference}</p><button type="button" className="mt-2 text-xs text-teal-700 underline" onClick={() => navigator.clipboard?.writeText(row.reference)}>Copy reference</button><p className="mt-3 text-xs text-slate-500">Provider time: {row.provider_at ? new Date(row.provider_at).toLocaleString() : 'Unknown'}</p>{row.corrections?.map(c => <div key={c.id} className="mt-3 border-t border-slate-100 pt-3"><p>{explain(c.action)} · {explain(c.state)}</p><p className="mt-1 text-xs text-slate-500">{c.reason}</p>{c.request_key && !['verified', 'previewed'].includes(c.state) && <button className="mt-2 text-xs text-teal-700 underline" type="button" onClick={() => { setPreview(c); executeKey.current = c.request_key; }}>Check saved correction</button>}</div>)}<div className="pb-3" /></details>
                </section>
            </>}
        </div><div ref={setFooterTarget} className="shrink-0" /></div>
    </dialog>;
}

function CollectionChoiceForm({ row, mode, busy, onSubmit, onCancel }) {
    const { user } = useAuth();
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState('newest');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
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
    return <details className="group pb-1 text-sm" onToggle={event => setOpen(event.currentTarget.open)}>
        <summary className={accordion}>Collector reconciliation<Icon name="chevron" className="h-4 w-4 text-slate-400 transition-transform group-open:rotate-90" /></summary>
        <p className="mt-2 text-xs text-slate-500">Customer payment and remittance to Exotic are separate. Totals below belong to this receiving account and currency.</p>
        {query.isFetching && <p role="status" className="mt-3 text-xs text-slate-500">Checking reconciliation…</p>}
        {query.isError && <p role="alert" className="mt-3 text-xs text-rose-800">Reconciliation could not be loaded. <button className="underline" type="button" onClick={() => query.refetch()}>Retry</button></p>}
        {data && <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3">{[['Received', 'gross_received_minor'], ['Statement credits', 'statement_gross_minor'], ['Remitted to Exotic', 'remitted_minor'], ['Closing amount due', 'closing_due_minor']].map(([label, field]) => <div key={field}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 font-medium">{money({ currency: data.currency, amount_minor: data[field] })}</dd></div>)}</dl>}
    </details>;
}
