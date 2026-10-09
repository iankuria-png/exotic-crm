import React, { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';
import { Link } from 'react-router-dom';
import MonetizationAutomation from './MonetizationAutomation';
import MonetizationSetup, { ProviderChoices } from './MonetizationSetup';
import { marketForm, readDraft, writeDraft, editableSettings } from './monetizationDraft';
import { readAuthSnapshot } from '../../../utils/authStorage';

const sections = ['Guided setup', 'Pass pricing', 'Offers & limits', 'Surfaces & checkout', 'Automation', 'Send love'];
const fieldClass = 'min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400';
const names = { photos_enabled: 'Private photos', videos_enabled: 'Private videos', single_enabled: 'Single-item offers', bundles_enabled: 'Fixed bundles', min_price: 'Minimum price', max_price: 'Maximum price', bundle_min_items: 'Minimum bundle items', bundle_max_items: 'Maximum bundle items', live_offer_limit: 'Live offer limit', upload_max_bytes: 'Upload limit (bytes)', max_video_seconds: 'Video limit (seconds)', profile_section: 'Profile private section', home_private_content: 'Home · Private photos & videos', videos_private_filter: 'Videos private filter', show_prices: 'Show prices on previews', activation_kill_switch: 'Pause new pass activation', checkout_kill_switch: 'Pause new purchases' };
const errorMessage = (error, fallback) => Object.values(error.response?.data?.errors || {}).flat()[0] || (error.response?.status < 500 ? error.response?.data?.message : null) || fallback;
function Check({ label, checked, onChange }) { return <label className="flex min-h-11 items-center justify-between gap-4 rounded-md border border-slate-200 px-3 py-2 text-sm"><span>{label}</span><input type="checkbox" className="h-4 w-4 accent-slate-900" checked={!!checked} onChange={e => onChange(e.target.checked)} /></label>; }
export default function MonetizationSettings() {
    const qc = useQueryClient();
    const [market, setMarket] = useState(() => new URLSearchParams(location.search).get('platform_id') || '');
    const [section, setSection] = useState(0), [form, setForm] = useState(null), [reason, setReason] = useState(''), [busy, setBusy] = useState(false), [message, setMessage] = useState('');
    const query = useQuery({ queryKey: ['monetization-settings', market], refetchInterval: 60000, queryFn: async () => (await api.get('/crm/settings/monetization', { params: { platform_id: market || undefined } })).data });
    const [dirty, setDirty] = useState(false), [errors, setErrors] = useState({});
    const formRef = useRef(null), dirtyRef = useRef(false), reasonRef = useRef('');
    const tabs = useRef([]);
    const actor = readAuthSnapshot().user?.id || 'session';
    const draftKey = id => `monetization-draft:${actor}:${id}`;
    const commitForm = value => { formRef.current = value; setForm(value); };
    const markDirty = value => { dirtyRef.current = value; setDirty(value); };
    useEffect(() => {
        const saved = query.data?.market;
        if (!saved) return;
        const next = marketForm(saved, query.data.automation);
        if (formRef.current?.platform_id === saved.platform_id && dirtyRef.current) {
            commitForm({ ...formRef.current, readiness_json: saved.readiness_json, wp_revision: saved.wp_revision, heartbeat_at: saved.heartbeat_at });
            if (formRef.current.config_revision !== saved.config_revision) setMessage('The saved revision changed. Your draft is preserved. Review the current settings before saving.');
            return;
        }
        const draft = readDraft(draftKey(saved.platform_id));
        if (draft) {
            commitForm({ ...next, ...draft.form, config_revision: draft.revision });
            markDirty(JSON.stringify(editableSettings(next)) !== JSON.stringify(draft.form));
            reasonRef.current = draft.reason || ''; setReason(reasonRef.current);
            setMessage(draft.outcome || (draft.revision !== saved.config_revision ? 'Your draft was restored, but the saved revision changed. Review the current settings before saving.' : 'Your unsaved draft was restored.'));
        } else { commitForm(next); markDirty(false); }
    }, [query.data]);
    useEffect(() => {
        if (!form || !dirty) return;
        if (!writeDraft(draftKey(form.platform_id), form, reason, message)) setMessage('Your draft is kept on this page, but browser storage is unavailable. Save before reloading.');
    }, [form, reason, dirty, message]);
    const update = (key, value) => { markDirty(true); commitForm({ ...formRef.current, [key]: value }); setErrors({}); };
    const nested = (key, name, value) => update(key, { ...formRef.current[key], [name]: value });
    const setup = query.data?.setup;
    const stale = form && query.data?.market && form.config_revision !== query.data.market.config_revision;
    const providerError = errors['checkout_policy.allowed_providers']?.join(' ');
    const clearDraft = id => { try { sessionStorage.removeItem(draftKey(id)); } catch {} };
    const acceptSaved = data => {
        if (data.market) commitForm(marketForm(data.market, { ...query.data.automation, expiry: data.market.expiry_video_policy_json || query.data.automation?.expiry, free_pass: data.market.free_pass_policy_json || query.data.automation?.free_pass, teaser: data.market.teaser_policy_json || query.data.automation?.teaser }));
        clearDraft(formRef.current.platform_id); markDirty(false); setErrors({});
    };
    async function save(e) {
        e.preventDefault(); setBusy(true); setMessage('Saving and checking WordPress…'); setErrors({});
        const submitted = formRef.current;
        try {
            const { data } = await api.put(`/crm/settings/monetization/markets/${submitted.platform_id}`, { ...editableSettings(submitted), config_revision: submitted.config_revision, reason }, { timeout: 180000 });
            acceptSaved(data);
            setMessage(data.sync.status === 'synced' && data.credentials.status === 'synced' && data.readiness?.ready ? 'Saved and synced. Required protected-delivery checks passed.' : `Saved, but WordPress needs attention. ${data.readiness?.error || data.sync.message || data.credentials.message || 'Review Guided setup, then re-check.'}`);
            await qc.invalidateQueries({ queryKey: ['monetization-settings'] });
        } catch (error) {
            setErrors(error.response?.data?.errors || {});
            setMessage(errorMessage(error, 'CRM could not confirm the save. Your draft is preserved. Retry; if it persists, ask the CRM administrator to check the service.'));
        } finally { setBusy(false); }
    }
    async function readiness() {
        if (dirtyRef.current) { setMessage('Save your draft first. Checks use the saved settings.'); return; }
        setBusy(true); setMessage('Checking connection, server and protected delivery…');
        try {
            const { data } = await api.post(`/crm/settings/monetization/markets/${formRef.current.platform_id}/sync`, {}, { timeout: 180000 });
            setMessage(data.readiness?.ready ? 'Saved configuration synced. Required delivery checks passed.' : `WordPress needs attention. ${data.readiness?.error || data.setup?.gates?.find(gate => !gate.passed)?.message || 'Review the setup steps below, then retry.'}`);
            await qc.invalidateQueries({ queryKey: ['monetization-settings'] });
        } catch (error) { setMessage(errorMessage(error, 'The check could not finish. Check site availability, then retry. Your saved settings are retained.')); }
        finally { setBusy(false); }
    }
    async function setupAction(action) {
        if (reason.trim().length < 5) { setMessage('Enter a reason for this audited setup action below.'); document.getElementById('monetization-reason')?.focus(); return; }
        if (dirtyRef.current) { setMessage('Save your draft before running setup actions.'); return; }
        setBusy(true); setErrors({}); setMessage(action === 'activate' ? 'Enabling live market and waiting for WordPress confirmation…' : 'Connecting and checking WordPress…');
        try {
            const { data } = await api.post(`/crm/settings/monetization/markets/${formRef.current.platform_id}/setup/${action}`, { reason, config_revision: formRef.current.config_revision, environment: formRef.current.premium_access_environment }, { timeout: 180000 });
            acceptSaved(data);
            setMessage(data.message || (data.status === 'failed' ? 'WordPress needs attention. Review the connection step and retry.' : data.readiness?.ready ? 'Connected and synced. Required protected-delivery checks passed.' : `Connection saved. WordPress needs attention. ${data.setup?.gates?.find(gate => !gate.passed)?.message || data.readiness?.error || 'Review Guided setup and re-check.'}`));
            await qc.invalidateQueries({ queryKey: ['monetization-settings'] });
        } catch (error) { setErrors(error.response?.data?.errors || {}); setMessage(errorMessage(error, 'CRM could not confirm this setup action. Re-check the saved market state before retrying.')); }
        finally { setBusy(false); }
    }
    async function system(key, value) {
        if (reason.trim().length < 5) { setMessage('Enter an audit reason before changing global controls.'); return; }
        setBusy(true);
        try { await api.put('/crm/settings/monetization/system', { ...query.data.system, [key]: value, reason }); await qc.invalidateQueries({ queryKey: ['monetization-settings'] }); setMessage('Global controls saved. Existing purchases retain access.'); }
        catch (e) { setMessage(errorMessage(e, 'CRM could not confirm the global control change. Retry or ask the CRM administrator to check the service.')); } finally { setBusy(false); }
    }
    const fieldError = key => errors[key]?.join(' ');
    const fieldAttrs = key => ({ 'aria-invalid': !!fieldError(key), 'aria-describedby': fieldError(key) ? `error-${key}` : undefined });
    const validation = key => fieldError(key) ? <span id={`error-${key}`} className="mt-1 block text-xs leading-5 text-red-700">{fieldError(key)}</span> : null;
    if (query.isPending) return <p role="status" className="p-6 text-slate-500">Loading Monetize settings…</p>;
    if (query.isError) return <div role="alert" className="rounded-lg border border-red-200 p-5">Could not load settings. <button onClick={() => query.refetch()}>Try again</button></div>;
    if (!form) return <p>No authorised markets are available.</p>;
    return <form onSubmit={save} aria-busy={busy} className="space-y-5">
        <div className="flex flex-col justify-between gap-4 md:flex-row md:items-end"><div><h2 className="text-xl font-semibold text-slate-900">Monetize settings</h2><p className="mt-1 text-sm text-slate-500">Pricing, private content and protected access. Revision {form.config_revision}.</p></div><label className="w-full md:w-64"><span className="mb-1 block text-xs font-medium text-slate-500">Market</span><select className={fieldClass} disabled={busy} value={market || form.platform_id} onChange={e => { if (dirtyRef.current) writeDraft(draftKey(form.platform_id), formRef.current, reasonRef.current, message); setMarket(e.target.value); setMessage(''); setErrors({}); setReason(''); reasonRef.current = ''; markDirty(false); }}>{query.data.platforms.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label></div>
        <div role="status" aria-live="polite" aria-atomic="true" className="rounded-md bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700">{message || (dirty ? 'Unsaved changes · your draft is kept in this browser tab.' : 'Saved configuration · setup actions use this revision.')}</div>
        {stale && <div className="rounded-md border border-amber-200 p-4 text-sm"><p>The saved revision changed. Your draft is preserved. Review the current settings before continuing.</p><button type="button" className="mt-2 min-h-11 font-medium underline" onClick={() => { clearDraft(form.platform_id); commitForm(marketForm(query.data.market, query.data.automation)); markDirty(false); setErrors({}); setMessage('Latest saved settings loaded.'); }}>Discard draft and load saved settings</button></div>}
        {Object.entries(errors).filter(([key]) => key !== 'checkout_policy.allowed_providers').map(([key, values]) => <p role="alert" key={key} className="text-sm text-red-700">{key.replaceAll('_', ' ').replaceAll('.', ' → ')}: {values.join(' ')}</p>)}
        <div role="tablist" aria-label="Monetize settings sections" className="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1">{sections.map((label, i) => <button type="button" role="tab" id={`monetization-tab-${i}`} aria-controls="monetization-panel" tabIndex={section === i ? 0 : -1} ref={element => { tabs.current[i] = element; }} onKeyDown={e => { let next; if (e.key === "ArrowRight") next = (i + 1) % sections.length; if (e.key === "ArrowLeft") next = (i + sections.length - 1) % sections.length; if (e.key === "Home") next = 0; if (e.key === "End") next = sections.length - 1; if (next !== undefined) { e.preventDefault(); setSection(next); tabs.current[next]?.focus(); } }} aria-selected={section === i} key={label} onClick={() => setSection(i)} className={`min-h-11 rounded-md px-4 text-sm ${section === i ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500'}`}>{label}</button>)}</div>
        <section className="rounded-lg border border-slate-200 bg-white p-5" role="tabpanel" id="monetization-panel" aria-labelledby={`monetization-tab-${section}`}><fieldset disabled={busy} className="min-w-0">
            {section === 5 && <div className="flex flex-wrap items-center justify-between gap-4"><div><h3 className="font-semibold text-slate-900">Send love settings have moved</h3><p className="mt-1 text-sm text-slate-500">Configure each market, check provider readiness and sync WordPress from the Send Love workspace.</p></div><Link to="/send-love?tab=setup" className="inline-flex min-h-11 items-center rounded-md bg-teal-700 px-4 text-sm font-semibold text-white">Open Send Love setup</Link></div>}
            {section === 0 && <MonetizationSetup setup={setup} form={form} update={update} nested={nested} busy={busy} dirty={dirty || stale} onAction={setupAction} onCheck={readiness} onSection={setSection} providerError={providerError} supportedCurrencies={query.data.supported_currencies || [form.currency]} systemControls={query.data.can_edit_system && <div className="space-y-2 border-t pt-4"><h3 className="font-semibold">Global emergency controls</h3><Check label="Enable Monetize globally" checked={query.data.system.enabled} onChange={value => system('enabled', value)} />{['activation_kill_switch', 'checkout_kill_switch'].map(key => <Check key={key} label={names[key]} checked={query.data.system[key]} onChange={value => system(key, value)} />)}</div>} />}
            {section === 1 && <div className="grid gap-5 md:grid-cols-2">{form.prices.map((p, i) => { const change = (k,v) => update('prices', form.prices.map((row,n) => n === i ? {...row,[k]:v} : row)); const subsidy = p.subsidy_mode === 'percentage' ? Number(p.price) * Number(p.subsidy_value) / 100 : Number(p.subsidy_value); return <div key={p.duration_key} className="space-y-4 rounded-lg border border-slate-200 p-5"><h3 className="text-lg font-semibold">{p.duration_label}</h3><Check label="Available" checked={p.is_active} onChange={v => change('is_active',v)} /><label className="block text-sm">Standard price · {form.currency}<input type="number" min="0" step="0.01" className={fieldClass} value={p.price} {...fieldAttrs(`prices.${i}.price`)} onChange={e => change('price',e.target.value)} />{validation(`prices.${i}.price`)}</label><label className="block text-sm">Paid-listing subsidy<select className={fieldClass} value={p.subsidy_mode} onChange={e => change('subsidy_mode', e.target.value)}><option value="fixed">Fixed amount</option><option value="percentage">Percentage</option></select></label><input aria-label={`${p.duration_label} subsidy`} type="number" min="0" className={fieldClass} value={p.subsidy_value} onChange={e => change('subsidy_value', e.target.value)} /><div className="border-t pt-3 text-sm leading-7"><p>Paid listing: <strong>{form.currency} {Math.max(0, p.price - subsidy).toFixed(2)}</strong></p><p>Trial or SEO boost: {form.currency} {Number(p.price).toFixed(2)}</p></div></div>; })}</div>}
            {section === 2 && <div className="space-y-5"><p className="text-sm text-slate-500">Verification rules are managed in <a className="underline" href="/settings?tab=kyc">Settings → KYC</a>. Passes and buyer access are unaffected by verification policy.</p><div className="grid gap-4 md:grid-cols-2">{Object.entries(form.offer_policy).map(([k,v]) => typeof v === 'boolean' ? <Check key={k} label={names[k] || k} checked={v} onChange={x => nested('offer_policy',k,x)} /> : <label key={k} className="text-sm">{names[k] || k}<input type="number" className={fieldClass} value={v} {...fieldAttrs(`offer_policy.${k}`)} onChange={e => nested('offer_policy', k, Number(e.target.value))} />{validation(`offer_policy.${k}`)}</label>)}</div></div>}
            {section === 4 && form.expiry_video_policy && <MonetizationAutomation form={form} update={update} automation={query.data.automation} marketName={query.data.platforms.find(p => String(p.id) === String(form.platform_id))?.name || 'This market'} savedRevision={query.data.market.config_revision} dirty={JSON.stringify([form.expiry_video_policy, form.free_pass_policy, form.teaser_policy]) !== JSON.stringify([query.data.automation.expiry, query.data.automation.free_pass, query.data.automation.teaser])} />}
            {section === 3 && <div className="grid gap-6 lg:grid-cols-2"><div className="space-y-3"><h3 className="font-semibold">Discovery surfaces</h3>{Object.entries(form.surface_policy).map(([k,v]) => <Check key={k} label={names[k] || k} checked={v} onChange={x => nested('surface_policy',k,x)} />)}</div><div className="space-y-3"><h3 className="font-semibold">Checkout & access</h3><ProviderChoices setup={setup} form={form} error={providerError} onChange={value => nested('checkout_policy', 'allowed_providers', value)} />{[['device_slots','Device slots',1,3],['restore_per_hour','Restore attempts per hour',1,10]].map(([k,label,min,max]) => <label key={k} className="block text-sm">{label}<input type="number" min={min} max={max} className={fieldClass} value={form.checkout_policy[k]} {...fieldAttrs(`checkout_policy.${k}`)} onChange={e => nested('checkout_policy',k,Number(e.target.value))} />{validation(`checkout_policy.${k}`)}</label>)}<label className="block text-sm">Viewing grant (seconds)<input type="number" min="60" max="300" className={fieldClass} value={form.delivery_policy.grant_ttl} {...fieldAttrs('delivery_policy.grant_ttl')} onChange={e => nested('delivery_policy','grant_ttl',Number(e.target.value))} />{validation('delivery_policy.grant_ttl')}</label><p className="text-xs leading-5 text-slate-500">Sales credit the full listed price. Refund the buyer manually, then record the refund in Monetize → Sales. Wallet reversals use the existing PIN-protected adjustment.</p></div></div>}
        </fieldset></section>
        {section !== 5 && <div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 md:flex-row md:items-end"><label className="flex-1 text-sm">Reason for change<input id="monetization-reason" required minLength={5} className={fieldClass} value={reason} onChange={e => { setReason(e.target.value); reasonRef.current = e.target.value; }} placeholder="Explain this market change" /></label><button disabled={busy || stale} className="min-h-11 rounded-md bg-slate-900 px-5 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Saving…' : 'Save & sync'}</button></div>}

        <details className="rounded-lg border border-slate-200 bg-white p-4"><summary className="cursor-pointer text-sm font-medium">Recent configuration changes</summary>{query.data.audit.map(a => <div key={a.id} className="border-b py-3 text-sm"><p>{a.reason}</p><span className="text-xs text-slate-500">{new Date(a.created_at).toLocaleString()} · Staff {a.actor_id}</span></div>)}</details>
    </form>;
}
