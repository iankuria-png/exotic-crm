import React, { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { Drawer } from '../db-observatory/shared';

const TIER_ORDER = ['vvip', 'vip', 'premium', 'basic'];

function money(value, currency) {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    if (['CFA', 'XOF'].includes(currency)) return `${n.toLocaleString('fr-FR').replace(/[  ]/g, ' ')} FCFA`;
    return `${currency || ''} ${n.toLocaleString('en-KE', { maximumFractionDigits: 2 })}`.trim();
}
const shortDate = (iso) => (iso ? new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
const lengthLabel = (d) => (d === 7 ? '1 week' : d === 14 ? '2 weeks' : d === 30 ? '1 month' : `${d} days`);

function Icon({ d, className = 'h-4 w-4' }) {
    return <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d={d} /></svg>;
}
const ICON = {
    link: 'M13.8 10.2a4 4 0 0 0-5.6 0l-4 4a4 4 0 1 0 5.6 5.6l1.1-1.1m-.7-4.9a4 4 0 0 0 5.6 0l4-4a4 4 0 0 0-5.6-5.6l-1.1 1.1',
    send: 'M4 12 20 4l-6 16-3-7-7-1Z',
    copy: 'M9 9h10v11H9zM5 15V4h10',
    check: 'M5 12.5l4.2 4.2L19 7',
    chevron: 'M9 6l6 6-6 6',
    alert: 'M12 8v5m0 3.5v.5M10.3 3.9 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z',
};

function StatusPill({ data, url }) {
    const [label, tone] = data?.mode === 'off' ? ['Off in this market', 'bg-slate-100 text-slate-600 ring-slate-200']
        : data?.mode === 'test' ? ['Test only', 'bg-amber-50 text-amber-800 ring-amber-200']
        : url ? ['Active', 'bg-emerald-50 text-emerald-700 ring-emerald-200'] : ['Not set up', 'bg-slate-100 text-slate-600 ring-slate-200'];
    return <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${tone}`}>{label === 'Active' ? <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true" /> : null}{label}</span>;
}

function ChoiceCard({ checked, disabled, onSelect, title, description, note, children }) {
    const id = useId();
    return (
        <div className={`rounded-xl border transition ${checked ? 'border-teal-600 bg-teal-50/40 ring-1 ring-teal-600' : 'border-slate-200 bg-white'} ${disabled ? 'opacity-60' : ''}`}>
            <label className={`flex items-start gap-3 p-3.5 ${disabled ? 'cursor-not-allowed' : 'cursor-pointer'}`}>
                <input type="radio" name="pay-link-offer" aria-labelledby={`${id}-t`} aria-describedby={`${id}-d`} className="mt-0.5 h-4 w-4 accent-teal-700" checked={checked} disabled={disabled} onChange={onSelect} />
                <span className="min-w-0">
                    <span id={`${id}-t`} className="block text-sm font-semibold text-slate-900">{title}</span>
                    <span id={`${id}-d`} className="mt-0.5 block text-[13px] leading-5 text-slate-500">{description}</span>
                    {note ? <span className="mt-1 block text-xs text-slate-500">{note}</span> : null}
                </span>
            </label>
            {checked && children ? <div className="border-t border-teal-100 px-3.5 pb-3.5 pt-3">{children}</div> : null}
        </div>
    );
}

export default function ClientPayLinkCard({ client }) {
    const queryClient = useQueryClient();
    const key = ['client-pay-link', client.id];
    const query = useQuery({ queryKey: key, queryFn: () => api.get(`/crm/clients/${client.id}/pay-link`).then((r) => r.data), retry: false });
    const data = query.data;

    const [open, setOpen] = useState(false);
    const [offer, setOffer] = useState('choose');
    const [amount, setAmount] = useState('');
    const [preview, setPreview] = useState(null);
    const [previewState, setPreviewState] = useState('idle');
    const [suggested, setSuggested] = useState({ url: '', amount: '' });
    const [preparing, setPreparing] = useState(false);
    const [action, setAction] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [copied, setCopied] = useState(false);
    const seq = useRef(0);

    const flexibleOn = data?.flexible_rules?.mode && data.flexible_rules.mode !== 'off' && data.flexible_rules.surfaces?.crm_link;
    const permanentUrl = data?.link?.url || '';
    const unresolved = data?.unresolved || [];
    const name = client.name || 'this client';

    async function change(which, why) {
        setBusy(true); setMessage('');
        try {
            const result = await api.post(`/crm/clients/${client.id}/pay-link${which ? `/${which}` : ''}`, { reason: why });
            queryClient.setQueryData(key, result.data); setAction(''); setReason('');
            if (which) setMessage(which === 'rotate' ? 'A new link replaced the old one.' : 'The link was revoked.');
        } catch (e) { setMessage(e.response?.data?.message || 'Could not update this pay link.'); }
        finally { setBusy(false); }
    }
    useEffect(() => {
        if (data && !data.link && data.mode !== 'off' && !busy && !message && !action) change('', 'Created when staff opened the client pay-link card');
    }, [data?.mode, data?.link]);

    // Catalogue for chips, then a debounced server preview of what the amount buys.
    useEffect(() => {
        if (!open || offer !== 'suggest' || !flexibleOn) return undefined;
        const id = ++seq.current;
        setPreviewState(amount ? 'loading' : (preview ? 'ready' : 'loading'));
        const timer = window.setTimeout(async () => {
            try {
                const r = await api.post(`/crm/clients/${client.id}/pay-link/quote`, { amount: amount || null });
                if (id === seq.current) { setPreview(r.data); setPreviewState('ready'); }
            } catch (e) { if (id === seq.current) { setPreviewState('error'); } }
        }, amount ? 350 : 0);
        return () => window.clearTimeout(timer);
    }, [open, offer, amount, flexibleOn]);

    const results = useMemo(() => [...(preview?.results || [])].sort((a, b) => TIER_ORDER.indexOf(a.tier) - TIER_ORDER.indexOf(b.tier)), [preview]);
    const anyValid = results.some((r) => r.valid);
    const currency = preview?.currency || client.platform?.currency_code || '';
    const chipPlan = useMemo(() => {
        const plans = preview?.plans || [];
        return (preview?.live && plans.find((p) => p.tier === preview?.current_tier)) || plans.find((p) => p.tier === 'vip') || plans[0];
    }, [preview]);

    // Prepare the signed suggested link once the amount buys something.
    useEffect(() => {
        if (offer !== 'suggest' || !anyValid || !amount) { setSuggested({ url: '', amount: '' }); return undefined; }
        if (suggested.amount === amount && suggested.url) return undefined;
        const timer = window.setTimeout(async () => {
            setPreparing(true);
            try { const r = await api.post(`/crm/clients/${client.id}/pay-link/suggestion`, { suggested_amount: amount }); setSuggested({ url: r.data.url, amount }); }
            catch (e) { setMessage(e.response?.data?.message || 'Could not prepare the suggested link.'); }
            finally { setPreparing(false); }
        }, 250);
        return () => window.clearTimeout(timer);
    }, [offer, anyValid, amount]);

    const sendUrl = offer === 'suggest' ? suggested.url : permanentUrl;
    const sendReady = !!sendUrl && !preparing && !(offer === 'suggest' && suggested.amount !== amount);
    const live = preview?.live ? results.find((r) => r.valid && r.tier === preview.current_tier) : null;
    const text = offer !== 'suggest' ? `Renew your profile: ${sendUrl}`
        : live ? `Add days to your ${live.plan} profile: ${money(live.charge, currency)} gets you ${live.days} more days. You can change the amount before paying. ${sendUrl}`
        : `Renew your profile with ${money(amount, currency)}. Pick your plan and change the amount before paying if you like. ${sendUrl}`;
    const phone = (client.phone_normalized || '').replace(/\D/g, '');

    function openDrawer() { setOpen(true); setMessage(''); setCopied(false); }
    function closeDrawer() { setOpen(false); setAction(''); setReason(''); }
    async function copy(value) {
        try { await navigator.clipboard.writeText(value); setCopied(true); window.setTimeout(() => setCopied(false), 1800); }
        catch { setMessage('Copy failed. Select the link in Link details instead.'); }
    }

    const stats = data?.stats || {};
    const statsLine = permanentUrl ? `${data.link.open_count || 0} opens · ${stats.paid || 0} paid · ${stats.activated || 0} activated` : null;

    return (
        <section className="mt-5 border-t border-slate-200 pt-4" aria-labelledby={`pay-link-${client.id}`}>
            <div className="flex items-center gap-3">
                <span className="grid h-9 w-9 flex-none place-items-center rounded-lg bg-teal-50 text-teal-700"><Icon d={ICON.link} /></span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 id={`pay-link-${client.id}`} className="text-sm font-semibold text-slate-900">Pay link</h3>
                        {query.isLoading ? null : <StatusPill data={data} url={permanentUrl} />}
                    </div>
                    <p className="mt-0.5 truncate text-xs text-slate-500">
                        {query.isLoading ? 'Loading…' : query.isError ? 'Could not load the pay link.' : data?.mode === 'off' ? 'Turn on under Settings → Billing → Pay links.' : statsLine || (busy ? 'Creating link…' : 'No active link yet.')}
                    </p>
                </div>
                {query.isError ? <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => query.refetch()}>Retry</button>
                    : <button type="button" className="crm-btn-primary gap-1.5 px-3 py-1.5 text-xs" disabled={!permanentUrl} onClick={openDrawer}><Icon d={ICON.send} className="h-3.5 w-3.5" />Send</button>}
            </div>
            {unresolved.length ? <p className="mt-3 flex gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900"><Icon d={ICON.alert} className="mt-px h-3.5 w-3.5 flex-none" />{unresolved.some((c) => c.payment_state === 'completed') ? 'Payment received; activation pending.' : 'A payment is still being checked.'} Review it in Billing → Pay links.</p> : null}
            {message && !open ? <p role="status" className="mt-2 text-xs text-slate-600">{message}</p> : null}

            <Drawer
                open={open}
                onClose={closeDrawer}
                width="max-w-md"
                title="Send pay link"
                subtitle={`${name}${data?.mode === 'test' ? ' · test mode' : ''}`}
                footer={<p className="text-xs leading-5 text-slate-500">The link is private to {name}. They confirm the final price on their phone before anything is charged.</p>}
            >
                <div className="space-y-6">
                    {unresolved.length ? <p className="flex gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-[13px] text-amber-900"><Icon d={ICON.alert} className="mt-0.5 h-4 w-4 flex-none" />A payment on this link is still being processed. Avoid sending a second link until it settles.</p> : null}

                    <section aria-labelledby="pl-step-1">
                        <h4 id="pl-step-1" className="mb-2.5 flex items-center gap-2 text-sm font-semibold text-slate-900"><span className="grid h-5 w-5 place-items-center rounded-full bg-slate-900 text-[11px] text-white">1</span>What should they pay?</h4>
                        <div className="space-y-2" role="radiogroup" aria-labelledby="pl-step-1">
                            <ChoiceCard checked={offer === 'choose'} onSelect={() => setOffer('choose')} title="Let them choose" description="They pick a plan and how much to spend on their phone." />
                            <ChoiceCard
                                checked={offer === 'suggest'}
                                disabled={!flexibleOn}
                                onSelect={() => setOffer('suggest')}
                                title="Suggest an amount"
                                description="Start them on an amount you agreed. They can still change it."
                                note={!flexibleOn ? 'Flexible amounts are off for CRM links in this market.' : null}
                            >
                                <label htmlFor="pl-amount" className="text-xs font-semibold text-slate-700">Amount</label>
                                <div className="mt-1 flex items-center rounded-lg border border-slate-300 bg-white focus-within:border-teal-600 focus-within:ring-2 focus-within:ring-teal-100">
                                    <span className="pl-3 text-sm font-semibold text-slate-500">{['CFA', 'XOF'].includes(currency) ? 'FCFA' : currency}</span>
                                    <input id="pl-amount" autoFocus inputMode="numeric" className="w-full rounded-lg border-0 bg-transparent px-2 py-2.5 text-lg font-semibold tabular-nums text-slate-900 focus:outline-none focus:ring-0" placeholder="0"
                                        value={amount ? Number(amount).toLocaleString('en-KE') : ''} onChange={(e) => setAmount(e.target.value.replace(/\D/g, '').slice(0, 9))} />
                                </div>
                                {chipPlan?.quick_picks?.length ? <div className="mt-2 flex flex-wrap gap-1.5">
                                    {chipPlan.quick_picks.map((p) => <button key={p.days} type="button" onClick={() => setAmount(String(Math.round(Number(p.amount))))}
                                        className={`rounded-full border px-2.5 py-1 text-xs font-semibold transition ${Number(amount) === Math.round(Number(p.amount)) ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'}`}>
                                        {money(p.amount, currency)} <span className="font-normal opacity-75">· {lengthLabel(p.days)}</span></button>)}
                                </div> : null}

                                <div className="mt-3 rounded-lg bg-white ring-1 ring-slate-200" aria-live="polite" aria-busy={previewState === 'loading'}>
                                    {!amount ? <p className="px-3 py-3 text-xs text-slate-500">Enter an amount to see how many days it buys{preview?.live ? ` on their current ${chipPlan?.plan || ''} plan` : ' on each plan'}.</p>
                                        : previewState === 'error' ? <p className="px-3 py-3 text-xs text-rose-700">Could not calculate days right now. You can still send the link.</p>
                                        : previewState === 'loading' && !results.length ? <p className="px-3 py-3 text-xs text-slate-500">Working out the days…</p>
                                        : <ul className={`divide-y divide-slate-100 ${previewState === 'loading' ? 'opacity-60' : ''}`}>
                                            <li className="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">What {money(amount, currency)} buys</li>
                                            {results.map((r) => <li key={r.product_id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                                                <span className="min-w-0"><span className="block text-sm font-semibold text-slate-900">{r.plan}</span>
                                                    <span className="block text-xs text-slate-500">{r.valid ? `${r.days} days · until ${shortDate(r.ends_at)}${r.package ? ' · package' : ''}` : (r.message || (r.nudge?.kind === 'minimum' ? `Minimum ${money(r.nudge.amount, currency)}` : 'Not enough for this plan'))}</span></span>
                                                {r.valid ? <span className="text-right"><span className="block text-sm font-semibold tabular-nums text-slate-900">{money(r.charge, currency)}</span>{Number(r.charge) < Number(amount) ? <span className="block text-[11px] text-slate-500">whole days</span> : null}</span>
                                                    : <span className="text-xs text-slate-400">—</span>}
                                            </li>)}
                                        </ul>}
                                </div>
                            </ChoiceCard>
                        </div>
                    </section>

                    <section aria-labelledby="pl-step-2">
                        <h4 id="pl-step-2" className="mb-2.5 flex items-center gap-2 text-sm font-semibold text-slate-900"><span className="grid h-5 w-5 place-items-center rounded-full bg-slate-900 text-[11px] text-white">2</span>Send it</h4>
                        <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                            <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Message preview</p>
                            <p className="mt-1.5 max-w-[34ch] rounded-2xl rounded-tl-sm bg-white px-3 py-2 text-[13px] leading-5 text-slate-700 shadow-sm ring-1 ring-slate-200 [overflow-wrap:anywhere]">
                                {sendReady ? text.replace(sendUrl, '') : offer === 'suggest' && !anyValid ? 'Choose an amount that buys at least the minimum days.' : 'Preparing link…'}
                                {sendReady ? <span className="text-teal-700 underline">{sendUrl.replace(/^https?:\/\//, '').replace(/#.*/, '')}{sendUrl.includes('#') ? '…' : ''}</span> : null}
                            </p>
                        </div>
                        <div className="mt-3 grid grid-cols-3 gap-2">
                            <a aria-disabled={!sendReady} className={`crm-btn-primary gap-1.5 text-xs ${!sendReady || !phone ? 'pointer-events-none opacity-50' : ''}`} target="_blank" rel="noreferrer" href={sendReady && phone ? `https://wa.me/${phone}?text=${encodeURIComponent(text)}` : undefined}>WhatsApp</a>
                            <a aria-disabled={!sendReady} className={`crm-btn-secondary text-xs ${!sendReady || !phone ? 'pointer-events-none opacity-50' : ''}`} href={sendReady && phone ? `sms:${client.phone_normalized}?body=${encodeURIComponent(text)}` : undefined}>SMS</a>
                            <button type="button" disabled={!sendReady} className="crm-btn-secondary gap-1.5 text-xs disabled:opacity-50" onClick={() => copy(sendUrl)}>{copied ? <><Icon d={ICON.check} className="h-3.5 w-3.5 text-emerald-600" />Copied</> : <><Icon d={ICON.copy} className="h-3.5 w-3.5" />Copy</>}</button>
                        </div>
                        {!phone ? <p className="mt-2 text-xs text-slate-500">No phone number on file. Copy the link instead.</p> : null}
                        {message ? <p role="status" className="mt-2 text-xs text-slate-600">{message}</p> : null}
                    </section>

                    <details className="group rounded-xl ring-1 ring-slate-200">
                        <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-3.5 py-3 text-sm font-semibold text-slate-700 [&::-webkit-details-marker]:hidden">
                            Link details and controls
                            <Icon d={ICON.chevron} className="h-4 w-4 text-slate-400 transition group-open:rotate-90" />
                        </summary>
                        <div className="space-y-3 border-t border-slate-100 px-3.5 py-3">
                            <dl className="grid grid-cols-3 gap-2 text-center">
                                {[['Opens', data?.link?.open_count || 0], ['Paid', stats.paid || 0], ['Activated', stats.activated || 0]].map(([l, v]) => <div key={l} className="rounded-lg bg-slate-50 py-2"><dt className="text-[11px] text-slate-500">{l}</dt><dd className="text-base font-semibold tabular-nums text-slate-900">{v}</dd></div>)}
                            </dl>
                            {data?.link?.last_opened_at ? <p className="text-xs text-slate-500">Last opened {new Date(data.link.last_opened_at).toLocaleString()}</p> : null}
                            <div className="flex items-center gap-2">
                                <code className="min-w-0 flex-1 truncate rounded-md bg-slate-50 px-2.5 py-2 font-mono text-xs text-slate-600 ring-1 ring-slate-200">{permanentUrl.replace(/^https?:\/\//, '')}</code>
                                <button type="button" className="crm-btn-secondary px-2.5 py-1.5 text-xs" onClick={() => copy(permanentUrl)} aria-label="Copy permanent link"><Icon d={ICON.copy} className="h-3.5 w-3.5" /></button>
                            </div>
                            {action ? <form className="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200" onSubmit={(e) => { e.preventDefault(); change(action, reason); }}>
                                <p className="text-xs text-slate-600">{action === 'rotate' ? 'The old link stops accepting new payments. Payments already started keep processing.' : 'New payments stop. Payments already started keep processing.'}</p>
                                <label className="mt-2 block text-xs font-semibold text-slate-700">Reason<input autoFocus className="crm-input mt-1" value={reason} onChange={(e) => setReason(e.target.value)} required minLength={5} maxLength={1000} /></label>
                                <div className="mt-2 flex gap-2"><button className={`${action === 'revoke' ? 'crm-btn-danger' : 'crm-btn-primary'} text-xs`} disabled={busy}>{action === 'rotate' ? 'Replace link' : 'Revoke link'}</button><button type="button" className="crm-btn-secondary text-xs" onClick={() => setAction('')}>Cancel</button></div>
                            </form> : <div className="flex gap-2">
                                <button type="button" className="crm-btn-secondary text-xs" onClick={() => { setAction('rotate'); setReason(''); }}>Replace link</button>
                                <button type="button" className="crm-btn-danger text-xs" onClick={() => { setAction('revoke'); setReason(''); }}>Revoke</button>
                            </div>}
                        </div>
                    </details>
                </div>
            </Drawer>
        </section>
    );
}
