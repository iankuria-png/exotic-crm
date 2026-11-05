import React, { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuth } from '../../hooks/useAuth';
import { useToast } from '../ToastProvider';
import api from '../../services/api';

export default function MarketTrialApprovalPolicy({ marketId }) {
    const { user } = useAuth(); const cache = useQueryClient(); const toast = useToast();
    const q = useQuery({ queryKey: ['trial-policy', user?.id, marketId], queryFn: () => api.get(`/crm/markets/${marketId}/trial-approval-policy`).then(r => r.data), enabled: Boolean(marketId) });
    const [mode, setMode] = useState('admin_only'); const [exception, setException] = useState(''); const [reviewOn, setReviewOn] = useState(''); const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState('');
    useEffect(() => { if (q.data) { setMode(q.data.approval_mode); setException(q.data.pin_exception_reason || ''); setReviewOn(q.data.review_on || ''); } }, [q.data]);
    const save = async () => { setBusy(true); setError(''); try { await api.put(`/crm/markets/${marketId}/trial-approval-policy`, { version: q.data.version, approval_mode: mode, pin_exception_reason: exception, review_on: reviewOn || null, reason }); await cache.invalidateQueries({ queryKey: ['trial-policy', user?.id, marketId] }); setReason(''); toast.success('Market trial approval policy saved.'); } catch (e) { setError(e?.response?.data?.message || 'Policy could not be saved.'); } finally { setBusy(false); } };
    if (!marketId) return null;
    return <section className="rounded-xl border border-slate-200 bg-white p-5 space-y-4"><div><p className="text-[11px] font-semibold uppercase tracking-widest text-teal-700">Market control</p><h3 className="mt-1 font-semibold text-slate-900">Free-trial approval</h3><p className="mt-1 text-xs leading-5 text-slate-500">Approval is required before discretionary trials activate. Existing trial product, duration, deposit and eligibility rules still apply.</p></div>
        {q.isLoading ? <p role="status">Loading market policy…</p> : q.isError ? <p role="alert" className="text-rose-700">Could not load market policy. <button onClick={() => q.refetch()}>Retry</button></p> : <fieldset disabled={busy || user?.role !== 'admin'} className="space-y-4">
            {[['admin_only', 'Admin approval only', 'Sales submits a request. An admin approves before activation.'], ['admin_or_pin', 'Admin or configured PIN', 'Allow immediate PIN authorization in this selected market.']].map(([key, label, hint]) => <label key={key} className={`flex cursor-pointer gap-3 rounded-lg border p-4 ${mode === key ? 'border-teal-600 bg-teal-50' : 'border-slate-200'}`}><input type="radio" name={`trial-policy-${marketId}`} value={key} checked={mode === key} onChange={() => setMode(key)} className="mt-1 accent-teal-700" /><span><strong className="block text-sm font-semibold">{label}</strong><small className="mt-1 block text-xs leading-5 text-slate-500">{hint}</small></span></label>)}
            <p className="text-xs text-slate-500">Global free-trial PIN: {q.data.pin_configured ? 'Configured · values hidden' : 'Not configured · set it in global PIN settings'}</p>
            {mode === 'admin_or_pin' && <><label className="block text-sm">Reason for PIN access<textarea className="crm-input mt-1 min-h-20" value={exception} onChange={e => setException(e.target.value)} placeholder="For example, market development before revenue" /></label><label className="block text-sm">Review this exception on<input type="date" className="crm-input mt-1" value={reviewOn} onChange={e => setReviewOn(e.target.value)} /></label><p className="text-xs text-slate-500">Revenue provides context. This setting does not switch automatically when sales begin.</p></>}
            {user?.role === 'admin' && <><label className="block text-sm">Reason for policy change<textarea className="crm-input mt-1 min-h-20" value={reason} onChange={e => setReason(e.target.value)} /></label>{error && <p role="alert" className="text-sm text-rose-700">{error}</p>}<button className="crm-btn-primary" onClick={save} disabled={busy || !reason.trim() || (mode === 'admin_or_pin' && (!exception.trim() || !reviewOn || !q.data.pin_configured))}>{busy ? 'Saving…' : 'Save market approval policy'}</button></>}
        </fieldset>}
    </section>;
}
