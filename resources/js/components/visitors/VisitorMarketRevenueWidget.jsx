import React, { useMemo, useState } from 'react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import SectionFrame from '../SectionFrame';
import { getCountryFlag } from '../../utils/flags';
import { formatCurrency } from '../../utils/currency';
import { moneyRowsLabel, percentLabel } from './visitorFormat';

const COLORS = ['#0f766e', '#2563eb', '#059669', '#d97706', '#7c3aed', '#be123c', '#0f172a', '#0891b2'];
const OTHER_COLOR = '#94a3b8';

function marketLabel(market) {
    const flag = getCountryFlag(market.country);
    return `${flag ? `${flag} ` : ''}${market.name || 'Unassigned market'}`;
}

function breakdownRows(breakdown = {}) {
    return Object.entries(breakdown).map(([currency, amount]) => ({ currency, amount: Number(amount || 0) }));
}

function moneyLabel(market, currency, isFlat) {
    if (!isFlat || market.normalized_total === null || market.normalized_total === undefined) {
        return moneyRowsLabel(breakdownRows(market.source_breakdown), '--');
    }
    return formatCurrency(market.normalized_total, market.normalized_currency || currency);
}

function EmptyState({ message, hint }) {
    return (
        <div className="flex h-72 flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center">
            <p className="text-sm font-medium text-slate-600">{message}</p>
            {hint ? <p className="text-xs text-slate-500">{hint}</p> : null}
        </div>
    );
}

function PieTooltip({ active, payload, currency, isFlat }) {
    if (!active || !payload?.length) return null;
    const item = payload[0]?.payload;
    if (!item) return null;

    return (
        <div className="min-w-[190px] rounded-lg border border-slate-800 bg-slate-950 p-3 text-white shadow-2xl">
            <p className="text-sm font-semibold">{item.platform_id ? marketLabel(item) : 'Other markets'}</p>
            <p className="mt-1 text-xs text-slate-300">
                {item.fx_unresolved
                    ? 'No FX rate — excluded from the total'
                    : `${Number(item.share_percent || 0).toFixed(1)}% of unlock revenue`}
            </p>
            <p className="mt-2 text-base font-semibold text-teal-200">{moneyLabel(item, currency, isFlat)}</p>
            <p className="text-xs text-slate-300">{Number(item.payments_count || 0).toLocaleString()} paid unlocks</p>
        </div>
    );
}

export default function VisitorMarketRevenueWidget({
    data,
    isLoading,
    errorMessage,
    reportingCurrency,
    selectedPlatformId = 'all',
    onSelectMarket,
    onClearMarket,
    onRetry,
}) {
    const [view, setView] = useState('share');
    const currency = data?.window?.target_currency || reportingCurrency?.targetCurrency || 'USD';
    const isFlat = Boolean(reportingCurrency?.isFlat);
    const markets = data?.markets || [];
    const scoped = selectedPlatformId && selectedPlatformId !== 'all';

    const colorByPlatform = useMemo(() => {
        // Markets arrive revenue-sorted. Pinning the colour to that order keeps a market the
        // same colour in the donut and in the list, whichever way the list is re-sorted.
        const map = new Map();
        markets.forEach((market, index) => map.set(String(market.platform_id), COLORS[index % COLORS.length]));
        return map;
    }, [markets]);

    const chartData = useMemo(() => {
        const top = [];
        const other = [];
        markets.forEach((market) => {
            // An unconvertible market has no share to rank on — surface it rather than burying
            // it in "Other", because it is exactly the market whose FX needs fixing.
            if (market.fx_unresolved || Number(market.share_percent || 0) > 2) top.push(market);
            else other.push(market);
        });

        const rows = top.map((market) => ({ ...market, color: colorByPlatform.get(String(market.platform_id)) || OTHER_COLOR }));

        if (other.length > 0) {
            rows.push({
                platform_id: null,
                name: 'Other markets',
                country: '',
                color: OTHER_COLOR,
                share_percent: other.reduce((sum, item) => sum + Number(item.share_percent || 0), 0),
                value: other.reduce((sum, item) => sum + Number(item.value || 0), 0),
                payments_count: other.reduce((sum, item) => sum + Number(item.payments_count || 0), 0),
                normalized_total: other.reduce((sum, item) => sum + Number(item.normalized_total || 0), 0),
                normalized_currency: currency,
                source_breakdown: other.reduce((acc, item) => {
                    Object.entries(item.source_breakdown || {}).forEach(([code, amount]) => {
                        acc[code] = (acc[code] || 0) + Number(amount || 0);
                    });
                    return acc;
                }, {}),
                fx_unresolved: false,
                other_markets: other,
            });
        }

        return rows;
    }, [colorByPlatform, currency, markets]);

    const totals = data?.totals || {};
    const total = totals.normalized_total === null || totals.normalized_total === undefined
        ? markets.reduce((sum, market) => sum + Number(market.value || 0), 0)
        : Number(totals.normalized_total);
    const ranked = useMemo(() => {
        const rows = [...markets];
        if (view === 'conversion') {
            return rows.sort((a, b) => Number(b.conversion_percent || 0) - Number(a.conversion_percent || 0));
        }
        if (view === 'volume') {
            return rows.sort((a, b) => Number(b.payments_count || 0) - Number(a.payments_count || 0));
        }
        return rows;
    }, [markets, view]);

    const bestConversion = Math.max(1, ...ranked.map((market) => Number(market.conversion_percent || 0)));

    return (
        <SectionFrame
            title="Visitor Revenue by Market"
            subtitle={scoped
                ? 'Scoped to one market. Clear the scope to compare every market side by side.'
                : 'Unlock revenue share and per-market checkout performance. Not attributed to subscription sales.'}
            className="overflow-hidden"
            action={(
                <div className="flex flex-wrap justify-end gap-2">
                    {scoped ? (
                        <button
                            type="button"
                            onClick={onClearMarket}
                            className="rounded-md border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800 transition hover:bg-teal-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        >
                            Clear market
                        </button>
                    ) : null}
                    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Market ranking">
                        {[
                            ['share', 'Revenue'],
                            ['volume', 'Unlocks'],
                            ['conversion', 'Conversion'],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setView(key)}
                                className={`rounded px-3 py-1.5 text-xs font-semibold transition ${view === key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        >
            {isLoading ? (
                <div className="grid gap-4 xl:grid-cols-[minmax(280px,0.9fr)_minmax(260px,1fr)]">
                    <div className="mx-auto h-64 w-64 animate-pulse rounded-full bg-slate-100" />
                    <div className="space-y-2">
                        {Array.from({ length: 6 }).map((_, index) => <div key={index} className="h-14 animate-pulse rounded bg-slate-100" />)}
                    </div>
                </div>
            ) : errorMessage ? (
                <div className="flex h-72 flex-col items-center justify-center gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 text-center">
                    <p className="text-sm font-semibold text-rose-900">{errorMessage}</p>
                    {onRetry ? <button type="button" className="crm-btn-secondary" onClick={onRetry}>Retry</button> : null}
                </div>
            ) : markets.length === 0 ? (
                <EmptyState
                    message="No market has sold a visitor unlock in this window."
                    hint="Enable contact unlocks for a market under Setup, or widen the date range."
                />
            ) : (
                <div className="grid gap-5 xl:grid-cols-[minmax(280px,0.85fr)_minmax(280px,1fr)]">
                    <div className="relative h-80">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={chartData}
                                    dataKey="value"
                                    nameKey="name"
                                    innerRadius={82}
                                    outerRadius={128}
                                    paddingAngle={2}
                                    onClick={(entry) => entry?.platform_id ? onSelectMarket?.(String(entry.platform_id)) : undefined}
                                    isAnimationActive
                                >
                                    {chartData.map((entry) => (
                                        <Cell key={entry.name} fill={entry.color} stroke="#fff" strokeWidth={2} />
                                    ))}
                                </Pie>
                                <Tooltip
                                    content={<PieTooltip currency={currency} isFlat={isFlat} />}
                                    wrapperStyle={{ zIndex: 80, outline: 'none' }}
                                    allowEscapeViewBox={{ x: true, y: true }}
                                />
                            </PieChart>
                        </ResponsiveContainer>
                        <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                            <div className="max-w-[150px] text-center">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Unlock revenue</p>
                                <p className="mt-1 text-lg font-semibold tracking-tight text-slate-950">
                                    {formatCurrency(total, currency)}
                                </p>
                                <p className="mt-0.5 text-[11px] text-slate-500">{markets.length} market{markets.length === 1 ? '' : 's'}</p>
                            </div>
                        </div>
                    </div>

                    <div className="min-w-0">
                        <p className="mb-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                            {view === 'conversion' ? 'Top performing markets — checkout to paid' : view === 'volume' ? 'Top performing markets — paid unlocks' : 'Top performing markets — revenue share'}
                        </p>
                        <div className="max-h-[330px] space-y-2 overflow-y-auto pr-1">
                            {ranked.map((market) => {
                                const color = colorByPlatform.get(String(market.platform_id)) || OTHER_COLOR;
                                const active = String(market.platform_id) === String(selectedPlatformId);

                                return (
                                    <button
                                        key={market.platform_id}
                                        type="button"
                                        onClick={() => onSelectMarket?.(String(market.platform_id))}
                                        className={`flex w-full items-start justify-between gap-3 rounded-lg border px-3 py-2.5 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                            active ? 'border-teal-400 bg-teal-50/60 ring-1 ring-teal-200' : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                                        }`}
                                        aria-label={`${market.name}, ${Number(market.share_percent || 0).toFixed(1)} percent of unlock revenue`}
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center gap-2">
                                                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: color }} />
                                                <span className="min-w-0 truncate text-sm font-semibold leading-snug text-slate-900">{marketLabel(market)}</span>
                                            </span>
                                            <span className="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-slate-100">
                                                <span
                                                    style={{
                                                        width: `${Math.max(2, Math.min(100, view === 'conversion'
                                                            ? (Number(market.conversion_percent || 0) / bestConversion) * 100
                                                            : Number(market.share_percent || 0)))}%`,
                                                        backgroundColor: color,
                                                    }}
                                                />
                                            </span>
                                            <span className="mt-1.5 block truncate text-[11px] text-slate-500">
                                                {Number(market.payments_count || 0).toLocaleString()} paid · {Number(market.checkout_starts || 0).toLocaleString()} checkouts · {percentLabel(market.conversion_percent)} convert
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-right">
                                            {market.fx_unresolved ? (
                                                <span className="block text-[10px] font-semibold uppercase tracking-[0.08em] text-amber-700">FX unresolved</span>
                                            ) : (
                                                <span className="block text-sm font-semibold text-slate-900">{Number(market.share_percent || 0).toFixed(1)}%</span>
                                            )}
                                            <span className="block text-[11px] text-slate-500">{moneyLabel(market, currency, isFlat)}</span>
                                            {market.delta_percent === null || market.delta_percent === undefined ? null : (
                                                <span className={`block text-[11px] font-semibold ${Number(market.delta_percent) >= 0 ? 'text-emerald-700' : 'text-rose-600'}`}>
                                                    {Number(market.delta_percent) > 0 ? '+' : ''}{Number(market.delta_percent).toFixed(1)}% vs prior
                                                </span>
                                            )}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}
        </SectionFrame>
    );
}
