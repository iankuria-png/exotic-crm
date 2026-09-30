import React, { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';

const fieldClass = 'min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400';
const quiet = 'min-h-11 rounded-md border border-slate-300 px-4 text-sm font-medium text-slate-800 disabled:opacity-50';
const primary = 'min-h-11 rounded-md bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-50';
const lengthModes = [['any', 'Any length'], ['up_to', 'Up to'], ['at_least', 'At least'], ['between', 'Between']];
const reasonLabels = { converted: 'converted', no_eligible_media: 'had no eligible media', duration_outside_rule: 'outside the length rule', duration_unavailable: 'length unreadable', over_profile_limit: 'over the per-profile limit', profile_not_restricted: 'no longer expired', owner_opted_out: 'taken off sale by the escort', commerce_unavailable: 'selling unavailable', wordpress_unavailable: 'WordPress unreachable', wordpress_outdated: 'WordPress plugin needs 1.3.16', granted: 'granted', already_granted: 'already covered', held: 'on hold', unexpected_error: 'unexpected error' };
const kindLabels = { expired_backfill: 'Existing expired profiles', active_pass_grant: 'Free pass · active subscriptions', selected_pass_grant: 'Free pass · selected escorts' };
const clock = s => { const n = Number(s) || 0; return `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`; };
const errorMessage = (error, fallback) => Object.values(error.response?.data?.errors || {}).flat()[0] || error.response?.data?.message || fallback;

/** Same formula the CRM applies when an item is published (see ExpiryAutomationService::price). */
export function scaledPrice(policy, seconds, marketMax) {
    const p = policy.pricing, d = policy.video_duration;
    const from = ['at_least', 'between'].includes(d.mode) ? Number(d.min_seconds) || 0 : 0;
    const to = ['up_to', 'between'].includes(d.mode) ? Number(d.max_seconds) || 0 : marketMax;
    const min = Number(p.min_amount) || 0, max = Number(p.max_amount) || 0, step = Math.max(1, Number(p.round_increment) || 1);
    const ratio = Math.max(0, Math.min(1, (seconds - from) / Math.max(1, to - from)));
    return { from, to, amount: Math.max(min, Math.min(max, Math.round((min + ratio * (max - min)) / step) * step)) };
}

function Toggle({ label, hint, pressed, onChange }) {
    return <button type="button" aria-pressed={pressed} onClick={() => onChange(!pressed)} className={`flex min-h-11 items-center justify-between gap-3 rounded-md border px-3 py-2 text-left text-sm ${pressed ? 'border-slate-900 bg-slate-50 font-semibold text-slate-900' : 'border-slate-200 text-slate-600'}`}><span>{label}</span><span className="text-xs font-medium text-slate-500">{pressed ? 'Selected' : hint}</span></button>;
}

function RunRow({ run, platformId, onRetried }) {
    const [busy, setBusy] = useState(false), [note, setNote] = useState('');
    const done = run.succeeded + run.skipped + run.failed, batch = run.rule?.batch_size;
    async function retry() {
        setBusy(true); setNote('');
        try { const { data } = await api.post(`/crm/settings/monetization/markets/${platformId}/automations/runs/${run.public_id}/retry`); setNote(`${data.retried} item${data.retried === 1 ? '' : 's'} queued again.`); onRetried(); }
        catch (e) { setNote(errorMessage(e, 'Retry failed.')); } finally { setBusy(false); }
    }
    return <li className="border-t border-slate-100 py-3 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-medium text-slate-900">{kindLabels[run.kind] || run.kind}{run.kind === 'active_pass_grant' ? (batch ? ` · batch of ${batch}` : ' · all remaining') : ''}</span><span className="text-xs text-slate-500">{new Date(run.created_at).toLocaleString()}</span></div>
        <p className={`mt-1 ${run.status === 'completed' ? 'text-emerald-700' : run.status === 'completed_with_errors' ? 'text-amber-700' : 'text-slate-600'}`}>{run.status === 'running' || run.status === 'queued' ? `Running · ${done} of ${run.total}` : `${run.succeeded} ${run.kind === 'expired_backfill' ? 'profiles updated' : 'added'}${run.skipped ? ` · ${run.skipped} skipped` : ''}${run.failed ? ` · ${run.failed} failed` : ''}`}</p>
        {run.reasons?.length > 0 && <p className="mt-1 text-xs text-slate-500">{run.reasons.map(r => `${r.total} ${reasonLabels[r.code] || r.code}`).join(' · ')}</p>}
        {run.failed > 0 && <button type="button" disabled={busy} onClick={retry} className="mt-2 min-h-11 text-sm font-semibold text-slate-900 underline disabled:opacity-50">Retry failed items</button>}
        <p role="status" className="text-xs text-slate-600">{note}</p>
    </li>;
}

export default function MonetizationAutomation({ form, update, automation, marketName, savedRevision, dirty }) {
    const qc = useQueryClient();
    const expiry = form.expiry_video_policy, pass = form.free_pass_policy, platformId = form.platform_id;
    const marketMax = Number(form.offer_policy?.max_video_seconds) || 1800;
    const [confirm, setConfirm] = useState(null), [busy, setBusy] = useState(false), [message, setMessage] = useState('');
    const [search, setSearch] = useState(''), [chosen, setChosen] = useState({}), [grantDuration, setGrantDuration] = useState(pass.duration_key || '1_month'), [batchSize, setBatchSize] = useState('50');
    const runs = useQuery({ queryKey: ['monetization-runs', platformId], initialData: { runs: automation.runs, estimates: automation.estimates }, queryFn: async () => (await api.get(`/crm/settings/monetization/markets/${platformId}/automations/runs`)).data, refetchInterval: q => (q.state.data?.runs || []).some(r => ['queued', 'running'].includes(r.status)) ? 4000 : false });
    const creators = useQuery({ queryKey: ['monetization-creator-search', platformId, search], enabled: search.trim().length >= 2, queryFn: async () => (await api.get('/crm/monetization/creators/search', { params: { platform_id: platformId, q: search } })).data.creators });
    const setExpiry = (key, value) => update('expiry_video_policy', { ...expiry, [key]: value });
    const setGroup = (group, key, value) => update('expiry_video_policy', { ...expiry, [group]: { ...expiry[group], [key]: value } });
    const toggleType = (type, on) => { const types = on ? Array.from(new Set([...expiry.media_types, type])) : expiry.media_types.filter(t => t !== type); update('expiry_video_policy', { ...expiry, media_types: types, max_per_type: { ...expiry.max_per_type, [type]: on ? Math.max(1, Number(expiry.max_per_type[type]) || (type === 'video' ? 2 : 1)) : 0 }, pricing: types.includes('video') ? expiry.pricing : { ...expiry.pricing, mode: 'fixed' } }); };
    const videos = expiry.media_types.includes('video'), photos = expiry.media_types.includes('photo'), scale = expiry.pricing.mode === 'duration_scale', d = expiry.video_duration;
    const examples = useMemo(() => { if (!scale) return []; const { from, to } = scaledPrice(expiry, 0, marketMax); if (to <= from) return []; return [from, Math.round((from + to) / 2), to].map(s => [s, scaledPrice(expiry, s, marketMax).amount]); }, [expiry, marketMax, scale]);
    const lengthText = d.mode === 'any' ? 'any length' : d.mode === 'up_to' ? `up to ${clock(d.max_seconds)}` : d.mode === 'at_least' ? `at least ${clock(d.min_seconds)}` : `between ${clock(d.min_seconds)} and ${clock(d.max_seconds)}`;
    const priceText = scale ? `scaled by length, ${form.currency} ${Number(expiry.pricing.min_amount).toLocaleString()}–${Number(expiry.pricing.max_amount).toLocaleString()} rounded to ${expiry.pricing.round_increment}` : `fixed ${form.currency} ${Number(expiry.pricing.fixed_amount).toLocaleString()}`;
    const ruleText = [videos && `${expiry.max_per_type.video} newest video${Number(expiry.max_per_type.video) === 1 ? '' : 's'} (${lengthText})`, photos && `${expiry.max_per_type.photo} newest photo${Number(expiry.max_per_type.photo) === 1 ? '' : 's'} at ${form.currency} ${Number(expiry.pricing.photo_amount || 0).toLocaleString()}`].filter(Boolean).join(' and ');
    const estimates = runs.data?.estimates || automation.estimates || {};
    const remaining = Number(estimates.active_subscriptions_remaining ?? estimates.active_subscriptions ?? 0), activeTotal = Number(estimates.active_subscriptions || 0);
    const batchCount = batchSize === 'all' ? remaining : Math.min(Number(batchSize), remaining);
    const selectedIds = Object.keys(chosen).filter(id => chosen[id]).map(Number);
    const latestExpiryRun = (runs.data?.runs || []).find(r => r.kind === 'expired_backfill');

    async function start() {
        setBusy(true); setMessage('');
        try {
            if (confirm.kind === 'backfill') await api.post(`/crm/settings/monetization/markets/${platformId}/automations/expired-content`, { scope: 'currently_expired', settings_revision: savedRevision });
            else await api.post(`/crm/settings/monetization/markets/${platformId}/automations/free-passes`, { scope: confirm.kind, duration_key: grantDuration, client_ids: confirm.kind === 'selected' ? selectedIds : undefined, batch_size: confirm.kind === 'active_subscriptions' && batchSize !== 'all' ? Number(batchSize) : undefined });
            setMessage('Started. Progress updates below.'); setConfirm(null); if (confirm.kind === 'selected') setChosen({});
            await qc.invalidateQueries({ queryKey: ['monetization-runs', platformId] });
        } catch (e) { setMessage(errorMessage(e, 'Could not start. Nothing was changed.')); } finally { setBusy(false); }
    }
    const durationLabel = key => key === '2_weeks' ? '2 Weeks' : '1 Month';

    return <div className="space-y-5">
        <div className="grid gap-5 xl:grid-cols-2">
            <section className="space-y-4 rounded-lg border border-slate-200 p-5" aria-labelledby="expiry-automation-title">
                <div className="flex items-start justify-between gap-3"><div><h3 id="expiry-automation-title" className="font-semibold text-slate-900">Content when a profile expires</h3><p className="mt-1 text-sm text-slate-500">Move selected public media into Private content after the protected copy succeeds.</p></div><label className="flex min-h-11 items-center gap-2 text-sm font-medium"><input type="checkbox" className="h-4 w-4 accent-slate-900" checked={!!expiry.enabled} onChange={e => setExpiry('enabled', e.target.checked)} />On</label></div>
                <fieldset className="space-y-2"><legend className="text-xs font-semibold uppercase tracking-wide text-slate-500">Content to monetize</legend><div className="grid gap-2 sm:grid-cols-2"><Toggle label="Videos" hint="Leave public" pressed={videos} onChange={v => toggleType('video', v)} /><Toggle label="Photos" hint="Leave public" pressed={photos} onChange={v => toggleType('photo', v)} /></div>{!photos && <p className="text-xs text-slate-500">Public images are not copied, removed or repriced while Photos is off. Cover and first photo always stay public.</p>}</fieldset>
                <div className="grid gap-3 sm:grid-cols-2">
                    {videos && <label className="text-sm">Videos per profile<input type="number" min="1" max="20" className={fieldClass} value={expiry.max_per_type.video} onChange={e => setGroup('max_per_type', 'video', Number(e.target.value))} /><small className="text-xs text-slate-500">Newest eligible first; the limit applies after the length filter.</small></label>}
                    {photos && <label className="text-sm">Photos per profile<input type="number" min="1" max="20" className={fieldClass} value={expiry.max_per_type.photo} onChange={e => setGroup('max_per_type', 'photo', Number(e.target.value))} /></label>}
                </div>
                {videos && <fieldset className="space-y-2"><legend className="text-xs font-semibold uppercase tracking-wide text-slate-500">Video length</legend><div className="grid gap-3 sm:grid-cols-3"><label className="text-sm">Rule<select className={fieldClass} value={d.mode} onChange={e => setGroup('video_duration', 'mode', e.target.value)}>{lengthModes.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>{['at_least', 'between'].includes(d.mode) && <label className="text-sm">Minimum (seconds)<input type="number" min="0" max={marketMax} className={fieldClass} value={d.min_seconds ?? ''} onChange={e => setGroup('video_duration', 'min_seconds', e.target.value === '' ? null : Number(e.target.value))} /><small className="text-xs text-slate-500">{clock(d.min_seconds)}</small></label>}{['up_to', 'between'].includes(d.mode) && <label className="text-sm">Maximum (seconds)<input type="number" min="1" max={marketMax} className={fieldClass} value={d.max_seconds ?? ''} onChange={e => setGroup('video_duration', 'max_seconds', e.target.value === '' ? null : Number(e.target.value))} /><small className="text-xs text-slate-500">{clock(d.max_seconds)}</small></label>}</div><p className="text-xs text-slate-500">Bounds are inclusive. Videos whose length cannot be read are skipped whenever length matters.</p></fieldset>}
                <fieldset className="space-y-3"><legend className="text-xs font-semibold uppercase tracking-wide text-slate-500">Automated price</legend>
                    {videos && <div className="grid gap-2 sm:grid-cols-2">{[['fixed', 'Fixed price'], ['duration_scale', 'Scale by video length']].map(([v, l]) => <label key={v} className={`flex min-h-11 items-center gap-2 rounded-md border px-3 text-sm ${expiry.pricing.mode === v ? 'border-slate-900 font-semibold' : 'border-slate-200'}`}><input type="radio" name="expiry-price-mode" className="accent-slate-900" checked={expiry.pricing.mode === v} onChange={() => setGroup('pricing', 'mode', v)} />{l}</label>)}</div>}
                    {videos && !scale && <label className="block text-sm">Video price · {form.currency}<input type="number" min={form.offer_policy.min_price} max={form.offer_policy.max_price} className={fieldClass} value={expiry.pricing.fixed_amount ?? ''} onChange={e => setGroup('pricing', 'fixed_amount', Number(e.target.value))} /></label>}
                    {videos && scale && <><div className="grid gap-3 sm:grid-cols-3">{[['min_amount', 'From'], ['max_amount', 'To'], ['round_increment', 'Round to']].map(([k, l]) => <label key={k} className="text-sm">{l} · {form.currency}<input type="number" min="1" className={fieldClass} value={expiry.pricing[k] ?? ''} onChange={e => setGroup('pricing', k, Number(e.target.value))} /></label>)}</div>{examples.length > 0 ? <p className="text-xs text-slate-600">{examples.map(([s, a]) => `${clock(s)} → ${form.currency} ${a.toLocaleString()}`).join(' · ')}</p> : <p className="text-xs text-amber-700">Set a length range to scale prices.</p>}</>}
                    {photos && <label className="block text-sm">Photo price · {form.currency}<input type="number" min={form.offer_policy.min_price} max={form.offer_policy.max_price} className={fieldClass} value={expiry.pricing.photo_amount ?? ''} onChange={e => setGroup('pricing', 'photo_amount', Number(e.target.value))} /><small className="text-xs text-slate-500">Photos have no length, so they use one fixed amount.</small></label>}
                    <p className="text-xs text-slate-500">Allowed offer range {form.currency} {Number(form.offer_policy.min_price).toLocaleString()}–{Number(form.offer_policy.max_price).toLocaleString()}. The calculated price is stored on each item; later changes affect future automation only.</p>
                </fieldset>
                <label className="flex min-h-11 items-center justify-between gap-4 rounded-md border border-slate-200 px-3 py-2 text-sm"><span>Apply automatically to future expiries</span><input type="checkbox" className="h-4 w-4 accent-slate-900" checked={!!expiry.apply_to_future_expiries} onChange={e => setExpiry('apply_to_future_expiries', e.target.checked)} /></label>
                <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4"><button type="button" className={quiet} disabled={dirty || !expiry.enabled || !expiry.media_types.length} onClick={() => setConfirm({ kind: 'backfill' })}>Apply to existing expired profiles</button>{dirty && <span className="text-xs text-slate-500">Save settings first.</span>}</div>
                {latestExpiryRun && <p className="text-sm text-slate-600">Latest run: {latestExpiryRun.succeeded} profiles updated{latestExpiryRun.skipped ? ` · ${latestExpiryRun.skipped} skipped` : ''}{latestExpiryRun.failed ? ` · ${latestExpiryRun.failed} failed` : ''}</p>}
            </section>
            <section className="space-y-4 rounded-lg border border-slate-200 p-5" aria-labelledby="free-pass-title">
                <div><h3 id="free-pass-title" className="font-semibold text-slate-900">Free selling passes</h3><p className="mt-1 text-sm text-slate-500">Free time is added after any pass an escort already owns. Paid time is never replaced.</p></div>
                <div className="space-y-3 rounded-md border border-slate-200 p-4"><label className="flex min-h-11 items-center justify-between gap-4 text-sm font-medium"><span>Future new subscriptions</span><input type="checkbox" className="h-4 w-4 accent-slate-900" checked={!!pass.new_subscriptions_enabled} onChange={e => update('free_pass_policy', { ...pass, new_subscriptions_enabled: e.target.checked })} /></label><label className="block text-sm">Pass length<select className={fieldClass} value={pass.duration_key} onChange={e => update('free_pass_policy', { ...pass, duration_key: e.target.value })}><option value="2_weeks">2 Weeks</option><option value="1_month">1 Month</option></select></label><p className="text-xs text-slate-500">Applies once per genuinely new subscription activated after saving. Renewals, trials and SEO boosts do not qualify.</p></div>
                <div className="space-y-3"><label className="block text-sm">Grant length<select className={fieldClass} value={grantDuration} onChange={e => setGrantDuration(e.target.value)}><option value="2_weeks">2 Weeks</option><option value="1_month">1 Month</option></select></label>
                    <div className="space-y-2 border-t border-slate-100 pt-3 text-sm"><div className="flex flex-wrap items-center justify-between gap-2"><span className="font-medium text-slate-900">Active subscriptions in batches</span><span className="text-xs text-slate-500">{remaining.toLocaleString()} remaining of {activeTotal.toLocaleString()} active</span></div>
                        <fieldset><legend className="sr-only">Batch size</legend><div className="grid grid-cols-2 gap-2 sm:grid-cols-4">{[['50', '50'], ['100', '100'], ['150', '150'], ['all', 'All remaining']].map(([v, l]) => <label key={v} className={`flex min-h-11 items-center justify-center gap-2 rounded-md border px-2 text-sm ${batchSize === v ? 'border-slate-900 bg-slate-50 font-semibold' : 'border-slate-200 text-slate-600'}`}><input type="radio" name="free-pass-batch" className="sr-only" value={v} checked={batchSize === v} onChange={() => setBatchSize(v)} />{l}</label>)}</div></fieldset>
                        <div className="flex flex-wrap items-center justify-between gap-3"><span className="text-xs text-slate-500">Next escorts by CRM client ID. Escorts who already have a batch pass, or are waiting in a running batch, are skipped.</span><button type="button" className={quiet} disabled={!remaining} onClick={() => setConfirm({ kind: 'active_subscriptions' })}>{remaining ? `Grant to next ${batchCount.toLocaleString()}` : 'All covered'}</button></div>
                    </div>
                    <div className="space-y-2 border-t border-slate-100 pt-3"><label className="block text-sm">Selected escorts<input className={fieldClass} value={search} onChange={e => setSearch(e.target.value)} placeholder="Search name, phone or CRM client ID" /></label>
                        {creators.isFetching && <p role="status" className="text-xs text-slate-500">Searching…</p>}
                        {(creators.data || []).length > 0 && <ul className="max-h-56 divide-y divide-slate-100 overflow-auto rounded-md border border-slate-200">{creators.data.map(c => <li key={c.id}><label className="flex min-h-11 items-center gap-3 px-3 py-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-slate-900" checked={!!chosen[c.id]} onChange={e => setChosen(s => ({ ...s, [c.id]: e.target.checked }))} /><span className="flex-1"><span className="font-medium text-slate-900">{c.name}</span> <span className="text-xs text-slate-500">#{c.id}{c.phone ? ` · ${c.phone}` : ''} · {c.lifecycle_state || 'active'}</span></span><span className="text-xs text-slate-500">{c.pass ? `${Number(c.pass.paid_amount) === 0 ? 'Free' : 'Paid'} pass to ${new Date(c.pass.expires_at).toLocaleDateString()}` : 'No pass'}</span></label></li>)}</ul>}
                        <div className="flex items-center justify-between gap-3"><span className="text-xs text-slate-500">{selectedIds.length} selected</span><button type="button" className={quiet} disabled={!selectedIds.length} onClick={() => setConfirm({ kind: 'selected' })}>Grant free pass</button></div>
                    </div>
                </div>
            </section>
        </div>
        {confirm && <div role="alertdialog" aria-labelledby="automation-confirm-title" className="space-y-3 rounded-lg border border-slate-900 bg-slate-50 p-5">
            <h3 id="automation-confirm-title" className="font-semibold text-slate-900">{confirm.kind === 'backfill' ? 'Apply to existing expired profiles?' : 'Grant free selling passes?'}</h3>
            <p className="text-sm leading-6 text-slate-700">{confirm.kind === 'backfill' ? `${marketName}: ${ruleText}, priced ${priceText}. About ${Number(automation.estimates?.expired_profiles || 0).toLocaleString()} publicly reachable expired profiles will be checked; files move only after their protected copy succeeds.` : `${marketName}: ${durationLabel(grantDuration)} free pass for ${confirm.kind === 'selected' ? `${selectedIds.length} selected escort${selectedIds.length === 1 ? '' : 's'}` : `the next ${batchCount.toLocaleString()} active subscription${batchCount === 1 ? '' : 's'} (${remaining.toLocaleString()} remaining)`}. Time queues after any pass they already own.`}</p>
            <div className="flex flex-wrap gap-2"><button type="button" autoFocus disabled={busy} className={primary} onClick={start}>{busy ? 'Starting…' : 'Start now'}</button><button type="button" className={quiet} onClick={() => setConfirm(null)}>Cancel</button></div>
        </div>}
        <p role="status" aria-live="polite" className="text-sm text-slate-700">{message}</p>
        <section className="rounded-lg border border-slate-200 p-5"><h3 className="font-semibold text-slate-900">Recent automation runs</h3>{(runs.data?.runs || []).length ? <ul className="mt-2">{runs.data.runs.map(run => <RunRow key={run.public_id} run={run} platformId={platformId} onRetried={() => qc.invalidateQueries({ queryKey: ['monetization-runs', platformId] })} />)}</ul> : <p className="mt-2 text-sm text-slate-500">No runs yet.</p>}</section>
    </div>;
}
