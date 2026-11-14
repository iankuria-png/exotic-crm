import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import { primaryButton, secondaryButton, quietButton } from './collectionUi';

export default function CollectionPackageRules({ account, manager }) {
    const { user } = useAuth();
    const client = useQueryClient();
    const policy = account.automation;
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(false);
    const [modes, setModes] = useState({});
    const [reason, setReason] = useState('');
    const [selected, setSelected] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [version, setVersion] = useState(account.version);
    const evidence = useQuery({ queryKey: ['collection-package-rules', user?.id, account.account_id, account.version],
        queryFn: () => api.get(`/crm/collections/accounts/${account.account_id}/package-rule-evidence`).then(r => r.data), enabled: manager && open });
    const mutation = useMutation({ mutationFn: body => api.put(`/crm/collections/accounts/${account.account_id}/policy`, body),
        onSuccess: () => { setEditing(false); setSelected(null); setReason(''); setError(''); setNotice('Automation rules saved. Existing receipts stay in their current workflow.'); client.invalidateQueries({ queryKey: ['collection-policies'] }); client.invalidateQueries({ queryKey: ['collection-package-rules'] }); },
        onError: e => setError(e.response?.status === 409 ? 'The policy or evidence changed. Your entries are kept. Reload and review the latest evidence before saving.' : e.response?.data?.message || 'Rules could not be saved. Your entries are kept.') });
    function editModes() {
        setVersion(account.version); setReason(''); setError(''); setNotice(''); setSelected(null);
        setModes({ continuous_operation: Boolean(policy.continuous_operation), receipt_learning: Boolean(policy.receipt_learning?.enabled), independent_subsidiary: Boolean(policy.independent_subsidiary) }); setEditing(true);
    }
    function submit(event) {
        event.preventDefault();
        if (reason.trim().length < 3) { setError('Explain the rule change for the audit trail.'); return; }
        const changes = selected ? { approve_package_default: { key: selected.key, product_price_id: selected.product_price_id, source_digest: selected.source_digest } } : modes;
        mutation.mutate({ expected_version: version, reason: reason.trim(), ...changes });
    }
    return <section aria-label="Learning and paired-market rules" className="rounded-xl border border-slate-200 bg-white p-4">
        <div className="flex flex-wrap items-center justify-between gap-3"><h5 className="text-sm font-semibold text-slate-900">Learning &amp; paired markets</h5>{manager && policy.continuous_available && !editing && !selected && <button type="button" className={quietButton} onClick={editModes}>Configure automation</button>}</div>
        <dl className="mt-3 space-y-2 text-sm"><div className="flex flex-wrap justify-between gap-2"><dt className="text-slate-600">Operation</dt><dd className="font-medium">{policy.continuous_operation ? 'Continuous · until paused' : 'Existing observation policy'}</dd></div><div className="flex flex-wrap justify-between gap-2"><dt className="text-slate-600">Learn from confirmed SMS payments</dt><dd className="font-medium">{policy.receipt_learning?.enabled ? 'On · one clean confirmation' : 'Off'}</dd></div><div className="flex flex-wrap justify-between gap-2"><dt className="text-slate-600">Tanzania after TZ activation</dt><dd className="font-medium">{policy.independent_subsidiary ? 'Automatic · separate recovery' : 'Existing approval workflow'}</dd></div></dl>
        {notice && <p role="status" className="mt-3 text-sm text-teal-800">{notice}</p>}
        {(editing || selected) && <form onSubmit={submit} aria-label={selected ? 'Approve package default' : 'Configure payment automation'} className="mt-4 space-y-3 border-t border-slate-100 pt-4">
            {selected ? <div><p className="text-sm font-semibold">{selected.currency} {(selected.amount_minor / 100).toLocaleString()} → {selected.name}</p><p className="mt-1 text-xs text-slate-600">{selected.qualifying_count} confirmed receipts · {selected.client_count} clients · last 90 days. Review the alternatives below before approving.</p></div> : <>
                <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={modes.continuous_operation} onChange={e => setModes(m => ({ ...m, continuous_operation: e.target.checked }))} /><span>Continuous operation — no pilot expiry<span className="mt-1 block text-xs text-slate-500">Pause remains available. Funds, device, client and package checks still apply.</span></span></label>
                <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={modes.receipt_learning} onChange={e => setModes(m => ({ ...m, receipt_learning: e.target.checked }))} /><span>Learn the payer and package from one clean staff confirmation<span className="mt-1 block text-xs text-slate-500">Shared payers and contradictory package choices stay for staff review.</span></span></label>
                <label className="flex items-start gap-2 text-sm"><input type="checkbox" disabled={user?.role !== 'admin'} checked={modes.independent_subsidiary} onChange={e => setModes(m => ({ ...m, independent_subsidiary: e.target.checked }))} /><span>Activate Tanzania after the paid TZ subscription<span className="mt-1 block text-xs text-slate-500">Admin authority includes creating and copying a new Tanzania profile. A target failure leaves TZ active.</span></span></label>
            </>}
            <label className="block text-sm font-medium">Reason for this automation change<textarea className="crm-input mt-1 w-full" rows={2} value={reason} onChange={e => setReason(e.target.value)} /></label>
            {error && <p role="alert" className="text-sm text-rose-800">{error} <button type="button" className="underline" onClick={() => { evidence.refetch(); client.invalidateQueries({ queryKey: ['collection-policies'] }); }}>Reload latest evidence</button></p>}
            {version !== account.version && <p role="status" className="text-xs text-amber-900">A newer policy is available. Your edits are kept. <button type="button" className="underline" onClick={() => setVersion(account.version)}>Use latest version</button></p>}
            <div className="flex flex-wrap gap-3"><button type="submit" disabled={mutation.isPending} className={primaryButton}>{mutation.isPending ? 'Saving…' : selected ? 'Approve this default' : 'Save automation rules'}</button><button type="button" disabled={mutation.isPending} className={secondaryButton} onClick={() => { setEditing(false); setSelected(null); setError(''); }}>Cancel</button></div>
        </form>}
        {manager && <details className="mt-4 border-t border-slate-100 pt-3" onToggle={event => setOpen(event.currentTarget.open)}><summary className="cursor-pointer text-sm font-medium text-teal-800">Package evidence &amp; suggested defaults</summary>
            <div className="mt-3 space-y-3">{evidence.isPending && <p role="status" className="text-sm text-slate-500">Checking confirmed payment history…</p>}{evidence.isError && <p role="alert" className="text-sm text-rose-800">Payment history could not be loaded. <button type="button" className="underline" onClick={() => evidence.refetch()}>Retry evidence</button></p>}
                {evidence.data && <p className="text-xs text-slate-500">{evidence.data.qualifying_count} qualifying receipts · {evidence.data.excluded_count} excluded · last {evidence.data.lookback_days} days{evidence.data.complete ? '' : ' · incomplete coverage; approvals held'}</p>}
                {evidence.data?.suggestions?.length === 0 && <p className="text-sm text-slate-600">No confirmed package history is available yet. Staff confirmations will appear here.</p>}
                {evidence.data?.suggestions?.map(row => <div key={row.key} className="rounded-lg border border-slate-200 p-3"><div className="flex flex-wrap justify-between gap-2"><p className="text-sm font-semibold">{row.currency} {(row.amount_minor / 100).toLocaleString()}</p><span className="text-xs text-slate-500">{row.qualifying_count} receipts · {row.client_count} clients</span></div><ul className="mt-2 space-y-1 text-xs text-slate-600">{row.alternatives.map((a, i) => <li key={i}>{a.tier?.toUpperCase()} · {a.duration_days} days · {a.count} {a.count === 1 ? 'receipt' : 'receipts'}{a.product_price_id ? ` · price ${a.product_price_id}` : ' · no catalogue price'}</li>)}</ul>{row.approved ? <p className="mt-2 text-xs font-medium text-teal-800">Approved default · price {row.approved}</p> : row.eligible ? <button type="button" className={`${quietButton} mt-2`} onClick={() => { setSelected(row); setEditing(false); setVersion(account.version); setReason(''); setError(''); }}>Review default</button> : <p className="mt-2 text-xs text-amber-900">Staff choice: the history is mixed, too small or has no current exact package.</p>}</div>)}
            </div></details>}
    </section>;
}
