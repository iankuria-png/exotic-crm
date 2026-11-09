import React, { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';

export default function CollectionAutomationControls({ account, manager }) {
    const policy = account.automation;
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState(false);
    const [threshold, setThreshold] = useState('');
    const [age, setAge] = useState('');
    const [purchaseMatching, setPurchaseMatching] = useState('reviewed_only');
    const [reason, setReason] = useState('');
    const [pilot, setPilot] = useState(false);
    const [version, setVersion] = useState(account.version);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const mutation = useMutation({ mutationFn: async body => (await api.put(`/crm/collections/accounts/${account.account_id}/policy`, body)).data,
        onSuccess: async () => { setEditing(false); setReason(''); setError(''); setNotice('Automatic activation policy saved.'); await queryClient.invalidateQueries({ queryKey: ['collection-policies'] }); },
        onError: failure => setError(failure.response?.status === 409 ? 'The saved policy or a readiness check changed. Reload the latest policy and review before trying again.' : failure.response?.data?.message || 'The policy could not be saved. Your entries are preserved.') });
    if (!policy) return null;
    function edit() { setPurchaseMatching(policy.purchase_matching || 'reviewed_only'); setThreshold(String(policy.match_threshold)); setAge(policy.max_auto_age_seconds == null ? '' : String(policy.max_auto_age_seconds)); setVersion(account.version); setPilot(false); setEditing(true); setError(''); setNotice(''); }
    function save(enabled = policy.enabled) {
        if (!/^(?:[0-9]|[1-9][0-9]|100)$/.test(threshold) || !/^\d+$/.test(age) || Number(age) < 60 || Number(age) > 86400 || reason.trim().length < 3) {
            setError('Choose a score from 0 to 100, a payment age from 60 to 86,400 seconds, and explain the change.'); return;
        }
        mutation.mutate({ expected_version: version, automation_enabled: enabled, match_threshold: Number(threshold), max_auto_age_seconds: Number(age), purchase_matching: purchaseMatching, reason: reason.trim(),
            ...(!policy.enabled && enabled && pilot ? { observation_pilot_hours: 24 } : {}) });
    }
    const pending = (policy.readiness || []).filter(check => !check.ready);
    return <section aria-label={`Automatic activation for ${account.label}`} className="mt-4 space-y-3 border-t border-slate-200 pt-4">
        <div className="flex flex-wrap items-center justify-between gap-3"><h4 className="text-sm font-semibold text-slate-900">Automatic activation · {policy.enabled ? 'Enabled' : 'Paused'}</h4>
            {manager && !editing && <button type="button" className="py-2 text-sm font-medium text-teal-800 underline" onClick={edit}>Configure automatic activation</button>}</div>
        <p className="text-sm text-slate-600">Match score strictly above {policy.match_threshold} / 100. Maximum payment age: {policy.max_auto_age_seconds == null ? 'awaiting approval' : `${policy.max_auto_age_seconds / 60} minutes`}. New payments only; earlier receipts stay for staff review.</p>
        {policy.default_packages?.map(p => <p key={p.id} className="text-sm text-slate-700">Automatic default · {p.currency} {Number(p.amount).toLocaleString()} → {p.name} · {p.duration_days} days{p.active ? '' : ' · unavailable; held for review'}</p>)}
        {policy.observation_exception && <p className="text-xs text-slate-600">Observation-only exception expires {new Date(policy.observation_exception.expires_at).toLocaleString('en-GB', { timeZone: 'Africa/Dar_es_Salaam' })} EAT. Identity, purchase, phone, statement and correction requirements still apply.</p>}
        <details><summary className="cursor-pointer py-2 text-sm font-medium text-slate-700">Readiness · {pending.length ? `${pending.length} checks pending` : 'required checks recorded'}</summary><ul className="mt-2 space-y-2 text-sm">{(policy.readiness || []).map(check => <li key={check.key} className={check.ready ? 'text-teal-800' : 'text-amber-900'}>{check.ready ? 'Ready' : 'Pending'} · {check.label}</li>)}</ul></details>
        {notice && <p role="status" className="text-sm text-teal-800">{notice}</p>}
        {editing && <div className="space-y-3 rounded-lg bg-slate-50 p-3"><div className="grid gap-3 sm:grid-cols-2"><label className="text-sm font-medium">Match score must be above<input className="crm-input mt-1 w-full" type="number" min="0" max="100" value={threshold} onChange={e => setThreshold(e.target.value)} /></label>
            <label className="text-sm font-medium">Maximum payment age<select className="crm-input mt-1 w-full" value={age} onChange={e => setAge(e.target.value)}><option value="">Choose approved payment age</option>{age && !['3600', '86400'].includes(age) && <option value={age}>{Number(age) / 60} minutes (current)</option>}<option value="3600">One hour</option><option value="86400">24 hours</option></select></label></div>
            <label className="block text-sm font-medium">Package matching<select className="crm-input mt-1 w-full" value={purchaseMatching} onChange={e => setPurchaseMatching(e.target.value)}><option value="exact_catalogue_or_reviewed">Use a unique exact catalogue package or reviewed purchase</option><option value="reviewed_only">Use reviewed purchases only</option></select></label>
            <label className="block text-sm font-medium">Reason for this change<textarea className="crm-input mt-1 w-full" rows={2} value={reason} onChange={e => setReason(e.target.value)} /></label>
            {!policy.enabled && !policy.observation_exception && <label className="flex items-start gap-2 text-sm"><input className="mt-1" type="checkbox" checked={pilot} onChange={e => setPilot(e.target.checked)} />Record the approved 24-hour TZ exception for observation days and credit count only.</label>}
            <p className="text-xs text-slate-600">Changing the threshold or payment age starts a new eligibility period. Earlier queued receipts stay manual. Enabling checks all required acceptance evidence again.</p>
            {version !== account.version && <p role="status" className="text-sm text-amber-900">A newer policy is available. Your entries are retained. <button type="button" className="underline" onClick={() => setVersion(account.version)}>Use latest version for next save</button></p>}
            {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}
            <div className="flex flex-wrap gap-3"><button type="button" className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" disabled={mutation.isPending} onClick={() => save()}>Save policy</button>
                {!policy.enabled && <button type="button" className="rounded-lg bg-teal-700 px-3 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={mutation.isPending || pending.some(check => check.key !== 'age')} onClick={() => save(true)}>Enable for new payments</button>}
                {policy.enabled && <button type="button" className="rounded-lg border border-amber-600 bg-white px-3 py-2 text-sm font-medium text-amber-900" disabled={mutation.isPending || reason.trim().length < 3} onClick={() => mutation.mutate({ expected_version: version, automation_enabled: false, reason: reason.trim() })}>Pause automatic activation</button>}
                <button type="button" className="py-2 text-sm text-slate-600" disabled={mutation.isPending} onClick={() => setEditing(false)}>Cancel</button>
                {error && <button type="button" className="py-2 text-sm text-teal-800 underline" onClick={() => queryClient.invalidateQueries({ queryKey: ['collection-policies'] })}>Reload latest policy</button>}</div>
        </div>}
    </section>;
}
