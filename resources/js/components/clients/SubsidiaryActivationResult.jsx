import React, { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import ReviewDialog from '../reconciliation/ReviewDialog';
import '../reconciliation/reconciliation.css';

const labels = { active: 'Active', already_active: 'Already active · no new trial granted', waiting_payment: 'Waiting for payment', activation_pending: 'Payment recorded; activation pending', waiting_primary: 'Waiting for primary subscription', pending_approval: 'Awaiting admin approval', approved: 'Approved; waiting for activation', activating: 'Checking activation', uncertain: 'Activation is being checked', denied: 'Trial request denied', cancelled: 'Trial request cancelled', expired: 'Coverage expired', unavailable: 'Unavailable', unknown: 'Outcome not recorded', restricted: 'Access required to view this trial' };
const pending = new Set(['waiting_payment', 'activation_pending', 'waiting_primary', 'pending_approval', 'approved', 'activating', 'uncertain']);
function expiry(row) {
    if (!row.expires_at) return '';
    try { return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: row.timezone || 'Africa/Nairobi' }).format(new Date(row.expires_at)) + ` (${row.timezone || 'Africa/Nairobi'})`; } catch { return row.expires_at; }
}
export function customerMessage(data) {
    const parts = [data.primary, data.subsidiary].filter(r => r?.public_url && ['active', 'already_active'].includes(r.status)).map((r, i) => `${r.market} ${r === data.primary ? 'subscription' : r.kind === 'trial' && r.status === 'active' ? 'free trial' : 'profile'} is active${r.expires_at ? ` until ${expiry(r)}` : ''}.\n${r.public_url}`).join('\n\n');
    return parts ? `Hi ${data.primary?.name || 'there'},\n\n${parts}` : '';
}
export default function SubsidiaryActivationResult({ dealId, onClose }) {
    const started = useRef(Date.now());
    const [copied, setCopied] = useState('');
    const [fallback, setFallback] = useState('');
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState('');
    const mounted = useRef(true);
    useEffect(() => () => { mounted.current = false; }, []);
    const summary = useQuery({ queryKey: ['activation-summary', dealId], queryFn: () => api.get(`/crm/deals/${dealId}/activation-summary`).then(r => r.data), staleTime: 0, refetchInterval: q => Date.now() - started.current < 90000 && (!q.state.data || [q.state.data.primary, q.state.data.subsidiary].some(r => pending.has(r?.status))) ? 5000 : false });
    const copy = async (text, name) => {
        try { await navigator.clipboard.writeText(text); setCopied(name); setFallback(''); }
        catch { setFallback(text); setCopied(''); }
    };
    const recover = async () => {
        setBusy(true); setActionError('');
        try { await api.post(`/crm/free-trials/requests/${summary.data.subsidiary.request_id}/recover`, { reason: 'Checking saved subsidiary activation from the sales handoff.' }); if (mounted.current) await summary.refetch(); }
        catch (e) { if (mounted.current) setActionError(e?.response?.data?.message || 'Could not check the saved operation. Try again.'); }
        finally { if (mounted.current) setBusy(false); }
    };
    const data = summary.data;
    const message = data ? customerMessage(data) : '';
    return <ReviewDialog title="Activation summary" eyebrow={data?.primary?.name || 'Customer profile handoff'} onClose={onClose} footer={<><button type="button" className="rec-button" onClick={onClose}>Done</button><button type="button" className="rec-button rec-button-primary" disabled={!message} onClick={() => copy(message, 'Customer message copied')}>Copy customer message</button></>}>
        {summary.isLoading && <p role="status">Checking saved activation outcomes…</p>}
        {summary.isError && <div role="alert" className="rec-error">Could not load the activation summary. Your saved request is unchanged. <button type="button" className="underline" onClick={() => summary.refetch()}>Try again</button></div>}
        {data && <div className="space-y-4">
            {[data.primary, data.subsidiary].filter(r => r?.status !== 'not_included').map((row, i) => <section key={i} className="rounded-lg border border-slate-200 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2"><h3 className="text-sm font-semibold text-slate-900">{row.market || (i === 0 ? 'Primary subscription' : 'Paired trial')}</h3><span className={`text-xs font-semibold ${['active', 'already_active'].includes(row.status) ? 'text-teal-800' : 'text-amber-900'}`}>{labels[row.status] || 'Checking status'}</span></div>
                {row.expires_at && <p className="mt-1 text-xs text-slate-500">Expires {expiry(row)}</p>}
                {row.public_url ? <div className="mt-3 flex flex-wrap gap-4 text-sm"><a href={row.public_url} target="_blank" rel="noopener noreferrer" className="font-semibold text-teal-800 underline">Open {row.market} profile</a><button type="button" className="font-semibold text-teal-800 underline" onClick={() => copy(row.public_url, `${row.market} link copied`)}>Copy {row.market} link</button></div> : ['active', 'already_active'].includes(row.status) ? <p className="mt-2 text-xs text-slate-500">Link being confirmed. Check status to try again.</p> : <p className="mt-2 text-xs text-slate-500">A customer link will be available after activation and publication are confirmed.</p>}
                {row.can_review && <a href={`/payments/reconciliations?tab=trials&request_id=${row.request_id}`} className="mt-3 inline-block text-sm font-semibold text-teal-800 underline">Review trial request</a>}
                {row.can_recover && <button type="button" className="mt-3 block text-sm font-semibold text-teal-800 underline" disabled={busy} onClick={recover}>{busy ? 'Checking saved operation…' : 'Check saved activation outcome'}</button>}
            </section>)}
            {message && <div><label htmlFor="activation-customer-message" className="text-sm font-semibold text-slate-800">Customer message</label><textarea id="activation-customer-message" className="crm-input mt-2 w-full text-sm" rows={8} readOnly value={message} /><p className="mt-1 text-xs text-slate-500">Includes only confirmed active profiles. Copying does not send a message.</p></div>}
            <button type="button" className="rec-button" disabled={summary.isFetching} onClick={() => summary.refetch()}>{summary.isFetching ? 'Checking…' : 'Check status'}</button>
        </div>}
        {copied && <p role="status" className="mt-3 text-sm text-teal-800">{copied}</p>}
        {fallback && <label className="mt-3 block text-sm">Clipboard unavailable. Select and copy this text.<textarea aria-label="Text to copy" className="crm-input mt-2" readOnly rows={5} value={fallback} onFocus={e => e.target.select()} /></label>}
        {actionError && <p role="alert" className="mt-3 text-sm text-rose-800">{actionError}</p>}
    </ReviewDialog>;
}
