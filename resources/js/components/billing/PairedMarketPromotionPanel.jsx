import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';

function PairForm({ pair, editable, copyEnabled }) {
    const queryClient = useQueryClient();
    const initial = () => ({ duration_mode: pair.duration_mode, fixed_days: pair.fixed_days || '', allow_day_override: pair.allow_day_override, copy_new_profile: pair.copy_new_profile });
    const [form, setForm] = useState(initial);
    const [status, setStatus] = useState('');
    useEffect(() => { setForm(initial()); }, [pair.version, pair.source_platform_id, pair.target_platform_id]);
    const save = useMutation({
        mutationFn: () => api.put(`/crm/settings/subsidiary-promotions/${pair.source_platform_id}/${pair.target_platform_id}`, { ...form, fixed_days: form.duration_mode === 'fixed_days' ? Number(form.fixed_days) : null, version: pair.version }).then(r => r.data),
        onSuccess: () => { setStatus('Paired-market settings saved.'); queryClient.invalidateQueries({ queryKey: ['subsidiary-promotions'] }); },
        onError: e => setStatus(e?.response?.data?.message || 'Settings could not be saved. Reload and try again.'),
    });
    const id = `pair-${pair.source_platform_id}-${pair.target_platform_id}`;
    const invalidDays = form.duration_mode === 'fixed_days' && !(Number(form.fixed_days) >= 1 && Number(form.fixed_days) <= 90);
    return <form className="space-y-3" onSubmit={e => { e.preventDefault(); setStatus(''); save.mutate(); }}>
        <p className="text-sm font-semibold text-slate-900">Paired market: {pair.source_name} → {pair.target_name}</p>
        <fieldset className="space-y-2" disabled={!editable || save.isPending}>
            <legend className="text-xs font-semibold text-slate-700">Default subscription period</legend>
            <label className="flex items-center gap-2 text-sm text-slate-700"><input type="radio" name={`${id}-mode`} className="accent-teal-700" checked={form.duration_mode === 'match_primary'} onChange={() => setForm(f => ({ ...f, duration_mode: 'match_primary' }))} />Match the purchased {pair.source_name} package</label>
            <label className="flex flex-wrap items-center gap-2 text-sm text-slate-700"><input type="radio" name={`${id}-mode`} className="accent-teal-700" checked={form.duration_mode === 'fixed_days'} onChange={() => setForm(f => ({ ...f, duration_mode: 'fixed_days' }))} />Fixed number of days
                {form.duration_mode === 'fixed_days' && <input aria-label="Fixed number of days" type="number" min="1" max="90" inputMode="numeric" className="crm-input w-24" value={form.fixed_days} onChange={e => setForm(f => ({ ...f, fixed_days: e.target.value.replace(/\D/g, '').slice(0, 2) }))} />}</label>
            {invalidDays && <p role="alert" className="text-xs text-amber-900">Choose between 1 and 90 days.</p>}
        </fieldset>
        <fieldset className="space-y-2" disabled={!editable || save.isPending}>
            <legend className="sr-only">Options</legend>
            <label className="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" className="mt-1 accent-teal-700" checked={form.allow_day_override} onChange={e => setForm(f => ({ ...f, allow_day_override: e.target.checked }))} /><span>Allow an authorised day override<span className="block text-xs text-slate-500">Sales can choose 1–90 days; the existing approval rules still apply.</span></span></label>
            <label className="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" className="mt-1 accent-teal-700" checked={form.copy_new_profile} onChange={e => setForm(f => ({ ...f, copy_new_profile: e.target.checked }))} /><span>Copy the {pair.source_name} public profile when creating a {pair.target_name} profile<span className="block text-xs text-slate-500">Existing {pair.target_name} profile content is kept.{!copyEnabled && ' Copying is not switched on for this CRM yet, so new profiles are created with name, phone and city only.'}</span></span></label>
        </fieldset>
        {editable ? <button type="submit" className="crm-btn-primary px-4 py-2 disabled:opacity-60" disabled={save.isPending || invalidDays}>{save.isPending ? 'Saving…' : 'Save paired-market settings'}</button> : <p className="text-xs text-slate-500">Only billing admins can change these settings.</p>}
        {status && <p role="status" className={`text-xs ${save.isError ? 'text-rose-800' : 'text-teal-800'}`}>{status}</p>}
    </form>;
}

/** Dedicated pair policy; it saves separately from general free-trial and discount rules. */
export default function PairedMarketPromotionPanel({ marketId }) {
    const pairs = useQuery({ queryKey: ['subsidiary-promotions'], queryFn: () => api.get('/crm/settings/subsidiary-promotions').then(r => r.data), staleTime: 15000 });
    const rows = (pairs.data?.pairs || []).filter(p => Number(p.source_platform_id) === Number(marketId) || Number(p.target_platform_id) === Number(marketId));
    if (pairs.isSuccess && rows.length === 0) return null;
    return <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]" aria-label="Paired market settings">
        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Paired market</p>
        <h5 className="mt-1 text-base font-semibold text-slate-950">Subscription period and profile copy</h5>
        <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">Applies when sales include the paired market with a subscription. Saved on its own, without changing the rules above.</p>
        <div className="mt-4 space-y-6">
            {pairs.isLoading && <p role="status" className="text-sm text-slate-500">Loading paired-market settings…</p>}
            {pairs.isError && <p role="alert" className="text-sm text-amber-900">Paired-market settings could not be loaded. <button type="button" className="underline" onClick={() => pairs.refetch()}>Try again</button></p>}
            {rows.map(pair => <PairForm key={`${pair.source_platform_id}-${pair.target_platform_id}`} pair={pair} editable={pairs.data.editable} copyEnabled={pairs.data.copy_enabled} />)}
        </div>
    </section>;
}
