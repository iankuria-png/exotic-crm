import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import SubsidiaryTrialSection from '../clients/SubsidiaryTrialSection';
import SubsidiaryCopyProgress from '../clients/SubsidiaryCopyProgress';
import { primaryButton, secondaryButton, StatusPill } from './collectionUi';

const labels = { pending: 'Tanzania queued', copying: 'Copying Tanzania profile', prepared: 'Activating Tanzania', needs_approval: 'Tanzania needs approval', needs_profile: 'Tanzania needs setup', uncertain: 'Checking Tanzania confirmation', succeeded: 'Tanzania active', already_active: 'Tanzania already covered', canceled: 'Tanzania step cancelled' };
export default function CollectionSubsidiaryProgress({ row, onChanged }) {
    const client = useQueryClient();
    const [editing, setEditing] = useState(false);
    const [ready, setReady] = useState(false);
    const [value, setValue] = useState({ enabled: true, platform_id: '2', duration_mode: 'match_primary', preview_token: '' });
    const [error, setError] = useState('');
    const base = `/crm/collections/receipts/${row.id}/subsidiary`;
    const result = useQuery({ queryKey: ['collection-subsidiary', row.id], queryFn: () => api.get(base).then(r => r.data),
        refetchInterval: query => ['succeeded', 'already_active', 'canceled'].includes(query.state.data?.state) ? false : 5000 });
    const data = result.data;
    const profile = data?.summary?.subsidiary;
    const state = data?.state || row.subsidiary_obligation.state;
    const complete = ['succeeded', 'already_active'].includes(state);
    const finish = useMutation({ mutationFn: () => api.post(`${base}/finish`, { expected_version: data.version, manual_client_id: value.manual_client_id || null,
        preview_token: value.preview_token, create_confirmed: Boolean(value.create_confirmed), ...(value.pin ? { pin: value.pin } : {}),
        copy_region_id: value.copy_region_id || null, copy_city_id: value.copy_city_id || null, copy_currency_id: value.copy_currency_id || null }),
        onSuccess: () => { setEditing(false); setError(''); setValue(v => ({ ...v, pin: '' })); result.refetch(); client.invalidateQueries({ queryKey: ['collection-receipts'] }); onChanged?.(); },
        onError: e => { setError(e.response?.status === 409 ? 'The saved setup changed. Your choices are kept. Refresh its status and preview before retrying.' : e.response?.data?.message || 'Tanzania could not be confirmed. The paid TZ subscription stays active.'); result.refetch(); } });
    return <section aria-label="Paired Tanzania activation" className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 sm:p-5"><div className="flex flex-wrap items-center justify-between gap-2"><h3 className="text-sm font-semibold">Tanzania paired subscription</h3><StatusPill tone={complete ? 'live' : ['needs_approval', 'needs_profile', 'uncertain'].includes(state) ? 'action' : 'busy'}>{labels[state] || 'Checking Tanzania'}</StatusPill></div>
        <p className="text-sm text-slate-600">The paid TZ subscription is active. Tanzania is completed separately for the same purchased period.</p>
        {result.isPending && <p role="status" className="text-sm text-slate-500">Loading the saved Tanzania step…</p>}
        {result.isError && <p role="alert" className="text-sm text-amber-900">Tanzania status is unavailable or outside your market access. <button type="button" className="underline" onClick={() => result.refetch()}>Retry status</button></p>}
        {complete && profile?.public_url && <a className="inline-block py-2 text-sm font-medium text-teal-800 underline" href={profile.public_url} target="_blank" rel="noopener noreferrer">Open Tanzania profile</a>}
        {profile?.copy && <SubsidiaryCopyProgress row={profile} onChanged={() => result.refetch()} />}
        {!complete && state !== 'canceled' && data && <div className="flex flex-wrap gap-3"><button type="button" className={secondaryButton} onClick={() => result.refetch()}>Check Tanzania status</button>{!editing && <button type="button" className={primaryButton} onClick={() => setEditing(true)}>Finish Tanzania setup</button>}</div>}
        {editing && data && <div className="space-y-3"><SubsidiaryTrialSection endpointBase={base} requestContext={{ source_client_id: row.purchase?.client_id, product_price_id: row.purchase?.product_price_id, receipt_version: data.version }}
            value={value} onChange={patch => setValue(v => ({ ...v, ...patch }))} onReadyChange={setReady} disabled={finish.isPending} />
            {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}
            <div className="flex flex-wrap gap-3"><button type="button" disabled={!ready || !value.enabled || finish.isPending} className={primaryButton} onClick={() => finish.mutate()}>{finish.isPending ? 'Checking saved setup…' : 'Complete Tanzania step'}</button><button type="button" className={secondaryButton} disabled={finish.isPending} onClick={() => setEditing(false)}>Cancel</button></div>
        </div>}
    </section>;
}
