import React, { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../services/api';
import PageHeader from '../components/PageHeader';
import LoveGifts from '../components/monetization/LoveGifts';
import SendLoveSettings from '../components/settings/monetization/SendLoveSettings';

const TABS = [['overview', 'Overview'], ['gifts', 'Gifts'], ['notes', 'Notes'], ['setup', 'Setup']];
const NOTE_STATES = [['', 'All', 'all'], ['visible', 'Visible', 'visible'], ['reported', 'Reported', 'reported'], ['hidden_by_creator', 'Hidden by creator', 'hidden_by_creator'], ['removed_by_staff', 'Removed', 'removed_by_staff']];
const PROVIDERS = { kopokopo: 'M-Pesa · KopoKopo', pawapay: 'pawaPay' };
const FAILURES = { cancelled_by_user: 'Cancelled on the phone', insufficient_funds: 'Insufficient funds', timeout: 'Prompt timed out' };
const field = 'min-h-11 rounded-md border border-slate-300 bg-white px-3 text-sm';
const card = 'rounded-lg border border-slate-200 bg-white';
const money = (n, c) => `${c} ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
const number = (n) => Number(n || 0).toLocaleString();
const pct = (a, b) => (b ? Math.round((1000 * a) / b) / 10 : 0);
const human = (v) => String(v || 'unknown').replaceAll('_', ' ').replace(/^\w/, (c) => c.toUpperCase());
const localDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return localDate(d); };
const shortDay = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });

export default function SendLove() {
    const [params, setParams] = useSearchParams();
    const tab = TABS.some(([key]) => key === params.get('tab')) ? params.get('tab') : 'overview';
    const noteState = NOTE_STATES.some(([key]) => key === params.get('state')) ? params.get('state') : '';
    const go = (next, state = '') => setParams((current) => {
        const n = new URLSearchParams(current);
        n.set('tab', next);
        if (state) n.set('state', state); else n.delete('state');
        return n;
    }, { replace: true });
    const [market, setMarket] = useState('');
    const [from, setFrom] = useState(daysAgo(29));
    const [to, setTo] = useState(localDate(new Date()));
    const [range, setRange] = useState(30);
    const [tests, setTests] = useState(false);
    const filters = { platform_id: market || undefined, from: from || undefined, to: to || undefined, include_tests: tests ? 1 : undefined };
    const overview = useQuery({ queryKey: ['send-love-overview', filters], queryFn: async () => (await api.get('/crm/monetization/love/overview', { params: filters })).data });
    const markets = overview.data?.markets || [];
    const applyRange = (days) => {
        setRange(days);
        if (!days) { setFrom(''); setTo(''); return; }
        setFrom(days === 1 ? localDate(new Date()) : daysAgo(days - 1));
        setTo(localDate(new Date()));
    };

    return (
        <div className="space-y-5">
            <PageHeader
                title="Send Love"
                subtitle="Visitor gifts to creators, private notes and per-market setup."
                actions={<Link to="/payments?purpose=send_love" className="inline-flex min-h-11 items-center rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Payments</Link>}
            />
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3">
                <p className="text-sm font-semibold text-teal-900">Love is creator wallet credit, recorded separately from subscription revenue. Shared payment rails, separate books.</p>
                <code className="rounded-md border border-teal-200 bg-white px-2 py-1 text-xs font-semibold text-teal-800">purpose: send_love</code>
            </div>

            <section className={`${card} p-4`} aria-labelledby="love-controls">
                <h2 id="love-controls" className="text-sm font-semibold text-slate-950">Controls</h2>
                <p className="mt-1 text-xs text-slate-500">Market and date range apply to the overview, gifts, notes and export. Setup always shows every market you can access.</p>
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    <label className="text-xs font-medium text-slate-500">Market
                        <select className={`${field} mt-1 block min-w-56`} value={market} onChange={(e) => setMarket(e.target.value)}>
                            <option value="">All authorised markets</option>
                            {markets.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                    </label>
                    <label className="text-xs font-medium text-slate-500">From<input type="date" className={`${field} mt-1 block`} value={from} max={to || undefined} onChange={(e) => { setFrom(e.target.value); setRange(null); }} /></label>
                    <label className="text-xs font-medium text-slate-500">To<input type="date" className={`${field} mt-1 block`} value={to} min={from || undefined} onChange={(e) => { setTo(e.target.value); setRange(null); }} /></label>
                    <div className="flex min-h-11 items-center rounded-md border border-slate-200 bg-slate-50 p-1" role="group" aria-label="Date range">
                        {[[1, 'Today'], [7, '7D'], [30, '30D'], [0, 'All time']].map(([days, text]) => (
                            <button key={text} type="button" aria-pressed={range === days} onClick={() => applyRange(days)} className={`min-h-9 rounded px-3 text-xs font-semibold ${range === days ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}>{text}</button>
                        ))}
                    </div>
                    <label className="flex min-h-11 items-center gap-2 px-2 text-sm text-slate-700"><input type="checkbox" checked={tests} onChange={(e) => setTests(e.target.checked)} />Include sandbox</label>
                </div>
            </section>

            <div role="tablist" aria-label="Send Love workspace" className="flex flex-wrap gap-1 border-b border-slate-200">
                {TABS.map(([key, text]) => (
                    <button key={key} id={`love-tab-${key}`} role="tab" type="button" aria-selected={tab === key} aria-controls="love-panel" onClick={() => go(key)} className={`min-h-11 border-b-2 px-4 text-sm ${tab === key ? 'border-slate-900 font-semibold text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'}`}>
                        {text}
                        {key === 'notes' && overview.data?.summary?.reported > 0 && <span className="ml-2 rounded-md bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800">{overview.data.summary.reported}</span>}
                    </button>
                ))}
            </div>

            <div id="love-panel" role="tabpanel" aria-labelledby={`love-tab-${tab}`} className="space-y-4">
                {tab === 'overview' && <Overview query={overview} onNotes={(state) => go('notes', state)} onGifts={() => go('gifts')} onSetup={() => go('setup')} filtered={Boolean(from)} from={from} to={to} />}
                {tab === 'gifts' && <LoveGifts market={market} clientId={null} from={from} to={to} tests={tests} />}
                {tab === 'notes' && <Notes filters={filters} state={noteState} onState={(state) => go('notes', state)} />}
                {tab === 'setup' && <Setup />}
            </div>
        </div>
    );
}

function Kpi({ label, value, hint, tone = 'slate', onClick }) {
    const tones = { slate: 'text-slate-900', good: 'text-emerald-700', warn: 'text-amber-700', bad: 'text-rose-700' };
    const body = <>
        <span className="text-sm font-semibold text-slate-600">{label}</span>
        <span className={`mt-2 block text-3xl font-semibold tracking-tight ${tones[tone]}`}>{Array.isArray(value) ? value.map((v) => <span key={v} className="block">{v}</span>) : value}</span>
        {hint && <span className="mt-2 block text-sm text-slate-500">{hint}</span>}
    </>;
    return onClick
        ? <button type="button" onClick={onClick} className={`${card} p-5 text-left transition hover:border-slate-300 hover:bg-slate-50`}>{body}</button>
        : <div className={`${card} p-5`}>{body}</div>;
}

function Overview({ query, onNotes, onGifts, onSetup, filtered, from, to }) {
    if (query.isPending) return <p role="status" className="text-sm text-slate-500">Loading Send Love activity…</p>;
    if (query.isError) return <div role="alert" className={`${card} p-5 text-sm`}>Could not load Send Love activity. <button type="button" className="font-semibold underline" onClick={() => query.refetch()}>Try again</button></div>;
    const d = query.data;
    const s = d.summary;
    if (!d.markets.length) {
        return <Empty title="Send Love isn't set up for your markets yet" text="Enable it for a market in Setup. Gifts and notes appear here once visitors start sending love." action={<button type="button" className="min-h-11 rounded-md bg-teal-700 px-4 text-sm font-semibold text-white" onClick={onSetup}>Open setup</button>} />;
    }
    if (!s.attempts) {
        return <Empty title="No gifts in this range yet" text="Try a wider date range or All time. Sandbox gifts are hidden unless Include sandbox is on." action={<button type="button" className="min-h-11 rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700" onClick={onSetup}>Check market setup</button>} />;
    }
    const statuses = s.statuses || {};
    const rate = s.completion_rate;
    const currencies = d.totals.map((t) => t.currency);
    return <>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi label="Love sent" value={d.totals.length ? d.totals.map((t) => money(t.gross, t.currency)) : '—'} hint={d.totals.length > 1 ? 'Native currencies kept separate' : 'Credited to creator wallets'} />
            <Kpi label="Gifts sent" value={number(s.sent)} hint={`${number(s.attempts)} attempts`} onClick={onGifts} />
            <Kpi label="Checkout completion" value={`${rate}%`} tone={rate >= 60 ? 'good' : rate >= 30 ? 'warn' : 'bad'} hint={`${number(statuses.failed)} failed · ${number(statuses.pending_payment)} pending · ${number(statuses.review)} in review`} />
            <Kpi label="Unique senders" value={number(s.senders)} hint={`${number(s.creators)} creators received love`} />
        </div>
        <div className="grid gap-3 md:grid-cols-3">
            <Kpi label="Average gift" value={d.totals.length ? d.totals.map((t) => money(t.average, t.currency)) : '—'} hint="Settled gifts only" />
            <Kpi label="Numbers shared" value={number(s.contact_shared)} hint={`${pct(s.contact_shared, s.sent)}% of gifts · sender opted in`} />
            <Kpi label="Notes" value={number(s.notes)} hint={s.reported ? `${number(s.reported)} reported by creators · review` : 'None reported by creators'} tone={s.reported ? 'warn' : 'slate'} onClick={() => onNotes(s.reported ? 'reported' : '')} />
        </div>
        <Trend rows={d.trend} window={d.trend_window} filtered={filtered} from={from} to={to} currencies={currencies} />
        <div className="grid gap-4 lg:grid-cols-2">
            <AmountMix rows={d.amounts} sent={s.sent} />
            <section className={`${card} p-5`} aria-labelledby="love-top">
                <h3 id="love-top" className="font-semibold text-slate-900">Top creators</h3>
                <p className="mt-1 text-sm text-slate-500">By love received in this range.</p>
                {d.top_creators.length ? <ol className="mt-3 divide-y divide-slate-100">
                    {d.top_creators.map((c, i) => (
                        <li key={`${c.client_id}-${c.currency}`} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                            <span className="flex min-w-0 items-center gap-3"><span className="w-5 text-right text-xs font-semibold text-slate-400">{i + 1}</span><Link className="truncate font-semibold text-slate-900 hover:underline" to={`/clients/${c.client_id}`}>{c.client?.name || `Client ${c.client_id}`}</Link></span>
                            <span className="shrink-0 text-slate-600">{number(c.gifts)} gifts · <span className="font-semibold text-slate-900">{money(c.gross, c.currency)}</span></span>
                        </li>
                    ))}
                </ol> : <p className="mt-3 text-sm text-slate-500">No settled gifts yet.</p>}
            </section>
        </div>
        <section className={`${card} p-5`} aria-labelledby="love-failures">
            <h3 id="love-failures" className="font-semibold text-slate-900">Why gifts didn't go through</h3>
            {d.failures.length ? <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {d.failures.map((f) => <li key={f.failure_reason || 'unknown'} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 text-sm"><span className="text-slate-700">{FAILURES[f.failure_reason] || human(f.failure_reason || 'Not reported by provider')}</span><span className="font-semibold text-slate-900">{number(f.count)}</span></li>)}
            </ul> : <p className="mt-2 text-sm text-slate-500">No failed attempts in this range.</p>}
        </section>
    </>;
}

/* Gifts per day: one series, so one hue and no legend; the card title names it. */
function Trend({ rows, window, filtered, from, to, currencies }) {
    const [hover, setHover] = useState(null);
    const days = useMemo(() => {
        const byDay = {};
        rows.forEach((r) => {
            const day = String(r.day).slice(0, 10);
            byDay[day] = byDay[day] || { gifts: 0, gross: {} };
            byDay[day].gifts += Number(r.gifts);
            byDay[day].gross[r.currency] = (byDay[day].gross[r.currency] || 0) + Number(r.gross);
        });
        const end = to ? new Date(`${to}T00:00:00`) : new Date();
        const start = filtered && from ? new Date(`${from}T00:00:00`) : new Date(end.getTime() - 29 * 86400000);
        const out = [];
        for (let d = new Date(start); d <= end && out.length < 120; d.setDate(d.getDate() + 1)) {
            const key = localDate(d);
            out.push({ day: key, gifts: byDay[key]?.gifts || 0, gross: byDay[key]?.gross || {} });
        }
        return out;
    }, [rows, filtered, from, to]);
    const max = Math.max(1, ...days.map((d) => d.gifts));
    const active = hover !== null ? days[hover] : null;
    return (
        <section className={`${card} p-5`} aria-labelledby="love-trend">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 id="love-trend" className="font-semibold text-slate-900">Gifts per day</h3>
                    <p className="mt-1 text-sm text-slate-500">{window === 'last_30_days' ? 'Last 30 days' : 'Selected range'} · settled gifts{currencies.length > 1 ? ' across currencies' : ''}</p>
                </div>
                <p className="min-h-10 text-right text-sm text-slate-600" aria-live="polite">
                    {active ? <><span className="block font-semibold text-slate-900">{shortDay(active.day)} · {number(active.gifts)} gifts</span>{Object.entries(active.gross).map(([c, v]) => <span key={c} className="block">{money(v, c)}</span>)}</> : <span className="text-slate-400">Hover a day for details</span>}
                </p>
            </div>
            <div className="relative mt-4 h-40">
                <span className="absolute left-0 top-0 text-[11px] text-slate-400">{max}</span>
                <span className="absolute inset-x-0 top-2 border-t border-dashed border-slate-100" aria-hidden="true" />
                <div className="absolute inset-x-0 bottom-0 top-2 flex items-end gap-[2px] border-b border-slate-200 pl-6" onMouseLeave={() => setHover(null)}>
                    {days.map((d, i) => (
                        <div key={d.day} className="flex h-full flex-1 items-end" onMouseEnter={() => setHover(i)} onFocus={() => setHover(i)} tabIndex={0} aria-label={`${shortDay(d.day)}: ${d.gifts} gifts`}>
                            <div className={`w-full rounded-t-[4px] ${hover === i ? 'bg-teal-800' : 'bg-teal-600'}`} style={{ height: d.gifts ? `${Math.max(3, (100 * d.gifts) / max)}%` : 0 }} />
                        </div>
                    ))}
                </div>
            </div>
            <div className="mt-2 flex justify-between pl-6 text-[11px] text-slate-400">
                <span>{days[0] && shortDay(days[0].day)}</span>
                <span>{days[Math.floor(days.length / 2)] && shortDay(days[Math.floor(days.length / 2)].day)}</span>
                <span>{days.at(-1) && shortDay(days.at(-1).day)}</span>
            </div>
            <details className="mt-3 text-sm">
                <summary className="cursor-pointer text-slate-600">View as table</summary>
                <table className="mt-2 w-full text-left text-sm">
                    <thead className="text-xs text-slate-500"><tr><th className="py-1">Day</th><th>Gifts</th><th>Love sent</th></tr></thead>
                    <tbody>{days.filter((d) => d.gifts).map((d) => <tr key={d.day} className="border-t border-slate-100"><td className="py-1">{shortDay(d.day)}</td><td>{d.gifts}</td><td>{Object.entries(d.gross).map(([c, v]) => money(v, c)).join(' · ')}</td></tr>)}</tbody>
                </table>
            </details>
        </section>
    );
}

function AmountMix({ rows, sent }) {
    return (
        <section className={`${card} p-5`} aria-labelledby="love-amounts">
            <h3 id="love-amounts" className="font-semibold text-slate-900">Amount mix</h3>
            <p className="mt-1 text-sm text-slate-500">Which amounts visitors choose. Use it to tune each market's presets.</p>
            {rows.length ? <ul className="mt-3 space-y-3">
                {rows.map((r) => {
                    const share = pct(Number(r.gifts), sent);
                    return <li key={`${r.currency}-${r.amount}`}>
                        <div className="flex justify-between text-sm"><span className="font-semibold text-slate-900">{money(r.amount, r.currency)}</span><span className="text-slate-600">{number(r.gifts)} gifts · {share}%</span></div>
                        <div className="mt-1 h-1.5 rounded-full bg-slate-100"><div className="h-1.5 rounded-full bg-teal-600" style={{ width: `${share}%` }} /></div>
                    </li>;
                })}
            </ul> : <p className="mt-3 text-sm text-slate-500">No settled gifts yet.</p>}
        </section>
    );
}

function Notes({ filters, state, onState }) {
    const qc = useQueryClient();
    const [page, setPage] = useState(1);
    const [action, setAction] = useState(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const params = { ...filters, state: state || undefined, page };
    const q = useQuery({ queryKey: ['send-love-notes', params], queryFn: async () => (await api.get('/crm/monetization/love/notes', { params })).data, retry: false });
    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        try {
            await api.post(`/crm/monetization/love/${action.id}/${action.type}`, { reason });
            setAction(null);
            setMessage(action.type === 'remove-note' ? 'Note removed. The creator no longer sees it.' : 'Shared number cleared.');
            await Promise.all([q.refetch(), qc.invalidateQueries({ queryKey: ['send-love-overview'] })]);
        } catch (err) {
            setMessage(err.response?.data?.message || 'Could not complete this action.');
        } finally { setBusy(false); }
    }
    if (q.isError && q.error?.response?.status === 403) return <Empty title="Notes are limited to admins" text="Private notes between visitors and creators are visible only to admin and sub-admin staff for moderation." />;
    const counts = q.data?.counts || {};
    const rows = q.data?.notes?.data || [];
    return <>
        <div className="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1" role="group" aria-label="Note state">
            {NOTE_STATES.map(([key, text, count]) => (
                <button key={text} type="button" aria-pressed={state === key} onClick={() => { setPage(1); onState(key); }} className={`min-h-9 rounded px-3 text-sm ${state === key ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}>
                    {text}{counts[count] !== undefined && <span className="ml-1.5 text-xs text-slate-500">{counts[count]}</span>}
                </button>
            ))}
        </div>
        <p className="text-xs text-slate-500">Notes are private between the visitor and the creator. Staff see them only to moderate; sender numbers are never shown here.</p>
        {message && <p role="status" className="text-sm text-slate-700">{message}</p>}
        {q.isPending && <p role="status" className="text-sm text-slate-500">Loading notes…</p>}
        {q.isError && q.error?.response?.status !== 403 && <div role="alert" className={`${card} p-5 text-sm`}>Could not load notes. <button type="button" className="font-semibold underline" onClick={() => q.refetch()}>Try again</button></div>}
        {q.data && !rows.length && <Empty title={state === 'reported' ? 'Nothing reported' : 'No notes here'} text={state === 'reported' ? 'Creators have not reported any notes in this range.' : 'Notes appear when visitors add a message or share their number with a gift.'} />}
        {rows.map((n) => (
            <article key={n.id} className={`${card} p-4`}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-sm text-slate-600"><Link to={`/clients/${n.client?.id}`} className="font-semibold text-slate-900 hover:underline">{n.client?.name || 'Creator'}</Link> · {money(n.amount, n.currency)} · {new Date(n.sent_at || n.created_at).toLocaleString()}</p>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {n.reported_at && <Badge tone="amber">Reported</Badge>}
                            {n.message_state === 'hidden_by_creator' && <Badge>Hidden by creator</Badge>}
                            {n.message_state === 'removed_by_staff' && <Badge>Removed by staff</Badge>}
                            {n.contact_shared && <Badge tone="teal">Number shared</Badge>}
                            {n.is_sandbox && <Badge>Sandbox</Badge>}
                            {n.status !== 'sent' && <Badge>{human(n.status)}</Badge>}
                        </div>
                    </div>
                    <span className="text-xs text-slate-400" title={n.public_id}>{n.payment_reference}</span>
                </div>
                {n.message ? <blockquote className="mt-3 border-l-2 border-slate-200 pl-3 text-[15px] text-slate-900">“{n.message}”{n.sender_name && <span className="mt-1 block text-sm text-slate-500">— {n.sender_name}</span>}</blockquote>
                    : <p className="mt-3 text-sm italic text-slate-500">{n.message_state === 'removed_by_staff' ? 'Note removed by staff.' : 'No note — the sender only shared their number.'}</p>}
                {action?.id === n.id ? (
                    <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3">
                        <label className="min-w-64 flex-1 text-xs font-medium text-slate-500">{action.type === 'remove-note' ? 'Why remove this note?' : 'Why clear the shared number?'}
                            <input className={`${field} mt-1 w-full`} minLength={5} required value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Audit reason (at least 5 characters)" autoFocus />
                        </label>
                        <button type="submit" disabled={busy} className="min-h-11 rounded-md bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-60">{action.type === 'remove-note' ? 'Remove note' : 'Clear number'}</button>
                        <button type="button" className={field} onClick={() => setAction(null)}>Cancel</button>
                    </form>
                ) : (
                    <div className="mt-3 flex flex-wrap gap-2">
                        {n.message && n.message_state !== 'removed_by_staff' && <button type="button" className={`${field} font-semibold text-slate-700`} onClick={() => { setAction({ id: n.id, type: 'remove-note' }); setReason(''); setMessage(''); }}>Remove note</button>}
                        {n.contact_shared && <button type="button" className={`${field} font-semibold text-slate-700`} onClick={() => { setAction({ id: n.id, type: 'clear-contact' }); setReason(''); setMessage(''); }}>Clear shared number</button>}
                    </div>
                )}
            </article>
        ))}
        {q.data?.notes?.last_page > 1 && (
            <div className="flex items-center justify-between text-sm text-slate-600">
                <span>Page {q.data.notes.current_page} of {q.data.notes.last_page}</span>
                <span className="flex gap-2">
                    <button type="button" className={field} disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</button>
                    <button type="button" className={field} disabled={page >= q.data.notes.last_page} onClick={() => setPage((p) => p + 1)}>Next</button>
                </span>
            </div>
        )}
    </>;
}

function Setup() {
    const qc = useQueryClient();
    const q = useQuery({ queryKey: ['send-love-markets'], queryFn: async () => (await api.get('/crm/monetization/love/markets')).data });
    const [open, setOpen] = useState(null);
    const [sync, setSync] = useState({});
    async function push(id) {
        setSync((s) => ({ ...s, [id]: { busy: true } }));
        try {
            const { data } = await api.post(`/crm/settings/send-love/${id}/sync`);
            setSync((s) => ({ ...s, [id]: { text: data.sync.status === 'synced' ? 'WordPress acknowledged the latest revision.' : (data.sync.message || 'WordPress did not acknowledge the revision.'), ok: data.sync.status === 'synced' } }));
            await q.refetch();
        } catch (e) {
            setSync((s) => ({ ...s, [id]: { text: e.response?.data?.message || 'Could not reach WordPress.', ok: false } }));
        }
    }
    if (q.isPending) return <p role="status" className="text-sm text-slate-500">Loading market setup…</p>;
    if (q.isError) return <div role="alert" className={`${card} p-5 text-sm`}>Could not load market setup. <button type="button" className="font-semibold underline" onClick={() => q.refetch()}>Try again</button></div>;
    const { markets, can_manage: canManage, global_paused: paused } = q.data;
    return <>
        {paused && <div role="status" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">Send Love is paused everywhere by the global switch. Existing gifts still settle; no new gifts can start.</div>}
        {!markets.length && <Empty title="No markets configured" text="Send Love settings are created per market. Ask an admin to open a market's settings to start." />}
        {markets.map((m) => {
            const mode = paused || m.kill_switch ? ['Paused', 'rose'] : !m.enabled || m.rollout_mode === 'off' ? ['Off', 'slate'] : m.rollout_mode === 'live' ? ['Live', 'emerald'] : ['Sandbox', 'amber'];
            const ready = m.providers.filter((p) => p.ready).length;
            const state = sync[m.platform.id];
            return (
                <article key={m.platform.id} className={card}>
                    <div className="flex flex-wrap items-start justify-between gap-4 p-5">
                        <div>
                            <div className="flex items-center gap-2"><h3 className="text-lg font-semibold text-slate-900">{m.platform.name}</h3><Badge tone={mode[1]}>{mode[0]}</Badge></div>
                            <p className="mt-1 text-sm text-slate-500">{m.currency} · presets {m.presets.map((p) => number(p)).join(' · ')} (default {number(m.default_preset)}) · creator keeps {m.creator_share_bps / 100}%</p>
                        </div>
                        {canManage && <div className="flex flex-wrap gap-2">
                            <button type="button" className={`${field} font-semibold text-slate-700`} disabled={state?.busy} onClick={() => push(m.platform.id)}>{state?.busy ? 'Syncing…' : 'Sync to WordPress'}</button>
                            <button type="button" aria-expanded={open === m.platform.id} className="min-h-11 rounded-md bg-teal-700 px-4 text-sm font-semibold text-white" onClick={() => { if (open === m.platform.id) { setOpen(null); qc.invalidateQueries({ queryKey: ['send-love-markets'] }); } else setOpen(m.platform.id); }}>{open === m.platform.id ? 'Close settings' : 'Configure'}</button>
                        </div>}
                    </div>
                    <dl className="grid gap-px border-t border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-4">
                        <Fact label="WordPress">
                            {m.in_sync ? <span className="text-emerald-700">Revision {m.wp_revision} / {m.config_revision} · in sync</span> : <span className="text-amber-700">Revision {m.wp_revision} / {m.config_revision} · waiting for WordPress</span>}
                        </Fact>
                        <Fact label={`Payment providers · ${ready}/${m.providers.length} ready`}>
                            <span className="flex flex-wrap gap-1.5">{m.providers.map((p) => <span key={p.key} title={p.message || 'Ready for this rollout mode'}><Badge tone={p.ready ? 'emerald' : 'rose'}>{PROVIDERS[p.key] || p.key} · {p.ready ? 'Ready' : 'Not enabled'}</Badge></span>)}</span>
                        </Fact>
                        <Fact label="Test creators">{m.test_creators ? `${m.test_creators} in sandbox` : 'None'}</Fact>
                        <Fact label="Last 7 days">{number(m.gifts_7d)} gifts · {money(m.gross_7d, m.currency)}</Fact>
                    </dl>
                    {m.providers.some((p) => !p.ready) && <p className="border-t border-slate-200 px-5 py-3 text-sm text-slate-600">A provider that isn't ready can't take gifts. Enable it in Settings → Wallet System for this market, or remove it in Configure.</p>}
                    {state?.text && <p role="status" className={`border-t border-slate-200 px-5 py-3 text-sm ${state.ok ? 'text-emerald-700' : 'text-rose-700'}`}>{state.text}</p>}
                    {open === m.platform.id && <div className="border-t border-slate-200 p-5"><SendLoveSettings platform={m.platform.id} /></div>}
                </article>
            );
        })}
    </>;
}

function Fact({ label, children }) {
    return <div className="bg-white px-5 py-4"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt><dd className="mt-1.5 text-sm text-slate-900">{children}</dd></div>;
}

function Badge({ children, tone = 'slate' }) {
    const tones = { slate: 'border-slate-200 bg-slate-50 text-slate-700', amber: 'border-amber-200 bg-amber-50 text-amber-800', teal: 'border-teal-200 bg-teal-50 text-teal-800', emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700', rose: 'border-rose-200 bg-rose-50 text-rose-700' };
    return <span className={`inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold ${tones[tone]}`}>{children}</span>;
}

function Empty({ title, text, action }) {
    return <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center">
        <p className="font-semibold text-slate-900">{title}</p>
        <p className="mx-auto mt-1 max-w-md text-sm text-slate-500">{text}</p>
        {action && <div className="mt-4">{action}</div>}
    </div>;
}
