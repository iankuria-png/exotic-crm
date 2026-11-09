import React, { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { CheckMark, Icon, StatusPill, eat, primaryButton, quietButton, remaining, secondaryButton, warnButton } from './collectionUi';

const groups = [
    ['finance', 'Finance sign-offs', 'Recorded by a manager on Finance’s behalf, with who accepted and the evidence.'],
    ['operations', 'Operations sign-offs', 'Confirms the phone, message reading and correction tools behave in real use.'],
    ['system', 'System checks', 'Checked automatically by CRM every time. They cannot be signed off by hand.'],
];
const ageLabel = seconds => seconds == null ? 'Not set' : seconds % 3600 === 0 ? `${seconds / 3600} ${seconds === 3600 ? 'hour' : 'hours'}` : `${Math.round(seconds / 60)} minutes`;
const matchingLabel = value => value === 'exact_catalogue_or_reviewed' ? 'Unique exact catalogue package, or a reviewed purchase' : 'Reviewed purchases only';

function useNow() {
    const [now, setNow] = useState(Date.now());
    useEffect(() => { const timer = setInterval(() => setNow(Date.now()), 30000); return () => clearInterval(timer); }, []);
    return now;
}

export function automationHeadline(policy, status, now = Date.now()) {
    const state = status?.live_state || (policy?.enabled ? 'live' : 'paused');
    const readiness = policy?.readiness || [];
    const done = readiness.filter(check => check.ready).length;
    if (state === 'live') return { state, tone: 'live', badge: 'Live', title: 'Automatic activation is live', pulse: true,
        text: `New payments that match one profile above ${policy.match_threshold} / 100 and a configured package activate on their own. Everything else waits here for staff.`,
        pilot: status?.pilot?.state === 'active' ? `24-hour pilot · ends ${eat(status.pilot.expires_at)} · ${remaining(status.pilot.expires_at, now)}` : null };
    if (state === 'held') return { state, tone: 'action', badge: 'Held', title: 'Switched on, but held',
        text: 'New payments wait for staff until the checks marked below pass again. Nothing activates automatically meanwhile.' };
    if (state === 'pilot_ended') return { state, tone: 'action', badge: 'Pilot ended', title: 'The 24-hour pilot has ended',
        text: `It ended ${eat(status.pilot.expires_at)}. New payments now wait for staff. Continuous operation needs 7 clean observation days and 100 receiving credits on record.` };
    if (status?.can_enable) return { state, tone: 'neutral', badge: 'Off', title: 'Ready to switch on',
        text: 'Every sign-off and system check passes. Staff keep activating payments by hand until a manager switches this on.' };
    return { state, tone: 'neutral', badge: 'Off', title: `${done} of ${readiness.length} go-live checks complete`,
        text: 'Staff keep matching and activating payments by hand. Record the remaining sign-offs to switch on.' };
}

export default function CollectionAutomationControls({ account, manager }) {
    const policy = account.automation;
    const status = policy?.status;
    const now = useNow();
    const [panel, setPanel] = useState(null);
    if (!policy) return null;
    const head = automationHeadline(policy, status, now);
    const readiness = policy.readiness || [];
    const done = readiness.filter(check => check.ready).length;
    const allReady = done === readiness.length;
    return <section aria-label={`Automatic activation for ${account.label}`} className="space-y-5">
        <div className={`rounded-xl border p-4 sm:p-5 ${head.tone === 'live' ? 'border-teal-200 bg-gradient-to-br from-teal-50 to-white' : head.tone === 'action' ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200 bg-slate-50'}`}>
            <div className="flex flex-wrap items-center gap-2"><StatusPill tone={head.tone} pulse={head.pulse}>{head.badge}</StatusPill><span className="text-xs text-slate-500">{account.label} · {account.currency}</span></div>
            <h4 className="mt-3 text-lg font-semibold text-slate-900">{head.title}</h4>
            <p className="mt-1 max-w-2xl text-sm text-slate-700">{head.text}</p>
            {head.pilot && <p className="mt-3 inline-flex items-center gap-2 rounded-lg bg-white px-3 py-1.5 text-sm font-medium tabular-nums text-teal-900 ring-1 ring-inset ring-teal-200"><Icon name="pending" />{head.pilot}</p>}
            {head.state === 'held' && <ul className="mt-3 space-y-1 text-sm text-amber-950">{readiness.filter(check => !check.ready).map(check => <li key={check.key} className="flex gap-2"><Icon name="alert" className="mt-0.5 h-4 w-4" />{check.title || check.label}{check.action ? ` — ${check.action}` : ''}</li>)}</ul>}
            {!policy.enabled && !allReady && <div className="mt-4"><div className="h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Go-live checks complete" aria-valuemin={0} aria-valuemax={readiness.length} aria-valuenow={done}><div className="h-full rounded-full bg-teal-600 motion-safe:transition-[width]" style={{ width: `${readiness.length ? (done / readiness.length) * 100 : 0}%` }} /></div></div>}
            {status?.activity && policy.enabled && <dl className="mt-4 grid max-w-md grid-cols-2 gap-3">
                <div className="rounded-lg bg-white p-3 ring-1 ring-inset ring-slate-200"><dt className="text-xs text-slate-500">Activated automatically</dt><dd className="mt-1 text-xl font-semibold tabular-nums text-slate-900">{status.activity.automatic}</dd></div>
                <div className="rounded-lg bg-white p-3 ring-1 ring-inset ring-slate-200"><dt className="text-xs text-slate-500">New payments received</dt><dd className="mt-1 text-xl font-semibold tabular-nums text-slate-900">{status.activity.received}</dd></div>
                <p className="col-span-2 text-xs text-slate-500">Since switch-on {eat(status.activity.since)}</p>
            </dl>}
            {status?.last_change && <p className="mt-3 text-xs text-slate-500">Last change by {status.last_change.actor} · {eat(status.last_change.at)}{status.last_change.reason ? ` · “${status.last_change.reason}”` : ''}</p>}
            {manager && !panel && <div className="mt-4 flex flex-wrap gap-3">
                {!policy.enabled && <button type="button" className={primaryButton} disabled={!status?.can_enable || policy.max_auto_age_seconds == null} onClick={() => setPanel('enable')}><Icon name="bolt" />Switch on automatic activation</button>}
                {policy.enabled && <button type="button" className={warnButton} onClick={() => setPanel('pause')}><Icon name="pause" />Pause automatic activation</button>}
            </div>}
            {!manager && <p className="mt-3 text-xs text-slate-500">Only an admin or sub-admin can switch automatic activation on or off.</p>}
            {panel && <SwitchPanel account={account} mode={panel} onDone={() => setPanel(null)} />}
        </div>
        <Checklist account={account} manager={manager} defaultOpen={!allReady || head.state === 'held'} />
        <Rules account={account} manager={manager} />
    </section>;
}

function usePolicyMutation(path, method, onSuccess) {
    const queryClient = useQueryClient();
    const [error, setError] = useState('');
    const mutation = useMutation({ mutationFn: async body => (await api[method](path, body)).data,
        onSuccess: async data => { setError(''); await queryClient.invalidateQueries({ queryKey: ['collection-policies'] }); onSuccess?.(data); },
        onError: failure => setError(failure.response?.status === 409 && failure.response?.data?.reason === 'collection_policy_changed'
            ? 'Someone else changed this policy a moment ago. Your entries are kept — reload the latest version and review it before saving again.'
            : failure.response?.data?.message || 'This could not be saved. Your entries are kept; try again.') });
    return { mutation, error, setError, reload: () => queryClient.invalidateQueries({ queryKey: ['collection-policies'] }) };
}

function SwitchPanel({ account, mode, onDone }) {
    const policy = account.automation;
    const status = policy.status || {};
    const [reason, setReason] = useState('');
    const { mutation, error, setError, reload } = usePolicyMutation(`/crm/collections/accounts/${account.account_id}/policy`, 'put', onDone);
    const pilot = mode === 'enable' && !status.observation_met && status.pilot?.state !== 'active';
    const pilotEnd = new Date(Date.now() + 24 * 3600000).toISOString();
    function submit(event) {
        event.preventDefault();
        if (reason.trim().length < 3) { setError(mode === 'enable' ? 'Say why it is being switched on, for the audit trail.' : 'Say why it is being paused, for the audit trail.'); return; }
        mutation.mutate(mode === 'enable'
            ? { expected_version: account.version, automation_enabled: true, match_threshold: policy.match_threshold, max_auto_age_seconds: policy.max_auto_age_seconds, purchase_matching: policy.purchase_matching, reason: reason.trim(), ...(pilot ? { observation_pilot_hours: 24 } : {}) }
            : { expected_version: account.version, automation_enabled: false, reason: reason.trim() });
    }
    return <form onSubmit={submit} aria-label={mode === 'enable' ? 'Confirm switch on' : 'Confirm pause'} className="mt-4 space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <h5 className="font-semibold text-slate-900">{mode === 'enable' ? `Switch on for ${account.label}?` : 'Pause automatic activation?'}</h5>
        {mode === 'enable' ? <ul className="space-y-2 text-sm text-slate-700">
            <li className="flex gap-2"><Icon name="check" className="mt-0.5 h-4 w-4 text-teal-700" />Only payments received after you switch on. Payments already in the queue stay with staff.</li>
            <li className="flex gap-2"><Icon name="check" className="mt-0.5 h-4 w-4 text-teal-700" />One profile must match above {policy.match_threshold} / 100 and the payment must be under {ageLabel(policy.max_auto_age_seconds)} old.</li>
            <li className="flex gap-2"><Icon name="check" className="mt-0.5 h-4 w-4 text-teal-700" />{policy.default_packages?.length ? `Package: ${policy.default_packages.map(p => `${p.currency} ${Number(p.amount).toLocaleString()} → ${p.name} · ${p.duration_days} days`).join('; ')}.` : `Package: ${matchingLabel(policy.purchase_matching).toLowerCase()}.`}</li>
            {pilot && <li className="flex gap-2"><Icon name="pending" className="mt-0.5 h-4 w-4 text-teal-700" />Runs as the approved 24-hour pilot, ending about {eat(pilotEnd)}.</li>}
            <li className="flex gap-2"><Icon name="check" className="mt-0.5 h-4 w-4 text-teal-700" />Every activation can be confirmed, undone or moved to another profile from its receipt. You can pause at any time.</li>
        </ul> : <p className="text-sm text-slate-700">New payments will wait for staff review. Activations already made stay in place and can still be reviewed or corrected.</p>}
        <label className="block text-sm font-medium text-slate-800">{mode === 'enable' ? 'Reason for switching on' : 'Reason for pausing'}<textarea className="crm-input mt-1 w-full" rows={2} value={reason} onChange={e => setReason(e.target.value)} placeholder={mode === 'enable' ? 'e.g. Finance accepted the statement comparison on 9 Nov' : 'e.g. Office phone offline overnight'} /></label>
        {error && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{error} <button type="button" className="font-medium underline" onClick={reload}>Reload latest</button></p>}
        <div className="flex flex-wrap gap-3"><button type="submit" className={mode === 'enable' ? primaryButton : warnButton} disabled={mutation.isPending}>{mutation.isPending ? 'Saving…' : mode === 'enable' ? 'Switch on now' : 'Pause now'}</button><button type="button" className={secondaryButton} disabled={mutation.isPending} onClick={onDone}>Cancel</button></div>
    </form>;
}

function Checklist({ account, manager, defaultOpen }) {
    const readiness = account.automation.readiness || [];
    const done = readiness.filter(check => check.ready).length;
    return <details open={defaultOpen} className="group rounded-xl border border-slate-200 bg-white">
        <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden"><span className="text-sm font-semibold text-slate-900">Go-live checklist</span><span className="flex items-center gap-2 text-sm tabular-nums text-slate-600">{done} of {readiness.length} complete<Icon name="chevron" className="h-4 w-4 transition-transform group-open:rotate-90" /></span></summary>
        <div className="space-y-5 border-t border-slate-100 px-4 py-4">{groups.map(([group, title, help]) => {
            const items = readiness.filter(check => (check.group || 'system') === group);
            if (!items.length) return null;
            return <section key={group} aria-label={title}><div className="flex items-baseline justify-between gap-3"><h5 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h5><span className="text-xs tabular-nums text-slate-500">{items.filter(i => i.ready).length}/{items.length}</span></div><p className="mt-1 text-xs text-slate-500">{help}</p>
                <ul className="mt-3 divide-y divide-slate-100">{items.map(check => check.kind === 'signoff' ? <SignoffItem key={check.key} account={account} check={check} manager={manager} /> : <li key={check.key} className="flex gap-3 py-3"><CheckMark state={check.ready ? 'done' : 'blocked'} /><div className="min-w-0"><p className="text-sm font-medium text-slate-900">{check.label}</p>{!check.ready && check.action && <p className="mt-0.5 text-xs text-amber-900">{check.action}</p>}</div></li>)}</ul>
            </section>;
        })}</div>
    </details>;
}

function SignoffItem({ account, check, manager }) {
    const [mode, setMode] = useState(null);
    const [by, setBy] = useState('');
    const [note, setNote] = useState('');
    const { mutation, error, setError, reload } = usePolicyMutation(`/crm/collections/accounts/${account.account_id}/signoffs`, 'post', () => { setMode(null); setBy(''); setNote(''); });
    const evidence = check.evidence;
    function submit(event) {
        event.preventDefault();
        if (mode === 'record' && by.trim().length < 2) { setError('Name who accepted this, for example “Finance — Grace M.”'); return; }
        if (note.trim().length < 3) { setError(mode === 'record' ? 'Add the evidence or a short note.' : 'Say why this sign-off is withdrawn.'); return; }
        mutation.mutate({ key: check.key, expected_version: account.version, note: note.trim(), ...(mode === 'record' ? { accepted_by: by.trim() } : { withdraw: true }) });
    }
    return <li className="py-3"><div className="flex gap-3"><CheckMark state={check.ready ? 'done' : 'pending'} /><div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-start justify-between gap-2"><div className="min-w-0"><p className="text-sm font-medium text-slate-900">{check.title || check.label}</p><p className="mt-0.5 text-xs text-slate-500">{check.detail}</p></div>
            {manager && !mode && <button type="button" className={quietButton} onClick={() => { setMode(check.ready ? 'withdraw' : 'record'); setError(''); }} aria-label={`${check.ready ? 'Withdraw' : 'Record'} sign-off: ${check.title || check.label}`}>{check.ready ? 'Withdraw' : 'Record sign-off'}</button>}</div>
        {check.ready && evidence && <div className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600"><p><span className="font-medium text-slate-800">Accepted by {evidence.accepted_by}</span> · recorded by {evidence.recorded_by_name} · {eat(evidence.recorded_at)}</p>{evidence.note && <p className="mt-1 text-slate-600">“{evidence.note}”</p>}</div>}
        {check.ready && !evidence && <p className="mt-2 text-xs text-slate-500">Accepted {eat(check.accepted_at)} · recorded before sign-off notes were kept</p>}
        {mode && <form onSubmit={submit} className="mt-3 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3" aria-label={mode === 'record' ? `Record sign-off: ${check.title}` : `Withdraw sign-off: ${check.title}`}>
            {mode === 'record' && <label className="block text-sm font-medium text-slate-800">Who accepted it<input className="crm-input mt-1 w-full" value={by} onChange={e => setBy(e.target.value)} placeholder="e.g. Finance — Grace M." autoFocus /></label>}
            <label className="block text-sm font-medium text-slate-800">{mode === 'record' ? 'Evidence or note' : 'Why withdraw it'}<textarea className="crm-input mt-1 w-full" rows={2} value={note} onChange={e => setNote(e.target.value)} placeholder={mode === 'record' ? 'e.g. Statement 1–8 Nov matched all 176 captured receipts' : 'e.g. Parser updated; re-test needed'} autoFocus={mode === 'withdraw'} /></label>
            {mode === 'withdraw' && account.automation.enabled && <p className="text-xs text-amber-900">Withdrawing holds automatic activation until this is recorded again.</p>}
            {error && <p role="alert" className="text-sm text-rose-800">{error} {mutation.isError && <button type="button" className="font-medium underline" onClick={reload}>Reload latest</button>}</p>}
            <div className="flex flex-wrap gap-2"><button type="submit" className={mode === 'record' ? primaryButton : warnButton} disabled={mutation.isPending}>{mutation.isPending ? 'Saving…' : mode === 'record' ? 'Record sign-off' : 'Withdraw sign-off'}</button><button type="button" className={secondaryButton} disabled={mutation.isPending} onClick={() => { setMode(null); setError(''); }}>Cancel</button></div>
        </form>}
    </div></div></li>;
}

function Rules({ account, manager }) {
    const policy = account.automation;
    const [editing, setEditing] = useState(false);
    const [threshold, setThreshold] = useState('');
    const [age, setAge] = useState('');
    const [purchaseMatching, setPurchaseMatching] = useState('reviewed_only');
    const [reason, setReason] = useState('');
    const [version, setVersion] = useState(account.version);
    const [notice, setNotice] = useState('');
    const { mutation, error, setError, reload } = usePolicyMutation(`/crm/collections/accounts/${account.account_id}/policy`, 'put', () => { setEditing(false); setReason(''); setNotice('Rules saved.'); });
    function edit() { setPurchaseMatching(policy.purchase_matching || 'reviewed_only'); setThreshold(String(policy.match_threshold)); setAge(policy.max_auto_age_seconds == null ? '' : String(policy.max_auto_age_seconds)); setVersion(account.version); setEditing(true); setError(''); setNotice(''); }
    function save(event) {
        event.preventDefault();
        if (!/^(?:[0-9]|[1-9][0-9]|100)$/.test(threshold) || !/^\d+$/.test(age) || Number(age) < 60 || Number(age) > 86400 || reason.trim().length < 3) {
            setError('Choose a score from 0 to 100 and a payment age, and explain the change.'); return;
        }
        mutation.mutate({ expected_version: version, match_threshold: Number(threshold), max_auto_age_seconds: Number(age), purchase_matching: purchaseMatching, reason: reason.trim() });
    }
    return <section aria-label="Automatic activation rules" className="rounded-xl border border-slate-200 bg-white p-4">
        <div className="flex flex-wrap items-center justify-between gap-3"><h5 className="text-sm font-semibold text-slate-900">Rules</h5>{manager && !editing && <button type="button" className={quietButton} onClick={edit}>Edit rules</button>}</div>
        {notice && <p role="status" className="mt-2 text-sm text-teal-800">{notice}</p>}
        {!editing ? <dl className="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt className="text-xs text-slate-500">Profile match must be above</dt><dd className="mt-0.5 font-medium tabular-nums text-slate-900">{policy.match_threshold} / 100</dd></div>
            <div><dt className="text-xs text-slate-500">Maximum payment age</dt><dd className="mt-0.5 font-medium text-slate-900">{ageLabel(policy.max_auto_age_seconds)}</dd></div>
            <div><dt className="text-xs text-slate-500">Package matching</dt><dd className="mt-0.5 font-medium text-slate-900">{matchingLabel(policy.purchase_matching)}</dd></div>
            <div><dt className="text-xs text-slate-500">Which payments</dt><dd className="mt-0.5 font-medium text-slate-900">New payments only</dd></div>
            {!!policy.default_packages?.length && <div className="sm:col-span-2"><dt className="text-xs text-slate-500">Approved default package</dt>{policy.default_packages.map(p => <dd key={p.id} className="mt-0.5 font-medium text-slate-900">{p.currency} {Number(p.amount).toLocaleString()} → {p.name} · {p.duration_days} days{p.active ? '' : <span className="ml-2 text-xs font-normal text-amber-900">unavailable — held for review</span>}</dd>)}</div>}
        </dl> : <form onSubmit={save} className="mt-3 space-y-3" aria-label="Edit automatic activation rules">
            <div className="grid gap-3 sm:grid-cols-2"><label className="text-sm font-medium text-slate-800">Match score must be above<input className="crm-input mt-1 w-full" type="number" min="0" max="100" value={threshold} onChange={e => setThreshold(e.target.value)} /></label>
                <label className="text-sm font-medium text-slate-800">Maximum payment age<select className="crm-input mt-1 w-full" value={age} onChange={e => setAge(e.target.value)}><option value="">Choose approved payment age</option>{age && !['3600', '86400'].includes(age) && <option value={age}>{ageLabel(Number(age))} (current)</option>}<option value="3600">One hour</option><option value="86400">24 hours</option></select></label></div>
            <label className="block text-sm font-medium text-slate-800">Package matching<select className="crm-input mt-1 w-full" value={purchaseMatching} onChange={e => setPurchaseMatching(e.target.value)}><option value="exact_catalogue_or_reviewed">Use a unique exact catalogue package or reviewed purchase</option><option value="reviewed_only">Use reviewed purchases only</option></select></label>
            <label className="block text-sm font-medium text-slate-800">Reason for this change<textarea className="crm-input mt-1 w-full" rows={2} value={reason} onChange={e => setReason(e.target.value)} /></label>
            <p className="text-xs text-slate-500">Changing the score or payment age starts a new eligibility period: payments already queued stay with staff.</p>
            {version !== account.version && <p role="status" className="text-sm text-amber-900">A newer policy is available. Your entries are kept. <button type="button" className="underline" onClick={() => setVersion(account.version)}>Use latest version for next save</button></p>}
            {error && <p role="alert" className="text-sm text-rose-800">{error} {mutation.isError && <button type="button" className="font-medium underline" onClick={reload}>Reload latest policy</button>}</p>}
            <div className="flex flex-wrap gap-3"><button type="submit" className={primaryButton} disabled={mutation.isPending}>{mutation.isPending ? 'Saving…' : 'Save rules'}</button><button type="button" className={secondaryButton} disabled={mutation.isPending} onClick={() => setEditing(false)}>Cancel</button></div>
        </form>}
    </section>;
}
