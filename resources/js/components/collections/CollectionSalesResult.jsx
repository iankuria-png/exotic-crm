import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import { customerMessage } from '../clients/SubsidiaryActivationResult';
import SubsidiaryCopyProgress from '../clients/SubsidiaryCopyProgress';

const button = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 focus-visible:ring-2 focus-visible:ring-teal-600 disabled:opacity-40';
const labels = { active: 'Active', setting_up: 'Setting up profile', setup_paused: 'Setup paused', already_active: 'Already active · Coverage unchanged', pending_approval: 'Awaiting admin approval', approved: 'Approved · Activation pending', waiting_primary: 'Waiting for the primary subscription', waiting_payment: 'Waiting for payment', activation_pending: 'Activation pending', activating: 'Checking activation', uncertain: 'Needs checking', denied: 'Request denied', cancelled: 'Cancelled', expired: 'Coverage expired', unavailable: 'Unavailable', restricted: 'Access required to view this market', unknown: 'Outcome not recorded' };
export default function CollectionSalesResult({ dealId, onBack }) {
    const { user } = useAuth();
    const [status, setStatus] = useState('');
    const [fallback, setFallback] = useState('');
    const result = useQuery({ queryKey: ['activation-summary', user?.id, dealId], queryFn: async () => (await api.get(`/crm/deals/${dealId}/activation-summary`)).data, staleTime: 0, retry: false,
        refetchInterval: q => q.state.data && [q.state.data.primary, q.state.data.subsidiary].some(r => r?.status === 'setting_up') ? 5000 : false });
    const data = result.data;
    const text = data ? customerMessage(data) : '';
    async function copy(value, label) {
        setStatus(''); setFallback('');
        try { await navigator.clipboard.writeText(value); setStatus(label); }
        catch { setFallback(value); }
    }
    return <section aria-label="Customer activation handoff" className="space-y-4"><div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="text-lg font-semibold">{data?.primary?.name || 'Customer message'}</h3><p className="mt-1 text-xs text-slate-500">Confirmed profile links for this advertiser</p></div><button type="button" className={button} onClick={onBack}>Back to subscriptions</button></div>
        {result.isPending && <p role="status" className="text-sm">Checking saved activation outcomes…</p>}
        {result.isError && <p role="alert" className="text-sm text-rose-800">Could not load the activation handoff. <button type="button" className="underline" onClick={() => result.refetch()}>Try again</button></p>}
        {data && [data.primary, data.subsidiary].filter(r => r && r.status !== 'not_included').map((profile, index) => <section key={index} className="rounded-lg border border-slate-200 p-4"><div className="flex flex-wrap justify-between gap-2"><h4 className="font-semibold">{profile.market || (index ? 'Subsidiary profile' : 'Primary subscription')}</h4><span className="text-sm text-teal-800">{labels[profile.status] || 'Needs checking'}</span></div>{['active', 'already_active'].includes(profile.status) && profile.public_url ? <div className="mt-3 flex flex-wrap gap-4 text-sm"><a className="font-medium text-teal-700 underline" href={profile.public_url} target="_blank" rel="noopener noreferrer">Open profile</a><button type="button" className="font-medium text-teal-700 underline" onClick={() => copy(profile.public_url, 'Profile link copied')}>Copy profile link</button></div> : <p className="mt-2 text-xs text-slate-500">A confirmed public link will appear when this profile is ready.</p>}<SubsidiaryCopyProgress row={profile} onChanged={() => result.refetch()} /></section>)}
        {text && <label className="block text-sm font-medium">Customer message<textarea className="crm-input mt-2 w-full text-sm font-normal" rows={7} readOnly value={text} /><span className="mt-2 block text-xs font-normal text-slate-500">Includes this advertiser’s confirmed primary and subsidiary profiles. Copying does not send a message.</span></label>}
        <div className="sticky bottom-0 flex flex-wrap justify-between gap-3 border-t border-slate-200 bg-white py-4"><button type="button" className={button} disabled={result.isFetching} onClick={() => result.refetch()}>Check status</button><button type="button" className={`${button} !border-teal-700 !bg-teal-700 !text-white`} disabled={!text} onClick={() => copy(text, 'Customer message copied')}>Copy customer message</button></div>
        {status && <p role="status" className="text-sm text-teal-800">{status}</p>}{fallback && <label className="block text-sm">Clipboard unavailable. Select and copy this text.<textarea className="crm-input mt-1 w-full" readOnly rows={7} value={fallback} onFocus={e => e.target.select()} /></label>}
    </section>;
}
