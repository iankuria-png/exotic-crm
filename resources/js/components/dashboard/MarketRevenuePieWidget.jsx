import React, { useMemo, useState } from 'react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import SectionFrame from '../SectionFrame';
import { getCountryFlag } from '../../utils/flags';
import { formatCurrency } from '../../utils/currency';
import { marketLabel, moneyFromBreakdown } from './ceoFormatters';

const COLORS = ['#0f766e', '#2563eb', '#059669', '#d97706', '#7c3aed', '#be123c', '#0f172a', '#0891b2'];
const CHANNEL_COLORS = {
    self_service: '#0f766e',
    manual: '#d97706',
    other: '#64748b',
};

function EmptyState({ message }) {
    return (
        <div className="flex h-72 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function PieTooltip({ active, payload, reporting, viewMode }) {
    if (!active || !payload?.length) return null;
    const item = payload[0]?.payload;
    if (!item) return null;
    const title = viewMode === 'channel'
        ? (item.label || item.name)
        : (item.platform_id ? marketLabel(item) : 'Other markets');

    return (
        <div className="min-w-[190px] rounded-lg border border-slate-800 bg-slate-950 p-3 text-white shadow-2xl">
            <p className="text-sm font-semibold">{title}</p>
            <p className="mt-1 text-xs text-slate-300">{Number(item.share_percent || 0).toFixed(1)}% of collected revenue</p>
            <p className="mt-2 text-base font-semibold text-teal-200">
                {moneyFromBreakdown(item.source_breakdown, item.normalized_total, item.normalized_currency || reporting?.targetCurrency, reporting?.displayMode)}
            </p>
            <p className="text-xs text-slate-300">{Number(item.payments_count || 0).toLocaleString()} payments</p>
        </div>
    );
}

export default function MarketRevenuePieWidget({ data, reporting, isLoading, errorMessage, onSelectMarket, selectedMarket, onClearMarket }) {
    const [showOther, setShowOther] = useState(false);
    const [viewMode, setViewMode] = useState('market');
    const markets = data?.markets || [];
    const total = Number(data?.total || 0);
    const chartData = useMemo(() => {
        if (viewMode === 'channel') {
            return (data?.channels || []).map((channel) => ({
                ...channel,
                name: channel.label,
                value: Number(channel.normalized_total ?? Object.values(channel.source_breakdown || {}).reduce((sum, amount) => sum + Number(amount || 0), 0)),
                color: CHANNEL_COLORS[channel.key] || '#64748b',
            }));
        }

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
    }, [data?.channels, markets, reporting?.targetCurrency, viewMode]);

    return (
        <SectionFrame
            title="Revenue by Market"
            subtitle={selectedMarket ? `Scoped to ${marketLabel(selectedMarket)}. Clear the scope to compare all markets.` : 'Platform-level revenue share with collection-channel mix.'}
            className="overflow-hidden"
            contentClassName="min-h-[500px]"
            action={(
                <div className="flex flex-wrap justify-end gap-2">
                    {selectedMarket ? (
                        <button
                            type="button"
                            onClick={onClearMarket}
                            className="rounded-md border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800 transition hover:bg-teal-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        >
                            Clear market
                        </button>
                    ) : null}
                    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Pie chart view">
                        {[
                            ['market', 'Markets'],
                            ['channel', 'Channels'],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setViewMode(key)}
                                className={`rounded px-3 py-1.5 text-xs font-semibold transition ${viewMode === key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            )}
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
                <div className="grid gap-5 xl:grid-cols-[minmax(300px,0.9fr)_minmax(260px,1fr)]">
                    <div className="relative h-80">
                        <div className="relative z-10 h-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={chartData}
                                        dataKey="value"
                                        nameKey="name"
                                        innerRadius={82}
                                        outerRadius={128}
                                        paddingAngle={2}
                                        onClick={(entry) => viewMode === 'market' && entry.platform_id ? onSelectMarket(entry.platform_id) : setShowOther(true)}
                                        isAnimationActive
                                    >
                                        {chartData.map((entry) => (
                                            <Cell key={entry.name} fill={entry.color} stroke="#fff" strokeWidth={2} />
                                        ))}
                                    </Pie>
                                    <Tooltip
                                        content={<PieTooltip reporting={reporting} viewMode={viewMode} />}
                                        wrapperStyle={{ zIndex: 80, outline: 'none' }}
                                        allowEscapeViewBox={{ x: true, y: true }}
                                    />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                        <div className="pointer-events-none absolute inset-0 z-0 flex items-center justify-center">
                            <div className="max-w-[140px] text-center">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    {viewMode === 'market' ? 'Collected' : 'Channels'}
                                </p>
                                <p className="mt-1 text-lg font-semibold tracking-tight text-slate-950">
                                    {formatCurrency(total, data?.window?.target_currency || reporting?.targetCurrency || 'USD')}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="min-w-0 space-y-3">
                        <div className="max-h-[340px] space-y-2 overflow-y-auto pr-1">
                            {chartData.map((market) => (
                                <button
                                    key={`${market.platform_id || 'other'}-${market.name}`}
                                    type="button"
                                    onClick={() => viewMode === 'market' && market.platform_id ? onSelectMarket(market.platform_id) : setShowOther((current) => !current)}
                                    className="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2.5 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                    aria-label={`${market.name} ${Number(market.share_percent || 0).toFixed(1)} percent of revenue`}
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: market.color }} />
                                            <span className="min-w-0 text-sm font-semibold leading-snug text-slate-900">
                                                {viewMode === 'channel' ? market.label : (market.platform_id ? marketLabel(market) : 'Other markets')}
                                            </span>
                                        </span>
                                        {viewMode === 'market' && Array.isArray(market.channels) && market.channels.length > 0 ? (
                                            <span className="mt-2 flex h-1.5 overflow-hidden rounded-full bg-slate-100">
                                                {market.channels.map((channel) => (
                                                    <span
                                                        key={channel.key}
                                                        style={{
                                                            width: `${Math.max(3, Number(channel.share_percent || 0))}%`,
                                                            backgroundColor: CHANNEL_COLORS[channel.key] || '#64748b',
                                                        }}
                                                        title={`${channel.label}: ${Number(channel.share_percent || 0).toFixed(1)}%`}
                                                    />
                                                ))}
                                            </span>
                                        ) : (
                                            <span className="mt-1 block truncate text-[11px] text-slate-500">{market.description || `${Number(market.payments_count || 0).toLocaleString()} payments`}</span>
                                        )}
                                    </span>
                                    <span className="shrink-0 text-right">
                                        <span className="block text-sm font-semibold text-slate-900">{Number(market.share_percent || 0).toFixed(1)}%</span>
                                        <span className="block text-[11px] text-slate-500">{formatCurrency(market.normalized_total || 0, market.normalized_currency || reporting?.targetCurrency)}</span>
                                    </span>
                                </button>
                            ))}
                        </div>

                        {viewMode === 'market' ? (
                            <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Collection channels</p>
                                <div className="mt-2 grid gap-2">
                                    {(data?.channels || []).map((channel) => (
                                        <div key={channel.key} className="flex items-center justify-between gap-3 text-xs">
                                            <span className="flex items-center gap-2 font-medium text-slate-700">
                                                <span className="h-2 w-2 rounded-full" style={{ backgroundColor: CHANNEL_COLORS[channel.key] || '#64748b' }} />
                                                {channel.label}
                                            </span>
                                            <span className="text-slate-500">{Number(channel.share_percent || 0).toFixed(1)}%</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ) : null}

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
