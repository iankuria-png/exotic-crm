import React, { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import TrialAuthorizationControl from '../trials/TrialAuthorizationControl';
import SubsidiaryClientSearchPopover from './SubsidiaryClientSearchPopover';

const explanations = { inactive_market: 'This market is inactive.', free_trial_disabled: 'Free trials are disabled in this market.', missing_matching_product: 'A matching package is not available.', missing_wp_api_credentials: 'This market’s connection needs attention.', missing_wp_db_credentials: 'Profile creation is unavailable for this market.' };

export default function SubsidiaryTrialSection({ dealId, value, onChange, onReadyChange, disabled = false, endpointBase = null, requestContext = {}, optionalReceipt = false }) {
    const identity = endpointBase ? JSON.stringify([endpointBase, requestContext.source_client_id, requestContext.product_price_id]) : dealId;
    const base = endpointBase || `/crm/deals/${dealId}`;
    const [details, setDetails] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const searchTrigger = useRef(null);
    const initialized = useRef(null);
    const targets = useQuery({ queryKey: ['subsidiary-trial-targets', identity], queryFn: () => api.get(`${base}/subsidiary-trial-targets`, { params: requestContext }).then(r => r.data), enabled: Boolean(endpointBase || dealId) && (!optionalReceipt || Boolean(value.enabled)), staleTime: 0 });
    const markets = targets.data?.targets || [];
    const target = markets.find(m => Number(m.id) === Number(value.platform_id));
    useEffect(() => {
        if (!targets.data || initialized.current === identity) return;
        initialized.current = identity;
        if (endpointBase && typeof value.enabled === 'boolean') { if (!value.platform_id && targets.data.recommended_platform_id) onChange({ platform_id: String(targets.data.recommended_platform_id), request_key: value.request_key || crypto.randomUUID() }); return; }
        const id = targets.data.recommended_platform_id;
        onChange({ enabled: optionalReceipt ? false : Boolean(id), platform_id: id ? String(id) : '', client_id: null, manual_client_id: null, duration_days: '', preview_token: '', request_key: crypto.randomUUID(), create_confirmed: false });
    }, [targets.data, identity]);
    const preview = useQuery({
        queryKey: ['subsidiary-trial-preview', identity, requestContext.receipt_version || null, value.platform_id, value.manual_client_id || null, value.duration_days || null],
        queryFn: () => api.get(`${base}/subsidiary-trial-preview`, { params: { ...requestContext, platform_id: Number(value.platform_id), ...(value.manual_client_id ? { client_id: Number(value.manual_client_id) } : {}), ...(value.duration_days ? { duration_days: Number(value.duration_days) } : {}) } }).then(r => r.data),
        enabled: Boolean(value.enabled && (endpointBase || dealId) && value.platform_id), staleTime: 0, refetchInterval: value.enabled ? 10 * 60 * 1000 : false,
    });
    const result = preview.data;
    const kind = result?.resolution?.kind;
    const errors = result?.config_errors || [];
    const ready = Boolean(result?.preview_token && !preview.isFetching && !preview.isError && !errors.length);
    useEffect(() => {
        if (!result || preview.isFetching) return;
        const patch = { preview_token: result.preview_token || '', client_id: result.resolution?.client_id || null, create_confirmed: kind === 'create_if_needed' || Boolean(result.match && !result.match.wp_post_id), resolution_kind: kind };
        if (!value.duration_days) patch.duration_days = String(result.default_duration_days);
        if (value.preview_token !== patch.preview_token || value.client_id !== patch.client_id || !value.duration_days) onChange(patch);
    }, [result, preview.isFetching, kind, value.preview_token, value.client_id, value.duration_days]);
    useEffect(() => { onReadyChange?.(!value.enabled || (!targets.isLoading && ready)); }, [ready, value.enabled, targets.isLoading, onReadyChange]);
    const reset = patch => onChange({ client_id: null, manual_client_id: null, preview_token: '', create_confirmed: false, request_key: crypto.randomUUID(), pin: '', ...patch });
    const primaryOnly = () => reset({ enabled: false });
    return <section className="rounded-lg border border-teal-200 bg-teal-50/40 p-4" aria-label="Paired market trial">
        <div className="flex flex-col items-start justify-between gap-2 sm:flex-row sm:gap-3">
            <label className="flex min-w-0 items-start gap-2.5 text-sm font-semibold text-slate-900">
                <input className="mt-1 h-4 w-4 shrink-0 accent-teal-700" type="checkbox" checked={Boolean(value.enabled)} disabled={disabled || targets.isLoading} onChange={e => reset({ enabled: e.target.checked })} />
                <span>{target ? `Include ${target.name} free trial` : 'Include a free trial on another market'}<span className="mt-1 block text-xs font-normal text-slate-500">{targets.isLoading ? 'Finding the paired market…' : value.enabled && value.duration_days ? `${value.duration_days} days · Free` : 'Optional extra exposure for this customer'}</span></span>
            </label>
            {value.enabled && <button type="button" className="shrink-0 self-start rounded px-1 py-2 text-xs font-semibold text-teal-800 focus-visible:outline-2" aria-expanded={details} onClick={() => setDetails(d => !d)}>{details ? 'Hide details' : 'Change details'}</button>}
        </div>
        {targets.isError && <p className="mt-3 text-sm text-amber-900" role="alert">Markets could not be loaded. <button type="button" className="underline" onClick={() => targets.refetch()}>Try again</button></p>}
        {targets.data?.pair_unavailable && !value.enabled && <p className="mt-3 text-xs text-amber-900">The paired market is unavailable. You can activate this subscription on its own.</p>}
        {value.enabled && <div className="mt-4 space-y-3">
            {(details || !value.platform_id) && <label className="block text-xs font-medium text-slate-700">Trial market<select aria-label="Trial market" className="crm-select mt-1" value={value.platform_id || ''} disabled={disabled} onChange={e => reset({ platform_id: e.target.value, duration_days: '' })}><option value="">Choose market</option>{markets.map(m => <option key={m.id} value={m.id} disabled={(m.config_errors || []).some(e => e !== 'missing_wp_db_credentials')}>{m.name}{m.config_errors?.length ? ' · Check availability' : ''}</option>)}</select></label>}
            {preview.isFetching ? <p role="status" className="text-sm text-slate-500">Checking the customer’s profile…</p> : preview.isError ? <div role="alert" className="text-sm text-rose-800">{preview.error?.response?.data?.message || 'Profile check failed.'} <button type="button" className="underline" onClick={() => preview.refetch()}>Refresh preview</button></div> : result && <div className="border-t border-teal-100 pt-3 text-sm">
                {kind === 'existing' && <><p className="font-semibold text-slate-900">Existing profile: {result.resolution.name}</p><p className="mt-1 text-xs text-slate-600">We’ll use this customer’s {target?.name} profile.</p></>}
                {kind === 'already_active' && <><p className="font-semibold text-teal-900">Already active on {target?.name}</p><p className="mt-1 text-xs text-slate-600">No new trial is needed. Existing coverage and expiry stay as they are.</p></>}
                {kind === 'create_if_needed' && <><p className="font-semibold text-slate-900">We’ll set up {result.resolution.name}’s {target?.name} profile if needed.</p><p className="mt-1 text-xs leading-5 text-slate-600">Including this trial allows us to reuse a matching profile or create one with this customer’s name, phone and city. Photos and bio are not copied.</p></>}
                {kind === 'ambiguous' && <><p className="font-semibold text-amber-900">Choose the correct profile</p><p className="mt-1 text-xs text-slate-600">More than one identity matches. No profile or trial has been created.</p><div className="mt-2 space-y-2">{result.resolution.candidates.map(c => <button key={c.id} type="button" className="block w-full rounded border border-slate-200 bg-white p-3 text-left" onClick={() => reset({ manual_client_id: c.id })}><strong>{c.name}</strong><span className="block text-xs text-slate-500">{c.phone_normalized} · {c.city || 'City not recorded'}</span></button>)}</div></>}
                {kind === 'unavailable' && <p role="alert" className="text-amber-900">This profile needs review before a trial. Choose an existing profile or activate the primary subscription only.</p>}
            </div>}
            {errors.length > 0 && <p role="alert" className="text-xs text-amber-900">{errors.map(e => explanations[e] || 'This market needs review.').join(' ')}</p>}
            {details && <div className="relative space-y-3"><button ref={searchTrigger} type="button" className="py-2 text-xs font-semibold text-teal-800 underline" aria-expanded={searchOpen} onClick={() => setSearchOpen(true)}>Choose a different profile</button><SubsidiaryClientSearchPopover open={searchOpen} platformId={value.platform_id} onClose={() => { setSearchOpen(false); searchTrigger.current?.focus(); }} onSelect={c => { reset({ manual_client_id: c.id }); setSearchOpen(false); searchTrigger.current?.focus(); }} /><label className="block text-xs font-medium text-slate-700">Trial duration (days)<input aria-label="Trial duration (days)" type="number" min="1" max="90" className="crm-input mt-1" value={value.duration_days || ''} onChange={e => reset({ duration_days: e.target.value, manual_client_id: value.manual_client_id })} /></label></div>}
            {value.platform_id && kind !== 'already_active' && <TrialAuthorizationControl compact marketId={value.platform_id} value={value.pin || ''} disabled={disabled} onChange={pin => onChange({ pin })} />}
            {(!ready || errors.length > 0) && <button type="button" className="rounded py-2 text-xs font-semibold text-teal-800 underline" onClick={primaryOnly}>Continue with primary subscription only</button>}
            <p className="text-xs text-slate-500">You’ll get the confirmed profile links here after activation.</p>
        </div>}
    </section>;
}
