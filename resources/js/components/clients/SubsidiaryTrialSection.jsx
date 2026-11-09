import React, { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import TrialAuthorizationControl from '../trials/TrialAuthorizationControl';
import SubsidiaryClientSearchPopover from './SubsidiaryClientSearchPopover';

const explanations = { inactive_market: 'This market is inactive.', free_trial_disabled: 'Paired-market activation is turned off for this market.', missing_matching_product: 'A matching package is not available.', missing_wp_api_credentials: 'This market’s connection needs attention.', missing_wp_db_credentials: 'Profile creation is unavailable for this market.' };
const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
const sentence = items => {
    const words = items.map((w, i) => (i ? w.charAt(0).toLowerCase() + w.slice(1) : w));
    return words.length > 1 ? `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}` : words[0] || '';
};

function CopySummary({ copy, target, source, value, onPick, disabled }) {
    const [open, setOpen] = useState(false);
    if (!copy || copy.status === 'off') return null;
    if (copy.status === 'unavailable') return <p role="alert" className="mt-2 text-xs leading-5 text-amber-900">{copy.message || 'The profile details could not be loaded.'}</p>;
    const media = [copy.photos ? plural(copy.photos, 'photo') : null, copy.videos ? plural(copy.videos, 'video') : null, copy.main_included ? 'Main photo included' : null].filter(Boolean).join(' · ');
    const location = copy.location || {};
    const regions = location.options || [];
    const region = regions.find(r => Number(r.id) === Number(value.copy_region_id || location.suggested_region_id));
    return <div className="space-y-2">
        <p className="font-semibold text-slate-900">Create {target} profile from {source}</p>
        <p className="text-xs text-slate-700">{sentence([...(copy.categories?.length ? copy.categories : ['Profile details']), ...(location.status && location.status !== 'none' ? ['location'] : [])])}</p>
        <p className="text-xs text-slate-700">{media || 'No public photos or videos'}</p>
        {(location.label || location.source) && location.status !== 'needs_selection' && <p className="text-xs text-slate-500">{location.source}{location.label && location.label !== location.source ? ` → ${location.label}` : ''}</p>}
        {location.status === 'needs_selection' && <fieldset className="space-y-2 rounded-md border border-amber-200 bg-amber-50/60 p-3">
            <legend className="px-1 text-xs font-semibold text-amber-900">Choose the matching {target} location</legend>
            <p className="text-xs text-slate-600">{source} location: {location.source}</p>
            <label className="block text-xs font-medium text-slate-700">Region<select aria-label={`${target} region`} className="crm-select mt-1" disabled={disabled} value={value.copy_region_id || location.suggested_region_id || ''} onChange={e => onPick({ copy_region_id: e.target.value ? Number(e.target.value) : null, copy_city_id: null })}><option value="">Choose region</option>{regions.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}</select></label>
            {region?.cities?.length > 0 && <label className="block text-xs font-medium text-slate-700">City / area<select aria-label={`${target} city`} className="crm-select mt-1" disabled={disabled} value={value.copy_city_id || ''} onChange={e => onPick({ copy_region_id: Number(region.id), copy_city_id: e.target.value ? Number(e.target.value) : null })}><option value="">Region only</option>{region.cities.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>}
            {!value.copy_region_id && <p className="text-xs text-amber-900">Choose the location to continue. Other profile details are ready to copy.</p>}
        </fieldset>}
        {copy.currency?.status === 'needs_selection' && <label className="block rounded-md border border-amber-200 bg-amber-50/60 p-3 text-xs font-medium text-amber-900">Rates are in {copy.currency.source}, which {target} does not list. Choose the rate currency<select aria-label={`${target} rate currency`} className="crm-select mt-1" disabled={disabled} value={value.copy_currency_id || ''} onChange={e => onPick({ copy_currency_id: e.target.value ? Number(e.target.value) : null })}><option value="">Choose currency</option>{(copy.currency.options || []).map(c => <option key={c.id} value={c.id}>{c.code}</option>)}</select></label>}
        {copy.status === 'blocked' && <p role="alert" className="text-xs leading-5 text-amber-900">{copy.message}</p>}
        {copy.status !== 'blocked' && <><button type="button" className="py-1 text-xs font-semibold text-teal-800 underline" aria-expanded={open} onClick={() => setOpen(o => !o)}>{open ? 'Hide profile details' : 'Review profile details'}</button>
            {open && <ul className="list-disc space-y-1 pl-5 text-xs text-slate-600"><li>Copied once into a new private {target} profile. Existing {target} profiles are never overwritten.</li><li>Photos and videos keep their order, alt text and main photo. Private paid content and stories stay on {source}.</li><li>Name, bio, contacts, rates and public details come from the {source} profile{copy.source?.status && copy.source.status !== 'publish' ? ` (currently ${copy.source.status})` : ''}.</li><li>The {target} profile goes live after every item is checked.</li></ul>}</>}
    </div>;
}

export default function SubsidiaryTrialSection({ dealId, value, onChange, onReadyChange, disabled = false, endpointBase = null, requestContext = {}, optionalReceipt = false }) {
    const identity = endpointBase ? JSON.stringify([endpointBase, requestContext.source_client_id, requestContext.product_price_id]) : dealId;
    const base = endpointBase || `/crm/deals/${dealId}`;
    const [details, setDetails] = useState(false);
    const [daysOpen, setDaysOpen] = useState(false);
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
        onChange({ enabled: optionalReceipt ? false : Boolean(id), platform_id: id ? String(id) : '', client_id: null, manual_client_id: null, duration_days: '', duration_mode: '', copy_region_id: null, copy_city_id: null, copy_currency_id: null, preview_token: '', request_key: crypto.randomUUID(), create_confirmed: false });
    }, [targets.data, identity]);
    const custom = value.duration_mode === 'custom';
    const preview = useQuery({
        queryKey: ['subsidiary-trial-preview', identity, requestContext.receipt_version || null, value.platform_id, value.manual_client_id || null, value.duration_mode || null, custom ? value.duration_days || null : null, value.copy_region_id || null, value.copy_city_id || null, value.copy_currency_id || null],
        queryFn: () => api.get(`${base}/subsidiary-trial-preview`, { params: { ...requestContext, platform_id: Number(value.platform_id), ...(value.manual_client_id ? { client_id: Number(value.manual_client_id) } : {}),
            ...(value.duration_mode ? { duration_mode: value.duration_mode } : {}), ...(custom && value.duration_days ? { duration_days: Number(value.duration_days) } : {}),
            ...(value.copy_region_id ? { copy_region_id: value.copy_region_id } : {}), ...(value.copy_city_id ? { copy_city_id: value.copy_city_id } : {}), ...(value.copy_currency_id ? { copy_currency_id: value.copy_currency_id } : {}) } }).then(r => r.data),
        enabled: Boolean(value.enabled && (endpointBase || dealId) && value.platform_id) && (!custom || Number(value.duration_days) > 0), staleTime: 0, refetchInterval: value.enabled ? 10 * 60 * 1000 : false,
    });
    const result = preview.data;
    const kind = result?.resolution?.kind;
    const errors = result?.config_errors || [];
    const duration = result?.duration || null;
    const paired = duration && duration.mode !== 'legacy';
    const copy = kind === 'create_if_needed' ? result?.copy_preview : null;
    const ready = Boolean(result?.preview_token && !preview.isFetching && !preview.isError && !errors.length);
    useEffect(() => {
        if (!result || preview.isFetching) return;
        const patch = { preview_token: result.preview_token || '', client_id: result.resolution?.client_id || null, create_confirmed: kind === 'create_if_needed' || Boolean(result.match && !result.match.wp_post_id), resolution_kind: kind };
        const days = duration?.days ? String(duration.days) : String(result.default_duration_days || '');
        if (!custom && days && value.duration_days !== days) patch.duration_days = days;
        if (value.preview_token !== patch.preview_token || value.client_id !== patch.client_id || patch.duration_days) onChange(patch);
    }, [result, preview.isFetching, kind, value.preview_token, value.client_id, value.duration_days]);
    useEffect(() => { onReadyChange?.(!value.enabled || (!targets.isLoading && ready)); }, [ready, value.enabled, targets.isLoading, onReadyChange]);
    const reset = patch => onChange({ client_id: null, manual_client_id: null, preview_token: '', create_confirmed: false, request_key: crypto.randomUUID(), pin: '', ...patch });
    const primaryOnly = () => reset({ enabled: false });
    const sourceName = result?.source_market || 'primary';
    const periodLine = value.duration_days ? `${plural(Number(value.duration_days), 'day')}${paired && !custom && duration?.mode === 'match_primary' ? ` · Same as ${sourceName}` : ''}` : '';
    return <section className="rounded-lg border border-teal-200 bg-teal-50/40 p-4" aria-label="Paired market options">
        <div className="flex flex-col items-start justify-between gap-2 sm:flex-row sm:gap-3">
            <label className="flex min-w-0 items-start gap-2.5 text-sm font-semibold text-slate-900">
                <input className="mt-1 h-4 w-4 shrink-0 accent-teal-700" type="checkbox" checked={Boolean(value.enabled)} disabled={disabled || targets.isLoading} onChange={e => reset({ enabled: e.target.checked })} />
                <span>{target ? `Include ${target.name}` : 'Include another market'}<span className="mt-1 block text-xs font-normal text-slate-500">{targets.isLoading ? 'Finding the paired market…' : value.enabled && periodLine ? periodLine : 'Optional extra exposure for this customer'}</span></span>
            </label>
            {value.enabled && <button type="button" className="shrink-0 self-start rounded px-1 py-2 text-xs font-semibold text-teal-800 focus-visible:outline-2" aria-expanded={details} onClick={() => setDetails(d => !d)}>{details ? 'Hide details' : 'Change details'}</button>}
        </div>
        {targets.isError && <p className="mt-3 text-sm text-amber-900" role="alert">Markets could not be loaded. <button type="button" className="underline" onClick={() => targets.refetch()}>Try again</button></p>}
        {targets.data?.pair_unavailable && !value.enabled && <p className="mt-3 text-xs text-amber-900">The paired market is unavailable. You can activate this subscription on its own.</p>}
        {value.enabled && <div className="mt-4 space-y-3">
            {(details || !value.platform_id) && <label className="block text-xs font-medium text-slate-700">Market<select aria-label="Paired market" className="crm-select mt-1" value={value.platform_id || ''} disabled={disabled} onChange={e => reset({ platform_id: e.target.value, duration_days: '', duration_mode: '', copy_region_id: null, copy_city_id: null, copy_currency_id: null })}><option value="">Choose market</option>{markets.map(m => <option key={m.id} value={m.id} disabled={(m.config_errors || []).some(e => e !== 'missing_wp_db_credentials')}>{m.name}{m.config_errors?.length ? ' · Check availability' : ''}</option>)}</select></label>}
            {paired && kind !== 'already_active' && <div className="text-sm">
                <div className="flex flex-wrap items-baseline gap-x-3">{!periodLine && <span className="text-xs font-medium text-slate-900">Choose the number of days</span>}{duration.allow_override && <button type="button" className="py-1 text-xs font-semibold text-teal-800 underline" aria-expanded={daysOpen || Boolean(duration.error)} onClick={() => setDaysOpen(o => !o)}>Change days</button>}{copy && copy.status !== 'off' && <span className="text-xs text-slate-500">Starts when the {target?.name} profile is ready.</span>}</div>
                {(daysOpen || duration.error) && <fieldset className="mt-2 space-y-2"><legend className="sr-only">Subscription period</legend>
                    <label className="flex items-center gap-2 text-xs text-slate-700"><input type="radio" name={`period-${identity}`} className="accent-teal-700" checked={!custom} disabled={disabled || !duration.purchased_days} onChange={() => reset({ duration_mode: 'match_primary', duration_days: '', copy_region_id: value.copy_region_id, copy_city_id: value.copy_city_id, copy_currency_id: value.copy_currency_id })} />Match {sourceName} subscription{duration.purchased_days ? ` · ${plural(duration.purchased_days, 'day')}` : ' · not available'}</label>
                    {duration.allow_override && <label className="flex items-center gap-2 text-xs text-slate-700"><input type="radio" name={`period-${identity}`} className="accent-teal-700" checked={custom} disabled={disabled} onChange={() => reset({ duration_mode: 'custom', duration_days: value.duration_days || '', copy_region_id: value.copy_region_id, copy_city_id: value.copy_city_id, copy_currency_id: value.copy_currency_id })} />Choose number of days</label>}
                    {custom && <label className="block text-xs font-medium text-slate-700">Days<input aria-label="Number of days" type="number" inputMode="numeric" min="1" max="90" className="crm-input mt-1 w-28" disabled={disabled} value={value.duration_days || ''} onChange={e => reset({ duration_mode: 'custom', duration_days: e.target.value.replace(/\D/g, '').slice(0, 2), copy_region_id: value.copy_region_id, copy_city_id: value.copy_city_id, copy_currency_id: value.copy_currency_id })} /><span className="mt-1 block font-normal text-slate-500">1–90 days. Changing days needs a fresh approval.</span></label>}
                </fieldset>}
                {duration.error && <p role="alert" className="mt-1 text-xs text-amber-900">{duration.message}</p>}
            </div>}
            {preview.isFetching ? <p role="status" className="text-sm text-slate-500">Checking the customer’s profile…</p> : preview.isError ? <div role="alert" className="text-sm text-rose-800">{preview.error?.response?.data?.message || 'Profile check failed.'} <button type="button" className="underline" onClick={() => preview.refetch()}>Refresh preview</button></div> : result && <div className="border-t border-teal-100 pt-3 text-sm">
                {kind === 'existing' && <><p className="font-semibold text-slate-900">Existing profile: {result.resolution.name}</p><p className="mt-1 text-xs text-slate-600">We’ll use this customer’s {target?.name} profile. Its content stays as it is.</p></>}
                {kind === 'already_active' && <><p className="font-semibold text-teal-900">Already active on {target?.name}</p><p className="mt-1 text-xs text-slate-600">Nothing new is added. Existing coverage and expiry stay as they are.</p></>}
                {kind === 'create_if_needed' && (!copy || copy.status === 'off') && <><p className="font-semibold text-slate-900">We’ll set up {result.resolution.name}’s {target?.name} profile if needed.</p><p className="mt-1 text-xs leading-5 text-slate-600">Including this market allows us to reuse a matching profile or create one with this customer’s name, phone and city. Photos and bio are not copied.</p></>}
                {kind === 'create_if_needed' && copy && copy.status !== 'off' && <CopySummary copy={copy} target={target?.name} source={sourceName} value={value} disabled={disabled} onPick={patch => reset({ ...patch, duration_mode: value.duration_mode, duration_days: value.duration_days })} />}
                {kind === 'ambiguous' && <><p className="font-semibold text-amber-900">Choose the correct profile</p><p className="mt-1 text-xs text-slate-600">More than one identity matches. No profile has been created.</p><div className="mt-2 space-y-2">{result.resolution.candidates.map(c => <button key={c.id} type="button" className="block w-full rounded border border-slate-200 bg-white p-3 text-left" onClick={() => reset({ manual_client_id: c.id })}><strong>{c.name}</strong><span className="block text-xs text-slate-500">{c.phone_normalized} · {c.city || 'City not recorded'}</span></button>)}</div></>}
                {kind === 'unavailable' && <p role="alert" className="text-amber-900">This profile needs review first. Choose an existing profile or activate the primary subscription only.</p>}
            </div>}
            {errors.length > 0 && <p role="alert" className="text-xs text-amber-900">{errors.map(e => explanations[e] || 'This market needs review.').join(' ')}</p>}
            {details && <div className="relative space-y-3"><button ref={searchTrigger} type="button" className="py-2 text-xs font-semibold text-teal-800 underline" aria-expanded={searchOpen} onClick={() => setSearchOpen(true)}>Choose a different profile</button><SubsidiaryClientSearchPopover open={searchOpen} platformId={value.platform_id} onClose={() => { setSearchOpen(false); searchTrigger.current?.focus(); }} onSelect={c => { reset({ manual_client_id: c.id, duration_mode: value.duration_mode, duration_days: value.duration_days }); setSearchOpen(false); searchTrigger.current?.focus(); }} />
                {!paired && <label className="block text-xs font-medium text-slate-700">Number of days<input aria-label="Number of days" type="number" min="1" max="90" className="crm-input mt-1" value={value.duration_days || ''} onChange={e => reset({ duration_days: e.target.value, manual_client_id: value.manual_client_id })} /></label>}</div>}
            {value.platform_id && kind !== 'already_active' && <TrialAuthorizationControl compact context="promotion" marketId={value.platform_id} value={value.pin || ''} disabled={disabled} onChange={pin => onChange({ pin })} />}
            {(!ready || errors.length > 0) && <button type="button" className="rounded py-2 text-xs font-semibold text-teal-800 underline" onClick={primaryOnly}>Continue with primary subscription only</button>}
            <p className="text-xs text-slate-500">You’ll get the confirmed profile links here after activation.</p>
        </div>}
    </section>;
}
