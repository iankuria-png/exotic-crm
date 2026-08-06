import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';

const FLOW_LABELS = {
    onboarding: 'Onboarding',
    recovery: 'Payment recovery',
    reactivation: 'Win-back',
    renewal: 'Renewal',
};

const WINDOW_OPTIONS = [7, 14, 30];
const PERIOD_PRESETS = [7, 14, 30];

function usd(n) {
    if (n === null || n === undefined) return '—';
    return `USD ${Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

function pct(n) {
    return `${Number(n ?? 0).toFixed(1)}%`;
}

function isoDaysAgo(days) {
    const d = new Date();
    d.setDate(d.getDate() - days);
    return d.toISOString().slice(0, 10);
}

function Tile({ label, value, sub, tone = 'slate' }) {
    const tones = {
        slate: 'text-slate-900',
        teal: 'text-teal-700',
        emerald: 'text-emerald-700',
        amber: 'text-amber-700',
        rose: 'text-rose-700',
    };
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${tones[tone]}`}>{value}</p>
            {sub ? <p className="mt-0.5 text-xs text-slate-400">{sub}</p> : null}
        </div>
    );
}

function FunnelBar({ label, count, total, tone }) {
    const width = total > 0 ? Math.max(2, Math.round((count / total) * 100)) : 0;
    const tones = {
        sent: 'bg-slate-300',
        opened: 'bg-teal-400',
        converted: 'bg-emerald-500',
    };
    return (
        <div>
            <div className="mb-1 flex items-center justify-between text-xs">
                <span className="font-medium text-slate-700">{label}</span>
                <span className="text-slate-500">{Number(count).toLocaleString()}{total > 0 && label !== 'Sent' ? ` · ${pct((count / total) * 100)}` : ''}</span>
            </div>
            <div className="h-3 w-full overflow-hidden rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${tones[tone]}`} style={{ width: `${width}%` }} />
            </div>
        </div>
    );
}

export default function LifecycleAnalytics() {
    const queryClient = useQueryClient();
    const [platformId, setPlatformId] = useState('');
    const [period, setPeriod] = useState(30); // 7 | 14 | 30 | 'custom'
    const [customFrom, setCustomFrom] = useState(() => isoDaysAgo(30));
    const [customTo, setCustomTo] = useState(() => new Date().toISOString().slice(0, 10));
    const [windowDays, setWindowDays] = useState(null); // null → use server default

    const { from, to } = useMemo(() => (
        period === 'custom'
            ? { from: customFrom, to: customTo }
            : { from: isoDaysAgo(period), to: new Date().toISOString().slice(0, 10) }
    ), [period, customFrom, customTo]);

    const marketsQuery = useQuery({
        queryKey: ['my-markets'],
        queryFn: () => api.get('/crm/dashboard/my-markets').then((r) => r.data),
        staleTime: 300_000,
    });

    const params = useMemo(() => ({
        ...(platformId ? { platform_id: Number(platformId) } : {}),
        ...(from ? { from } : {}),
        ...(to ? { to } : {}),
        ...(windowDays ? { window_days: windowDays } : {}),
    }), [platformId, from, to, windowDays]);

    const analyticsQuery = useQuery({
        queryKey: ['lifecycle-analytics', params],
        queryFn: () => api.get('/crm/lifecycle-sms/analytics', { params }).then((r) => r.data),
        keepPreviousData: true,
    });

    const saveWindowMutation = useMutation({
        mutationFn: (days) => api.patch('/crm/lifecycle-sms/analytics/settings', { attribution_window_days: days }).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lifecycle-analytics'] }),
    });

    // Per-message drill-down
    const [msgFlow, setMsgFlow] = useState('');
    const [msgOutcome, setMsgOutcome] = useState('');
    const [msgSearch, setMsgSearch] = useState('');
    const [msgPage, setMsgPage] = useState(1);

    const msgParams = useMemo(() => ({
        ...params,
        ...(msgFlow ? { flow: msgFlow } : {}),
        ...(msgOutcome ? { outcome: msgOutcome } : {}),
        ...(msgSearch.trim() ? { search: msgSearch.trim() } : {}),
        page: msgPage,
        per_page: 25,
    }), [params, msgFlow, msgOutcome, msgSearch, msgPage]);

    const messagesQuery = useQuery({
        queryKey: ['lifecycle-messages', msgParams],
        queryFn: () => api.get('/crm/lifecycle-sms/analytics/messages', { params: msgParams }).then((r) => r.data),
        keepPreviousData: true,
    });

    const data = analyticsQuery.data;
    const effectiveWindow = windowDays ?? data?.window_days ?? 7;
    const markets = marketsQuery.data || [];

    const exportCsv = () => {
        if (!data) return;
        const rows = [
            ['Metric', 'Value'],
            ['Range', `${from} to ${to}`],
            ['Attribution window (days)', effectiveWindow],
            ['Market', platformId ? (markets.find((m) => String(m.id) === String(platformId))?.name || platformId) : 'All markets'],
            ['Sent', data.funnel.sent],
            ['Opened', data.funnel.opened],
            ['Open rate %', data.funnel.open_rate],
            ['Converted', data.funnel.converted],
            ['Direct conversions', data.funnel.direct],
            ['Assisted conversions', data.funnel.assisted],
            ['Conversion rate %', data.funnel.conversion_rate],
            ['Attributed revenue USD', data.attributed_revenue_usd],
            ['Median hours to convert', data.time_to_convert.median_hours ?? ''],
            ['Lifecycle payments successful', data.payments.completed.count],
            ['Lifecycle payments successful value USD', data.payments.completed.value_usd],
            ['Lifecycle payments pending', data.payments.pending.count],
            ['Lifecycle payments pending value USD', data.payments.pending.value_usd],
            ['Lifecycle payments failed', data.payments.failed.count],
        ];
        const csv = rows.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `lifecycle-analytics-${from}_${to}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-5 p-1">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Lifecycle Analytics</h1>
                    <p className="text-sm text-slate-500">Sent → opened → converted, with direct and assisted attribution and payment outcomes.</p>
                </div>
                <button type="button" onClick={exportCsv} disabled={!data} className="crm-btn-secondary text-xs disabled:opacity-50">Export CSV</button>
            </header>

            {/* Filters */}
            <div className="flex flex-wrap items-end gap-x-5 gap-y-3 rounded-xl border border-slate-200 bg-white p-3">
                {/* Period */}
                <div>
                    <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Period</p>
                    <div className="inline-flex items-center rounded-lg bg-slate-100 p-0.5">
                        {PERIOD_PRESETS.map((p) => (
                            <button
                                key={p}
                                type="button"
                                onClick={() => setPeriod(p)}
                                className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${period === p ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                            >
                                {p}d
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => setPeriod('custom')}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${period === 'custom' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                        >
                            Custom
                        </button>
                    </div>
                </div>

                {period === 'custom' ? (
                    <div className="flex items-end gap-2">
                        <div>
                            <label htmlFor="la-from" className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">From</label>
                            <input id="la-from" type="date" value={customFrom} max={customTo} onChange={(e) => setCustomFrom(e.target.value)} className="crm-input text-sm" />
                        </div>
                        <div>
                            <label htmlFor="la-to" className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">To</label>
                            <input id="la-to" type="date" value={customTo} min={customFrom} onChange={(e) => setCustomTo(e.target.value)} className="crm-input text-sm" />
                        </div>
                    </div>
                ) : null}

                <div className="h-9 w-px self-end bg-slate-200" aria-hidden="true" />

                <div>
                    <label htmlFor="la-market" className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Market</label>
                    <select id="la-market" value={platformId} onChange={(e) => setPlatformId(e.target.value)} className="crm-select text-sm">
                        <option value="">All markets (USD)</option>
                        {markets.map((m) => (
                            <option key={m.id} value={m.id}>{m.name}</option>
                        ))}
                    </select>
                </div>
                <div>
                    <label htmlFor="la-window" className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500" title="How long after a send a conversion is still credited to it">Attribution window</label>
                    <div className="flex items-center gap-2">
                        <select id="la-window" value={effectiveWindow} onChange={(e) => setWindowDays(Number(e.target.value))} className="crm-select text-sm">
                            {WINDOW_OPTIONS.map((w) => <option key={w} value={w}>{w} days</option>)}
                            {!WINDOW_OPTIONS.includes(effectiveWindow) ? <option value={effectiveWindow}>{effectiveWindow} days</option> : null}
                        </select>
                        <button
                            type="button"
                            onClick={() => saveWindowMutation.mutate(effectiveWindow)}
                            disabled={saveWindowMutation.isPending}
                            className="text-[11px] text-teal-700 hover:underline"
                            title="Save this window as the default"
                        >
                            {saveWindowMutation.isPending ? 'Saving…' : 'Set default'}
                        </button>
                    </div>
                </div>
                {analyticsQuery.isFetching ? <span className="pb-2 text-xs text-slate-400">Updating…</span> : null}
            </div>

            {analyticsQuery.isError ? (
                <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                    Could not load analytics. {analyticsQuery.error?.response?.data?.message || ''}
                    <button type="button" className="ml-2 underline" onClick={() => analyticsQuery.refetch()}>Retry</button>
                </div>
            ) : analyticsQuery.isLoading || !data ? (
                <div className="py-20 text-center text-sm text-slate-400">Loading lifecycle analytics…</div>
            ) : data.funnel.sent === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-200 py-20 text-center text-sm text-slate-400">
                    No lifecycle SMS were sent in this range{platformId ? ' for this market' : ''}. Adjust the filters or enable flows in Settings → SMS Routing → Lifecycle.
                </div>
            ) : (
                <>
                    {/* KPI tiles */}
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Tile label="Messages sent" value={data.funnel.sent.toLocaleString()} sub={`${data.funnel.opened.toLocaleString()} opened · ${pct(data.funnel.open_rate)}`} />
                        <Tile label="Conversion rate" value={pct(data.funnel.conversion_rate)} sub={`${data.funnel.direct} direct · ${data.funnel.assisted} assisted`} tone="emerald" />
                        <Tile label="Attributed revenue" value={usd(data.attributed_revenue_usd)} sub={`${data.funnel.converted} conversions`} tone="teal" />
                        <Tile label="Median time to convert" value={data.time_to_convert.median_hours != null ? `${data.time_to_convert.median_hours} h` : '—'} sub={data.time_to_convert.count ? `${data.time_to_convert.count} measured` : 'no conversions yet'} />
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                        {/* Funnel */}
                        <section className="rounded-xl border border-slate-200 bg-white p-4 lg:col-span-2">
                            <h3 className="mb-3 text-sm font-semibold text-slate-900">Conversion funnel</h3>
                            <div className="space-y-3">
                                <FunnelBar label="Sent" count={data.funnel.sent} total={data.funnel.sent} tone="sent" />
                                <FunnelBar label="Opened link" count={data.funnel.opened} total={data.funnel.sent} tone="opened" />
                                <FunnelBar label="Converted" count={data.funnel.converted} total={data.funnel.sent} tone="converted" />
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700 ring-1 ring-inset ring-emerald-600/15"><span className="font-semibold">{data.funnel.direct}</span> direct · paid the link</span>
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-teal-50 px-2.5 py-1 text-xs text-teal-700 ring-1 ring-inset ring-teal-600/15"><span className="font-semibold">{data.funnel.assisted}</span> assisted · in {effectiveWindow}d</span>
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600"><span className="font-semibold">{data.new_vs_existing.new}</span> new · <span className="font-semibold">{data.new_vs_existing.existing}</span> existing</span>
                            </div>
                        </section>

                        {/* Payment rollup */}
                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                            <h3 className="mb-3 text-sm font-semibold text-slate-900">Lifecycle payments</h3>
                            <div className="space-y-2">
                                {[
                                    ['completed', 'Successful', 'text-emerald-700'],
                                    ['pending', 'Pending', 'text-amber-700'],
                                    ['failed', 'Failed', 'text-rose-700'],
                                ].map(([key, label, toneClass]) => (
                                    <div key={key} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                                        <span className={`text-xs font-semibold ${toneClass}`}>{label}</span>
                                        <span className="text-sm font-semibold text-slate-800">
                                            {data.payments[key].count.toLocaleString()}
                                            <span className="ml-2 text-xs font-normal text-slate-400">{usd(data.payments[key].value_usd)}</span>
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <p className="mt-3 text-[11px] text-slate-400">Pending value is the open pipeline from links awaiting payment.</p>
                        </section>
                    </div>

                    {/* By flow */}
                    <section className="rounded-xl border border-slate-200 bg-white">
                        <header className="border-b border-slate-100 px-4 py-2.5"><h3 className="text-sm font-semibold text-slate-900">By flow</h3></header>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400">
                                        <th className="px-4 py-2 font-semibold">Flow</th>
                                        <th className="px-4 py-2 font-semibold">Sent</th>
                                        <th className="px-4 py-2 font-semibold">Opened</th>
                                        <th className="px-4 py-2 font-semibold">Direct</th>
                                        <th className="px-4 py-2 font-semibold">Assisted</th>
                                        <th className="px-4 py-2 font-semibold">Conv. rate</th>
                                        <th className="px-4 py-2 font-semibold">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {data.by_flow.map((f) => (
                                        <tr key={f.flow} className="hover:bg-slate-50">
                                            <td className="px-4 py-2 font-medium text-slate-800">{FLOW_LABELS[f.flow] || f.flow}</td>
                                            <td className="px-4 py-2 text-slate-600">{f.sent.toLocaleString()}</td>
                                            <td className="px-4 py-2 text-slate-600">{f.opened.toLocaleString()}</td>
                                            <td className="px-4 py-2 text-emerald-700">{f.direct}</td>
                                            <td className="px-4 py-2 text-teal-700">{f.assisted}</td>
                                            <td className="px-4 py-2 text-slate-600">{pct(f.conversion_rate)}</td>
                                            <td className="px-4 py-2 text-slate-800">{usd(f.revenue_usd)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {/* By market */}
                    {!platformId && data.by_market.length > 1 ? (
                        <section className="rounded-xl border border-slate-200 bg-white">
                            <header className="border-b border-slate-100 px-4 py-2.5"><h3 className="text-sm font-semibold text-slate-900">By market</h3></header>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400">
                                            <th className="px-4 py-2 font-semibold">Market</th>
                                            <th className="px-4 py-2 font-semibold">Sent</th>
                                            <th className="px-4 py-2 font-semibold">Converted</th>
                                            <th className="px-4 py-2 font-semibold">Conv. rate</th>
                                            <th className="px-4 py-2 font-semibold">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {data.by_market.map((m) => (
                                            <tr key={m.platform_id} className="hover:bg-slate-50">
                                                <td className="px-4 py-2 font-medium text-slate-800">{m.platform_name}</td>
                                                <td className="px-4 py-2 text-slate-600">{m.sent.toLocaleString()}</td>
                                                <td className="px-4 py-2 text-slate-600">{m.converted.toLocaleString()}</td>
                                                <td className="px-4 py-2 text-slate-600">{pct(m.conversion_rate)}</td>
                                                <td className="px-4 py-2 text-slate-800">{usd(m.revenue_usd)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    ) : null}

                    {/* Per-message drill-down */}
                    <section className="rounded-xl border border-slate-200 bg-white">
                        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5">
                            <h3 className="text-sm font-semibold text-slate-900">Messages</h3>
                            <div className="flex flex-wrap items-center gap-2">
                                <input
                                    type="search"
                                    value={msgSearch}
                                    onChange={(e) => { setMsgSearch(e.target.value); setMsgPage(1); }}
                                    placeholder="Search client or text"
                                    className="crm-input text-xs sm:w-48"
                                    aria-label="Search messages"
                                />
                                <select value={msgFlow} onChange={(e) => { setMsgFlow(e.target.value); setMsgPage(1); }} className="crm-select text-xs" aria-label="Flow filter">
                                    <option value="">All flows</option>
                                    {Object.entries(FLOW_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                </select>
                                <select value={msgOutcome} onChange={(e) => { setMsgOutcome(e.target.value); setMsgPage(1); }} className="crm-select text-xs" aria-label="Outcome filter">
                                    <option value="">All outcomes</option>
                                    <option value="opened">Opened</option>
                                    <option value="converted">Converted</option>
                                    <option value="not_converted">Not converted</option>
                                </select>
                            </div>
                        </header>

                        {messagesQuery.isLoading ? (
                            <div className="py-10 text-center text-xs text-slate-400">Loading messages…</div>
                        ) : (messagesQuery.data?.data || []).length === 0 ? (
                            <div className="py-10 text-center text-xs text-slate-400">No messages match these filters.</div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="text-left text-[11px] uppercase tracking-wide text-slate-400">
                                                <th className="px-4 py-2 font-semibold">Sent</th>
                                                <th className="px-4 py-2 font-semibold">Client</th>
                                                <th className="px-4 py-2 font-semibold">Flow</th>
                                                <th className="px-4 py-2 font-semibold">Message</th>
                                                <th className="px-4 py-2 font-semibold">Opened</th>
                                                <th className="px-4 py-2 font-semibold">Converted</th>
                                                <th className="px-4 py-2 font-semibold">Time</th>
                                                <th className="px-4 py-2 font-semibold">Value</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {(messagesQuery.data?.data || []).map((m) => (
                                                <tr key={m.id} className="align-top hover:bg-slate-50">
                                                    <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-500">{new Date(m.sent_at).toLocaleString()}</td>
                                                    <td className="px-4 py-2">
                                                        <a href={`/clients/${m.client_id}`} className="font-medium text-teal-700 hover:underline">{m.client_name}</a>
                                                        {m.is_new != null ? <span className="ml-1 text-[10px] text-slate-400">{m.is_new ? 'new' : 'existing'}</span> : null}
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-600">{FLOW_LABELS[m.flow] || m.flow}</td>
                                                    <td className="px-4 py-2 text-xs text-slate-600"><span className="line-clamp-2 max-w-xs" title={m.body}>{m.body || '—'}</span></td>
                                                    <td className="px-4 py-2">{m.opened ? <span className="inline-flex rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-semibold text-teal-700">Opened</span> : <span className="text-[10px] text-slate-300">—</span>}</td>
                                                    <td className="px-4 py-2">
                                                        {m.converted
                                                            ? <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${m.conversion_type === 'direct' ? 'bg-emerald-50 text-emerald-700' : 'bg-teal-50 text-teal-700'}`}>{m.conversion_type}</span>
                                                            : <span className="text-[10px] text-slate-300">—</span>}
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-500">{m.hours_to_convert != null ? `${Number(m.hours_to_convert).toFixed(1)} h` : '—'}</td>
                                                    <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-700">{m.value_usd != null ? usd(m.value_usd) : '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <footer className="flex items-center justify-between border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                                    <span>{(messagesQuery.data?.total || 0).toLocaleString()} messages</span>
                                    <div className="flex items-center gap-2">
                                        <button type="button" disabled={msgPage <= 1} onClick={() => setMsgPage((p) => Math.max(1, p - 1))} className="rounded border border-slate-300 px-2 py-1 disabled:opacity-40">Prev</button>
                                        <span>Page {messagesQuery.data?.page || 1} / {messagesQuery.data?.total_pages || 1}</span>
                                        <button type="button" disabled={(messagesQuery.data?.page || 1) >= (messagesQuery.data?.total_pages || 1)} onClick={() => setMsgPage((p) => p + 1)} className="rounded border border-slate-300 px-2 py-1 disabled:opacity-40">Next</button>
                                    </div>
                                </footer>
                            </>
                        )}
                    </section>

                    {data.capped ? (
                        <p className="text-[11px] text-amber-600">Note: this range hit the {(20000).toLocaleString()}-send scan cap; narrow the date range for exact totals.</p>
                    ) : null}
                    <p className="text-[11px] text-slate-400">
                        Attribution is last-touch within {effectiveWindow} days. Open rate reflects payment-link opens (billing proxy); SMS delivery receipts aren’t tracked. Values converted to USD.
                    </p>
                </>
            )}
        </div>
    );
}
