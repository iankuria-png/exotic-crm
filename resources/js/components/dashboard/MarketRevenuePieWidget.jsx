import React, { useMemo, useState } from 'react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import SectionFrame from '../SectionFrame';
import { getCountryFlag } from '../../utils/flags';
import { formatCurrency } from '../../utils/currency';
import { marketLabel, moneyFromBreakdown } from './ceoFormatters';

const COLORS = ['#0f766e', '#2563eb', '#059669', '#d97706', '#7c3aed', '#be123c', '#0f172a', '#0891b2'];

function EmptyState({ message }) {
    return (
        <div className="flex h-72 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function PieTooltip({ active, payload, reporting }) {
    if (!active || !payload?.length) return null;
    const item = payload[0]?.payload;
    if (!item) return null;

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
            <p className="text-sm font-semibold text-slate-900">{marketLabel(item)}</p>
            <p className="mt-1 text-xs text-slate-500">{Number(item.share_percent || 0).toFixed(1)}% of revenue</p>
            <p className="mt-2 text-sm font-semibold text-teal-800">
                {moneyFromBreakdown(item.source_breakdown, item.normalized_total, item.normalized_currency || reporting?.targetCurrency, reporting?.displayMode)}
            </p>
            <p className="text-xs text-slate-500">{Number(item.payments_count || 0).toLocaleString()} payments</p>
        </div>
    );
}

export default function MarketRevenuePieWidget({ data, reporting, isLoading, errorMessage, onSelectMarket }) {
    const [showOther, setShowOther] = useState(false);
    const markets = data?.markets || [];
    const total = Number(data?.total || 0);
    const chartData = useMemo(() => {
        const top = [];
        const other = [];
        markets.forEach((market) => {
            if (Number(market.share_percent || 0) <= 2) other.push(market);
            else top.push(market);
        });

        const rows = top.map((market, index) => ({
            ...market,
            value: Number(market.normalized_total ?? Object.values(market.source_breakdown || {}).reduce((sum, amount) => sum + Number(amount || 0), 0)),
            color: COLORS[index % COLORS.length],
        }));

        if (other.length > 0) {
            rows.push({
                platform_id: null,
                name: 'Other',
                country: '',
                share_percent: other.reduce((sum, item) => sum + Number(item.share_percent || 0), 0),
                source_breakdown: other.reduce((acc, item) => {
                    Object.entries(item.source_breakdown || {}).forEach(([currency, amount]) => {
                        acc[currency] = (acc[currency] || 0) + Number(amount || 0);
                    });
                    return acc;
                }, {}),
                normalized_total: other.reduce((sum, item) => sum + Number(item.normalized_total || 0), 0),
                normalized_currency: reporting?.targetCurrency || 'USD',
                payments_count: other.reduce((sum, item) => sum + Number(item.payments_count || 0), 0),
                color: '#94a3b8',
                other_markets: other,
                value: other.reduce((sum, item) => sum + Number(item.normalized_total || 0), 0),
            });
        }

        return rows;
    }, [markets, reporting?.targetCurrency]);

    return (
        <SectionFrame
            title="Revenue by Market"
            subtitle="Platform-level revenue share. Country appears only as visual context."
            className="overflow-hidden"
            contentClassName="min-h-[360px]"
        >
            {isLoading ? (
                <div className="grid gap-4 md:grid-cols-[240px_1fr]">
                    <div className="h-64 animate-pulse rounded-full bg-slate-100" />
                    <div className="space-y-2">
                        {Array.from({ length: 6 }).map((_, index) => <div key={index} className="h-10 animate-pulse rounded bg-slate-100" />)}
                    </div>
                </div>
            ) : errorMessage ? (
                <EmptyState message={errorMessage} />
            ) : total <= 0 || chartData.length === 0 ? (
                <EmptyState message="No market revenue in this window yet." />
            ) : (
                <div className="grid gap-4 lg:grid-cols-[260px_1fr]">
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={chartData}
                                    dataKey="value"
                                    nameKey="name"
                                    innerRadius={72}
                                    outerRadius={112}
                                    paddingAngle={2}
                                    onClick={(entry) => entry.platform_id ? onSelectMarket(entry.platform_id) : setShowOther(true)}
                                    isAnimationActive
                                >
                                    {chartData.map((entry) => (
                                        <Cell key={entry.name} fill={entry.color} stroke="#fff" strokeWidth={2} />
                                    ))}
                                </Pie>
                                <Tooltip content={<PieTooltip reporting={reporting} />} />
                            </PieChart>
                        </ResponsiveContainer>
                    </div>

                    <div className="space-y-2">
                        {chartData.slice(0, 8).map((market) => (
                            <button
                                key={`${market.platform_id || 'other'}-${market.name}`}
                                type="button"
                                onClick={() => market.platform_id ? onSelectMarket(market.platform_id) : setShowOther((current) => !current)}
                                className="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                aria-label={`${market.name} ${Number(market.share_percent || 0).toFixed(1)} percent of revenue`}
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: market.color }} />
                                    <span className="truncate text-sm font-semibold text-slate-900">
                                        {market.platform_id ? marketLabel(market) : 'Other markets'}
                                    </span>
                                </span>
                                <span className="shrink-0 text-right">
                                    <span className="block text-sm font-semibold text-slate-900">{Number(market.share_percent || 0).toFixed(1)}%</span>
                                    <span className="block text-[11px] text-slate-500">{formatCurrency(market.normalized_total || 0, market.normalized_currency || reporting?.targetCurrency)}</span>
                                </span>
                            </button>
                        ))}

                        {showOther && chartData.find((item) => item.other_markets)?.other_markets?.length ? (
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Other markets</p>
                                <div className="mt-2 grid gap-1">
                                    {chartData.find((item) => item.other_markets).other_markets.map((market) => (
                                        <button
                                            key={market.platform_id}
                                            type="button"
                                            onClick={() => onSelectMarket(market.platform_id)}
                                            className="flex items-center justify-between rounded-md px-2 py-1.5 text-left text-xs transition hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                        >
                                            <span className="font-medium text-slate-700">{getCountryFlag(market.country)} {market.name}</span>
                                            <span className="text-slate-500">{Number(market.share_percent || 0).toFixed(1)}%</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ) : null}
                    </div>
                </div>
            )}
        </SectionFrame>
    );
}
