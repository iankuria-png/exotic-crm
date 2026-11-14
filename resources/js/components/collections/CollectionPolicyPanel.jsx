import React, { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import CollectionAutomationControls, { automationHeadline } from './CollectionAutomationControls';
import { Icon, StatusPill, primaryButton, quietButton, secondaryButton } from './collectionUi';

const caps = { single_cap_minor: 'Per payment', daily_cap_minor: 'Per day', unreviewed_cap_minor: 'Awaiting sales review', unverified_cap_minor: 'Awaiting statement verification' };
const showAmount = value => value === null ? '' : (value / 100).toFixed(2);
function minor(value) {
    if (! /^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/.test(value)) throw new Error('Enter an amount with up to two decimal places.');
    const [whole, fraction = ''] = value.split('.');
    const result = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    if (!Number.isSafeInteger(result) || result > 900000000000000) throw new Error('The amount is too large.');
    return result;
}

export function useCollectionPolicies() {
    const { user } = useAuth();
    return useQuery({ queryKey: ['collection-policies', user?.id], queryFn: async () => (await api.get('/crm/collections/account-policies')).data,
        refetchInterval: () => document.visibilityState === 'visible' ? 10000 : false, refetchOnWindowFocus: true });
}

export function CollectionAutomationSummary({ onConfigure }) {
    const query = useCollectionPolicies();
    const accounts = query.data?.accounts || [];
    const account = accounts.find(a => a.automation?.enabled) || accounts[0];
    const policy = account?.automation;
    const status = policy?.status;
    const head = policy ? automationHeadline(policy, status) : null;
    const pending = accounts.flatMap(a => a.automation?.readiness || []).filter(check => !check.ready);
    const finance = pending.filter(check => check.group === 'finance' || ['independent_reconciliation_accepted_at', 'settlement_policy_accepted_at'].includes(check.key)).length;
    const title = query.isPending ? 'Checking automatic activation…' : query.isError ? 'Automatic activation status unavailable' : !accounts.length ? 'No receiving account in this view' : !head ? 'Automatic activation · Not configured'
        : `Automatic activation · ${head.state === 'live' ? 'Live' : head.state === 'held' ? 'Held' : head.state === 'pilot_ended' ? 'Pilot ended' : status?.can_enable ? 'Ready to switch on' : 'Paused'}`;
    const line = !policy ? null : head.state === 'live' ? [head.pilot, status?.activity ? `${status.activity.automatic} of ${status.activity.received} new ${status.activity.received === 1 ? 'payment' : 'payments'} activated automatically` : null].filter(Boolean).join(' · ') || 'Eligible new payments activate automatically. Exceptions stay for staff review.'
        : head.state === 'held' ? 'Switched on, but held: new payments wait for staff until the failing checks pass.'
        : head.state === 'pilot_ended' ? 'The 24-hour pilot ended. New payments wait for staff review.'
        : status?.can_enable ? 'All go-live checks pass. A manager can switch it on.'
        : finance ? `Awaiting Finance acceptance and remaining activation checks · ${pending.length} open.` : `${pending.length} go-live ${pending.length === 1 ? 'check' : 'checks'} open. Staff activate payments meanwhile.`;
    return <section aria-label="Automatic activation status" className={`mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm ${head?.tone === 'live' ? 'border-teal-200 bg-teal-50/70' : head?.tone === 'action' ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'}`}>
        <div className="flex min-w-0 flex-1 items-start gap-3">
            {head && <span className="mt-0.5"><StatusPill tone={head.tone} pulse={head.pulse}>{head.badge}</StatusPill></span>}
            <div className="min-w-0"><p className="font-semibold text-slate-900">{title}</p>
                {line && <p className="mt-0.5 tabular-nums text-slate-600">{line}</p>}
                {query.isError && <p className="mt-1 text-slate-600">Refresh the status before relying on automatic activation. <button type="button" className="text-teal-800 underline" onClick={() => query.refetch()}>Retry status</button></p>}</div>
        </div>
        <button type="button" onClick={onConfigure} className={status?.can_enable || head?.tone === 'action' ? primaryButton : secondaryButton}>Automation settings</button>
    </section>;
}

export default function CollectionPolicyPanel({ open, onClose }) {
    const { user } = useAuth();
    const manager = ['admin', 'sub_admin'].includes(user?.role);
    const query = useCollectionPolicies();
    const dialog = useRef(null);
    useEffect(() => {
        const modal = dialog.current;
        if (!open || !modal) return undefined;
        const trigger = document.activeElement;
        modal.showModal();
        return () => { modal.close(); if (trigger?.isConnected) trigger.focus(); };
    }, [open]);
    if (!open) return null;
    return <dialog ref={dialog} id="collection-automation-settings" aria-labelledby="collection-automation-title" onCancel={event => { event.preventDefault(); onClose(); }} onClick={event => { if (event.target === event.currentTarget) onClose(); }}
        className="m-0 ml-auto h-dvh max-h-dvh w-full max-w-2xl border-0 bg-slate-50 p-0 text-slate-900 shadow-2xl backdrop:bg-slate-900/40">
        <div className="flex h-full flex-col" onClick={e => e.stopPropagation()}>
            <header className="flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4"><div><p className="text-xs font-medium uppercase tracking-wide text-slate-500">Incoming office payments</p><h2 id="collection-automation-title" className="mt-1 text-xl font-semibold">Automatic activation</h2><p className="mt-1 text-sm text-slate-500">Go-live checks, rules and exposure limits for each receiving account.</p></div>
                <button type="button" className={secondaryButton} onClick={onClose} autoFocus><Icon name="close" />Close</button></header>
            <div className="flex-1 space-y-6 overflow-y-auto p-5">
                {query.isPending && <div role="status" className="space-y-3" aria-label="Loading automatic activation">{[0, 1, 2].map(i => <div key={i} className="h-20 rounded-xl bg-slate-200/70 motion-safe:animate-pulse" />)}</div>}
                {query.isError && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">Automatic activation settings could not be loaded. <button type="button" className="font-medium underline" onClick={() => query.refetch()}>Retry</button></p>}
                {query.data?.accounts?.map(account => <div key={account.account_id} className="space-y-5"><CollectionAutomationControls account={account} manager={manager} /><AccountLimits account={account} manager={manager} /></div>)}
                {query.data?.accounts?.length === 0 && <p className="text-sm text-slate-500">No receiving account is available in your market scope.</p>}
            </div>
        </div>
    </dialog>;
}

function AccountLimits({ account, manager }) {
    const client = useQueryClient();
    const [editing, setEditing] = useState(null);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const update = useMutation({ mutationFn: async payload => (await api.put(`/crm/collections/accounts/${account.account_id}/policy`, payload)).data,
        onSuccess: () => { setEditing(null); setError(''); setSaved(true); client.invalidateQueries({ queryKey: ['collection-policies'] }); },
        onError: failure => setError(failure.response?.status === 409 ? 'Another staff member changed these limits. Your entries are preserved. Reload the latest limits before trying again.' : failure.response?.data?.message || 'Limits could not be saved. Your entries are preserved.') });
    function edit() {
        const values = Object.fromEntries(Object.keys(caps).map(cap => [cap, { unlimited: account.configured && account.limits[cap] === null, amount: account.limits[cap] == null ? '' : showAmount(account.limits[cap]) }]));
        setEditing({ version: account.version, values }); setSaved(false); setError('');
    }
    function save(event) {
        event.preventDefault();
        try { update.mutate({ expected_version: editing.version, ...Object.fromEntries(Object.entries(editing.values).map(([cap, value]) => [cap, value.unlimited ? null : minor(value.amount)])) }); }
        catch (failure) { setError(failure.message); }
    }
    return <section aria-label="Exposure limits" className="rounded-xl border border-slate-200 bg-white p-4"><div className="flex items-start justify-between gap-3"><div className="min-w-0"><h5 className="text-sm font-semibold text-slate-900">Exposure limits</h5><p className="mt-0.5 text-xs text-slate-500">Caps on what automatic activation may grant before staff review. Saving limits does not switch anything on.</p></div>{manager && !editing && <button type="button" onClick={edit} className={`${quietButton} shrink-0`}>Configure limits</button>}</div>
        {!account.configured && !editing && <p className="mt-2 text-sm text-amber-900">Limits have not been approved. Automatic activation stays held.</p>}
        {saved && <p role="status" className="mt-2 text-sm text-teal-800">Limits saved.</p>}
        {editing ? <form onSubmit={save} className="mt-3"><div className="grid gap-4 sm:grid-cols-2">{Object.entries(caps).map(([cap, label]) => <fieldset key={cap} className="min-w-0"><legend className="text-sm font-medium text-slate-700">{label}</legend>
            <label className="mt-2 flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" checked={editing.values[cap].unlimited} onChange={e => setEditing(current => ({ ...current, values: { ...current.values, [cap]: { ...current.values[cap], unlimited: e.target.checked } } }))} />Unlimited</label>
            {!editing.values[cap].unlimited && <label className="mt-1 block text-xs text-slate-600">Amount in {account.currency}<input className="crm-input mt-1 w-full" inputMode="decimal" required value={editing.values[cap].amount} onChange={e => setEditing(current => ({ ...current, values: { ...current.values, [cap]: { ...current.values[cap], amount: e.target.value } } }))} /></label>}
        </fieldset>)}</div>
            {error && <p role="alert" className="mt-3 text-sm text-rose-800">{error}</p>}
            <p className="mt-3 text-xs text-slate-500">A limit of zero prevents new automatic exposure. Existing payment and review totals are retained when limits change.</p>
            <div className="mt-4 flex flex-wrap gap-3"><button type="submit" disabled={update.isPending} className={primaryButton}>{update.isPending ? 'Saving…' : 'Save limits'}</button><button type="button" disabled={update.isPending} onClick={() => { setEditing(null); setError(''); }} className={secondaryButton}>Cancel</button>{error && <button type="button" onClick={() => client.invalidateQueries({ queryKey: ['collection-policies'] })} className="rounded px-2 py-2 text-sm text-teal-700">Reload latest limits</button>}</div>
            {editing.version !== account.version && <p role="status" className="mt-3 text-sm text-amber-900">Latest saved version loaded. Your edits are preserved. <button type="button" className="underline" onClick={() => setEditing(current => ({ ...current, version: account.version }))}>Use this version for my next save</button></p>}
        </form> : account.configured && <dl className="mt-3 grid gap-3 sm:grid-cols-2">{Object.entries(caps).map(([cap, label]) => <div key={cap}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 text-sm text-slate-900">{account.limits[cap] === null ? 'Unlimited' : `${account.currency} ${showAmount(account.limits[cap])}`}</dd></div>)}</dl>}
    </section>;
}
