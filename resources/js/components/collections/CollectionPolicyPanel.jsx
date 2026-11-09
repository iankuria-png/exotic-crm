import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import CollectionAutomationControls from './CollectionAutomationControls';

const caps = { single_cap_minor: 'Per payment', daily_cap_minor: 'Per day', unreviewed_cap_minor: 'Awaiting sales review', unverified_cap_minor: 'Awaiting statement verification' };
const showAmount = value => value === null ? '' : (value / 100).toFixed(2);
function minor(value) {
    if (! /^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/.test(value)) throw new Error('Enter an amount with up to two decimal places.');
    const [whole, fraction = ''] = value.split('.');
    const result = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    if (!Number.isSafeInteger(result) || result > 900000000000000) throw new Error('The amount is too large.');
    return result;
}

export default function CollectionPolicyPanel() {
    const { user } = useAuth();
    const manager = ['admin', 'sub_admin'].includes(user?.role);
    const query = useQuery({ queryKey: ['collection-policies', user?.id], queryFn: async () => (await api.get('/crm/collections/account-policies')).data,
        refetchOnWindowFocus: true });
    return <details className="mb-5 border-b border-slate-200 pb-5"><summary className="cursor-pointer py-2 text-sm font-semibold text-slate-800">Automatic activation limits</summary>
        <p className="mt-2 text-sm text-slate-600">Limits control exposure from automatic activation. Unlimited keeps the payment and review totals recorded. Saving limits does not enable automatic activation.</p>
        {query.isPending && <p className="mt-3 text-sm text-slate-500">Loading limits…</p>}
        {query.isError && <p role="alert" className="mt-3 text-sm text-rose-800">Limits could not be loaded. <button type="button" className="underline" onClick={() => query.refetch()}>Retry</button></p>}
        {query.data?.accounts?.map(account => <AccountLimits key={account.account_id} account={account} manager={manager} />)}
        {query.data?.accounts?.length === 0 && <p className="mt-3 text-sm text-slate-500">No receiving account is available in your market scope.</p>}
    </details>;
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
    return <div className="mt-4 rounded-lg border border-slate-200 bg-white p-4"><div className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm font-semibold text-slate-900">{account.label} · {account.currency}</p>{manager && !editing && <button type="button" onClick={edit} className="rounded px-2 py-2 text-sm font-medium text-teal-700 focus-visible:ring-2 focus-visible:ring-teal-600">Configure limits</button>}</div>
        {!account.configured && !editing && <p className="mt-2 text-sm text-amber-900">Limits have not been approved. Automatic activation stays held.</p>}
        {saved && <p role="status" className="mt-2 text-sm text-teal-800">Limits saved.</p>}
        {editing ? <form onSubmit={save} className="mt-3"><div className="grid gap-4 sm:grid-cols-2">{Object.entries(caps).map(([cap, label]) => <fieldset key={cap} className="min-w-0"><legend className="text-sm font-medium text-slate-700">{label}</legend>
            <label className="mt-2 flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" checked={editing.values[cap].unlimited} onChange={e => setEditing(current => ({ ...current, values: { ...current.values, [cap]: { ...current.values[cap], unlimited: e.target.checked } } }))} />Unlimited</label>
            {!editing.values[cap].unlimited && <label className="mt-1 block text-xs text-slate-600">Amount in {account.currency}<input className="crm-input mt-1 w-full" inputMode="decimal" required value={editing.values[cap].amount} onChange={e => setEditing(current => ({ ...current, values: { ...current.values, [cap]: { ...current.values[cap], amount: e.target.value } } }))} /></label>}
        </fieldset>)}</div>
            {error && <p role="alert" className="mt-3 text-sm text-rose-800">{error}</p>}
            <p className="mt-3 text-xs text-slate-500">A limit of zero prevents new automatic exposure. Existing payment and review totals are retained when limits change.</p>
            <div className="mt-4 flex flex-wrap gap-3"><button type="submit" disabled={update.isPending} className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{update.isPending ? 'Saving…' : 'Save limits'}</button><button type="button" disabled={update.isPending} onClick={() => { setEditing(null); setError(''); }} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">Cancel</button>{error && <button type="button" onClick={() => client.invalidateQueries({ queryKey: ['collection-policies'] })} className="rounded px-2 py-2 text-sm text-teal-700">Reload latest limits</button>}</div>
            {editing.version !== account.version && <p role="status" className="mt-3 text-sm text-amber-900">Latest saved version loaded. Your edits are preserved. <button type="button" className="underline" onClick={() => setEditing(current => ({ ...current, version: account.version }))}>Use this version for my next save</button></p>}
        </form> : account.configured && <dl className="mt-3 grid gap-3 sm:grid-cols-2">{Object.entries(caps).map(([cap, label]) => <div key={cap}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 text-sm text-slate-900">{account.limits[cap] === null ? 'Unlimited' : `${account.currency} ${showAmount(account.limits[cap])}`}</dd></div>)}</dl>}
        <CollectionAutomationControls account={account} manager={manager} />
    </div>;
}
