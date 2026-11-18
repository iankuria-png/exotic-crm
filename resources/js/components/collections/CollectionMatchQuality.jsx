import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import { Icon, eat, formatMinor } from './collectionUi';
import { GUARD_TEXT } from './CollectionMatchFeedback';

const RANGES = [7, 14, 30, 90];
const BAND_FLOOR = { '95+': 0.95, '90–94': 0.9, '81–89': 0.81, 80: 0.8, '65–79': 0.65, '50–64': 0.5 };
const RULE_LABELS = {
    current_purchase: 'Saved purchase for this code', reviewed_payer_link: 'Staff-reviewed payer link', several_profiles_due: 'Several-profile payer · due profile',
    staff_confirmed_payer: 'Payer confirmed for this profile before', payment_history_3plus: '3 or more earlier payments', advert_phone: 'Advert phone matches payer',
    payment_history: '1–2 earlier payments', typed_reference: 'Payer typed the profile name', other: 'Other evidence',
};
const pct = value => value === null || value === undefined ? '—' : `${Math.round(value * 100)}%`;
const segment = active => `whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium tabular-nums transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 ${active ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900'}`;
const card = 'rounded-xl border border-slate-200 bg-white p-4 sm:p-5';

export default function CollectionMatchQuality({ platformId, onOpenReceipt }) {
    const { user } = useAuth();
    const [days, setDays] = useState(30);
    const [fresh, setFresh] = useState(0);
    const query = useQuery({ queryKey: ['collection-match-accuracy', user?.id, platformId || null, days, fresh],
        queryFn: async () => (await api.get('/crm/collections/match-accuracy', { params: { platform_id: platformId || undefined, days, fresh: fresh ? 1 : undefined } })).data,
        staleTime: 60000, refetchOnWindowFocus: false, retry: false });
    const report = query.data?.report;
    return <section aria-labelledby="match-quality-title" className="space-y-4">
        <div className="flex flex-wrap items-end justify-between gap-4">
            <div><h2 id="match-quality-title" className="text-base font-semibold text-slate-900">Matching quality</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-500">How often the suggested profile was the one staff activated. Uses the suggestion saved when each payment arrived.</p></div>
            <div className="flex flex-wrap items-center gap-2">
                <div className="inline-flex gap-1 rounded-xl bg-slate-100 p-1" role="group" aria-label="Period">{RANGES.map(value => <button key={value} type="button" aria-pressed={days === value} className={segment(days === value)} onClick={() => setDays(value)}>{value} days</button>)}</div>
                <button type="button" className="inline-flex min-h-9 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 disabled:opacity-45" disabled={query.isFetching} onClick={() => setFresh(Date.now())}>{query.isFetching ? 'Refreshing…' : 'Refresh'}</button>
            </div>
        </div>
        {query.isPending ? <Skeleton /> : query.isError ? <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{query.error?.response?.status === 403 ? 'Only managers can view matching quality.' : 'Matching quality could not be loaded.'} <button type="button" className="underline" onClick={() => query.refetch()}>Retry</button></p>
            : !report ? <Empty title="No receiving accounts in this market" body="Choose a market with an office wallet or phone to see matching quality." />
            : !report.totals.with_outcome ? <Empty title="No activations to measure yet" body={`No incoming payment in the last ${days} days has an activated or recorded profile yet. Try a longer period.`} />
            : <Report report={report} days={days} onOpenReceipt={onOpenReceipt} updatedAt={query.dataUpdatedAt} />}
    </section>;
}

function Report({ report, days, onOpenReceipt, updatedAt }) {
    const { totals, rates, automatic } = report;
    return <>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi label="Top suggestion was right" value={pct(rates.agreement)} meter={rates.agreement} detail={`${totals.agreed} of ${totals.with_outcome} activated payments`} />
            <Kpi label="Right when it suggested" value={pct(rates.precision)} meter={rates.precision} detail={`${totals.suggested} payments had a suggestion`} />
            <Kpi label="Payments with a suggestion" value={pct(rates.coverage)} meter={rates.coverage} detail={`${totals.no_suggestion} had no suggestion`} />
            <Kpi label="Automatic activations kept" value={automatic.activated ? pct(automatic.accuracy) : '—'} meter={automatic.accuracy}
                tone={automatic.moved_by_staff ? 'warn' : 'live'} detail={automatic.activated ? `${automatic.moved_by_staff} of ${automatic.activated} moved to another profile by staff` : 'No automatic activations in this period'} />
        </div>
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
            <section aria-labelledby="match-trend" className={card}><h3 id="match-trend" className="text-sm font-semibold text-slate-900">Daily trend</h3>
                <p className="mt-1 text-xs text-slate-500">Bars: activated payments. Line: share where the top suggestion was right (EAT days).</p>
                <Trend daily={report.daily} /></section>
            <Reasons report={report} />
        </div>
        <div className="grid gap-4 lg:grid-cols-2">
            <Calibration bands={report.bands} />
            <Evidence rules={report.rules} />
        </div>
        {!!report.guards?.length && <section aria-labelledby="match-guards" className={card}><h3 id="match-guards" className="text-sm font-semibold text-slate-900">Held for a person instead of automatic activation</h3>
            <ul className="mt-3 grid gap-2 sm:grid-cols-3">{report.guards.map(g => <li key={g.guard} className="rounded-lg bg-amber-50/70 p-3 text-sm text-amber-950 ring-1 ring-inset ring-amber-200"><p className="text-xl font-semibold tabular-nums">{g.count}</p><p className="mt-1 text-xs leading-5">{GUARD_TEXT[g.guard] || g.guard}</p></li>)}</ul></section>}
        <Cases cases={report.cases} totals={totals} onOpenReceipt={onOpenReceipt} />
        <p className="text-xs text-slate-500">Last {days} days · {totals.receipts} incoming payments, {totals.with_outcome} with an activated or recorded profile · updated {new Date(updatedAt).toLocaleTimeString()}. Rule scores are not probabilities; this view measures them.</p>
    </>;
}

function Kpi({ label, value, detail, meter, tone = 'live' }) {
    return <div className={`${card} !p-4`}>
        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
        <p className="mt-2 text-3xl font-semibold tabular-nums tracking-tight text-slate-900">{value}</p>
        <div aria-hidden="true" className="mt-3 h-1.5 rounded-full bg-slate-100"><div className={`h-full rounded-full ${tone === 'warn' ? 'bg-amber-500' : 'bg-teal-600'}`} style={{ width: `${Math.round((meter ?? 0) * 100)}%` }} /></div>
        <p className="mt-2 text-xs text-slate-600">{detail}</p>
    </div>;
}

function Trend({ daily }) {
    const data = daily.map(d => ({ ...d, label: d.date.slice(5).replace('-', '/'), rate: d.with_outcome ? Math.round(d.agreed / d.with_outcome * 100) : null }));
    const best = data.filter(d => d.rate !== null);
    return <>
        <div className="mt-3 h-56" role="img" aria-label={`Daily agreement ranged from ${Math.min(...best.map(d => d.rate))}% to ${Math.max(...best.map(d => d.rate))}% across ${best.length} days with activations.`}>
            <ResponsiveContainer width="100%" height="100%"><ComposedChart data={data} margin={{ top: 8, right: 4, bottom: 0, left: -18 }}>
                <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="label" tick={{ fontSize: 11, fill: '#64748b' }} tickLine={false} axisLine={false} interval="preserveStartEnd" minTickGap={16} />
                <YAxis yAxisId="count" tick={{ fontSize: 11, fill: '#64748b' }} tickLine={false} axisLine={false} allowDecimals={false} />
                <YAxis yAxisId="rate" orientation="right" domain={[0, 100]} tick={{ fontSize: 11, fill: '#64748b' }} tickLine={false} axisLine={false} unit="%" width={40} />
                <Tooltip cursor={{ fill: '#f1f5f9' }} contentStyle={{ borderRadius: 8, borderColor: '#e2e8f0', fontSize: 12 }}
                    formatter={(value, name) => name === 'rate' ? [`${value}%`, 'Top suggestion right'] : [value, 'Activated payments']} labelFormatter={label => `Day ${label}`} />
                <Bar yAxisId="count" dataKey="with_outcome" fill="#cbd5e1" radius={[3, 3, 0, 0]} maxBarSize={22} />
                <Line yAxisId="rate" dataKey="rate" stroke="#0f766e" strokeWidth={2.5} dot={{ r: 2.5 }} connectNulls type="monotone" />
            </ComposedChart></ResponsiveContainer>
        </div>
        <details className="mt-2 text-xs text-slate-600"><summary className="cursor-pointer py-1 font-medium">Show as table</summary>
            <table className="mt-2 w-full text-left tabular-nums"><thead className="text-slate-500"><tr><th className="py-1 font-medium">Day</th><th className="font-medium">Activated</th><th className="font-medium">Suggested</th><th className="font-medium">Top right</th></tr></thead>
                <tbody>{data.filter(d => d.with_outcome).map(d => <tr key={d.date} className="border-t border-slate-100"><td className="py-1">{d.date}</td><td>{d.with_outcome}</td><td>{d.suggested}</td><td>{d.agreed} ({d.rate}%)</td></tr>)}</tbody></table></details>
    </>;
}

function Reasons({ report }) {
    const max = Math.max(1, ...report.reasons.map(r => r.count));
    const { disagreements, explained } = report.feedback;
    return <section aria-labelledby="match-reasons" className={card}>
        <h3 id="match-reasons" className="text-sm font-semibold text-slate-900">Why staff chose a different profile</h3>
        <p className="mt-1 text-xs text-slate-500">{disagreements ? `${explained} of ${disagreements} differences explained. Open one below to add its reason.` : 'Staff agreed with every suggestion in this period.'}</p>
        {!!disagreements && <div aria-hidden="true" className="mt-2 h-1.5 rounded-full bg-slate-100"><div className="h-full rounded-full bg-teal-600" style={{ width: `${Math.round(explained / disagreements * 100)}%` }} /></div>}
        <ul className="mt-4 space-y-3">{report.reasons.map(r => <li key={r.reason}>
            <div className="flex justify-between gap-3 text-sm"><span className="text-slate-700">{r.label}</span><span className="font-semibold tabular-nums text-slate-900">{r.count}</span></div>
            <div aria-hidden="true" className="mt-1 h-2 rounded bg-slate-100"><div className="h-full rounded bg-amber-500" style={{ width: `${r.count / max * 100}%` }} /></div>
        </li>)}</ul>
    </section>;
}

function Calibration({ bands }) {
    const rows = bands.filter(b => b.n);
    return <section aria-labelledby="match-calibration" className={card}>
        <h3 id="match-calibration" className="text-sm font-semibold text-slate-900">Score vs. how often it was right</h3>
        <p className="mt-1 text-xs text-slate-500">A 95 score should be right at least 95% of the time. Overconfident bands are flagged.</p>
        {!rows.length ? <p className="mt-4 text-sm text-slate-600">No scored suggestions in this period.</p> : <ul className="mt-4 space-y-3">{rows.map(b => {
            const floor = BAND_FLOOR[b.band];
            const over = floor !== undefined && b.n >= 5 && b.precision < floor - 0.05;
            return <li key={b.band}>
                <div className="flex flex-wrap items-center justify-between gap-2 text-sm"><span className="font-medium tabular-nums text-slate-800">Score {b.band}</span>
                    <span className="flex items-center gap-2 tabular-nums text-slate-700">{over && <span className="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-900 ring-1 ring-inset ring-rose-600/25">Overconfident</span>}{b.correct} of {b.n} · <strong className="text-slate-900">{pct(b.precision)}</strong></span></div>
                <div aria-hidden="true" className="relative mt-1 h-2 rounded bg-slate-100"><div className={`h-full rounded ${over ? 'bg-rose-500' : 'bg-teal-600'}`} style={{ width: `${Math.round(b.precision * 100)}%` }} />
                    {floor !== undefined && <span className="absolute -top-0.5 h-3 w-0.5 rounded bg-slate-500" style={{ left: `${floor * 100}%` }} />}</div>
            </li>; })}</ul>}
    </section>;
}

function Evidence({ rules }) {
    return <section aria-labelledby="match-evidence" className={card}>
        <h3 id="match-evidence" className="text-sm font-semibold text-slate-900">Accuracy by evidence</h3>
        <p className="mt-1 text-xs text-slate-500">Which rule produced the top suggestion, and how often staff kept it.</p>
        {!rules.length ? <p className="mt-4 text-sm text-slate-600">No suggestions in this period.</p> : <table className="mt-3 w-full text-left text-sm"><thead className="text-xs text-slate-500"><tr><th scope="col" className="py-2 font-medium">Evidence</th><th scope="col" className="py-2 text-right font-medium">Suggested</th><th scope="col" className="py-2 text-right font-medium">Right</th></tr></thead>
            <tbody className="divide-y divide-slate-100">{rules.map(r => <tr key={r.rule}><td className="py-2.5 pr-2 text-slate-700">{r.label}</td><td className="py-2.5 text-right tabular-nums text-slate-700">{r.n}</td>
                <td className="py-2.5 text-right"><span className={`inline-block min-w-12 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ring-1 ring-inset ${r.precision >= 0.9 ? 'bg-teal-50 text-teal-900 ring-teal-600/25' : r.precision >= 0.75 ? 'bg-amber-50 text-amber-950 ring-amber-600/30' : 'bg-rose-50 text-rose-900 ring-rose-600/25'}`}>{pct(r.precision)}</span></td></tr>)}</tbody></table>}
    </section>;
}

function Cases({ cases, totals, onOpenReceipt }) {
    const [tab, setTab] = useState('disagreed');
    const rows = cases[tab] || [];
    const counts = { disagreed: totals.disagreed, no_suggestion: totals.no_suggestion };
    return <section aria-labelledby="match-cases" className={card}>
        <div className="flex flex-wrap items-center justify-between gap-3"><div><h3 id="match-cases" className="text-sm font-semibold text-slate-900">Payments to learn from</h3><p className="mt-1 text-xs text-slate-500">Newest first. Open a payment to see its evidence and add the reason.</p></div>
            <div className="inline-flex gap-1 rounded-xl bg-slate-100 p-1" role="tablist" aria-label="Payments to learn from">{[['disagreed', 'Different profile'], ['no_suggestion', 'No suggestion']].map(([key, label]) => <button key={key} type="button" role="tab" aria-selected={tab === key} className={segment(tab === key)} onClick={() => setTab(key)}>{label} <span className="ml-1 text-xs text-slate-500">{counts[key]}</span></button>)}</div></div>
        {!rows.length ? <p className="mt-4 rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-600">{tab === 'disagreed' ? 'Staff kept every suggestion in this period.' : 'Every activated payment had a suggestion.'}</p>
            : <ul className="mt-3 divide-y divide-slate-100">{rows.map(c => <li key={c.receipt_id}>
                <button type="button" onClick={() => onOpenReceipt(c.receipt_id)} className="grid w-full gap-2 rounded-lg px-2 py-3 text-left transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 sm:grid-cols-[9rem_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-center sm:gap-4">
                    <span><span className="block text-sm font-semibold tabular-nums text-slate-900">{formatMinor(c.amount_minor, c.currency)}</span><span className="block text-xs tabular-nums text-slate-500">{eat(c.at)}</span></span>
                    <span className="min-w-0 text-sm">{c.suggested ? <><span className="text-xs text-slate-500">Suggested </span><span className="font-medium text-slate-800">{c.suggested.name}</span><span className="text-xs tabular-nums text-slate-500"> · {c.suggested.score}</span><span className="block truncate text-xs text-slate-500">{RULE_LABELS[c.suggested.rule] || ''}</span></> : <span className="text-slate-500">No suggestion</span>}</span>
                    <span className="min-w-0 text-sm"><span className="text-xs text-slate-500">{c.automatic_moved ? 'Moved by staff to ' : 'Activated '}</span><span className="font-medium text-teal-800">{c.chosen.map(p => p.name).join(', ')}</span></span>
                    <span className="flex items-center gap-2 sm:justify-end">{c.reason_label ? <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{c.reason_label}</span> : <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-950 ring-1 ring-inset ring-amber-600/30">Add reason</span>}<Icon name="chevron" className="h-4 w-4 text-slate-400" /></span>
                </button></li>)}</ul>}
        {rows.length >= 40 && <p className="mt-2 text-xs text-slate-500">Showing the newest 40. Shorten the period to see others.</p>}
    </section>;
}

function Skeleton() {
    return <div role="status" aria-label="Loading matching quality" className="space-y-4"><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[0, 1, 2, 3].map(i => <div key={i} className="h-32 rounded-xl bg-slate-100 motion-safe:animate-pulse" />)}</div><div className="h-64 rounded-xl bg-slate-100 motion-safe:animate-pulse" /><span className="sr-only">Loading matching quality…</span></div>;
}

function Empty({ title, body }) {
    return <div className="flex flex-col items-center rounded-xl border border-dashed border-slate-300 px-6 py-12 text-center"><span className="flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 text-slate-500"><Icon name="inbox" className="h-5 w-5" /></span><p className="mt-3 font-medium text-slate-900">{title}</p><p className="mt-1 max-w-sm text-sm text-slate-500">{body}</p></div>;
}
