import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../../services/api';
import { formatCurrency } from '../../../utils/currency';

const OUTCOME_TABS = [
    { key: 'summary', label: 'Summary' },
    { key: 'floor', label: 'Can the floor deliver it?' },
    { key: 'markets', label: 'By market' },
];

function TabBar({ tabs, active, onChange }) {
    return (
        <div className="flex gap-4 border-b border-slate-200" role="tablist">
            {tabs.map((tab) => (
                <button
                    key={tab.key}
                    type="button"
                    role="tab"
                    aria-selected={active === tab.key}
                    onClick={() => onChange(tab.key)}
                    className={`-mb-px border-b-2 pb-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        active === tab.key
                            ? 'border-teal-600 text-slate-950'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    }`}
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}

function Rows({ isLoading, isError, isEmpty, emptyText, onRetry, children }) {
    if (isLoading) {
        return (
            <div className="space-y-2 py-3" aria-busy="true">
                {[0, 1, 2].map((row) => (
                    <span key={row} className="block h-8 animate-pulse rounded-md bg-slate-100" />
                ))}
            </div>
        );
    }
    if (isError) {
        return (
            <div className="py-3">
                <p className="text-xs text-slate-600">This could not be loaded.</p>
                <button type="button" onClick={onRetry} className="mt-2 h-8 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">Retry</button>
            </div>
        );
    }
    if (isEmpty) {
        return <p className="py-4 text-xs leading-relaxed text-slate-500">{emptyText}</p>;
    }
    return children;
}

/**
 * The scenario total shared out by each agent's trailing revenue share, compared
 * with the goal they already have. It adds no revenue - it asks whether the floor
 * that produced the baseline could produce the scenario.
 */
function FloorTab({ params, currency, scenarioTotal }) {
    const query = useQuery({
        queryKey: ['ceo-dashboard', 'forecast-floor', params, currency],
        queryFn: () => api.get('/crm/dashboard/ceo/agent-performance', {
            params: { ...params, currency },
        }).then((response) => response.data),
        staleTime: 120_000,
    });

    const agents = (query.data?.agents || query.data?.rows || query.data || []).filter?.(Boolean) || [];
    const revenueOf = (agent) => Number(agent?.revenue?.normalized_total || 0);
    const total = agents.reduce((sum, agent) => sum + revenueOf(agent), 0);

    return (
        <Rows
            isLoading={query.isLoading}
            isError={query.isError}
            isEmpty={!agents.length}
            emptyText="No agent revenue in this window, so there is nothing to share the scenario across."
            onRetry={() => query.refetch()}
        >
            <div className="mt-3 space-y-2">
                <p className="text-xs leading-relaxed text-slate-500">
                    The scenario shared out by each agent&rsquo;s share of revenue in this window. This is a
                    cross-check, not extra revenue.
                </p>
                {agents.slice(0, 8).map((agent, index) => {
                    const revenue = revenueOf(agent);
                    const share = total > 0 ? revenue / total : 0;
                    const implied = share * Number(scenarioTotal || 0);
                    const pct = Number(agent?.target?.percentage);

                    return (
                        <div key={agent.id || agent.name || index} className="flex items-baseline justify-between gap-3 border-b border-slate-100 pb-2 last:border-b-0">
                            <div className="min-w-0">
                                <p className="truncate text-xs font-semibold text-slate-900">{agent.name || 'Agent'}</p>
                                <p className="text-[10px] text-slate-500">
                                    {(share * 100).toFixed(1)}% of revenue
                                    {Number.isFinite(pct) && pct >= 0 ? ` · ${pct}% of goal` : ' · no goal set'}
                                </p>
                            </div>
                            <p className="shrink-0 text-xs font-semibold tabular-nums text-slate-900">{formatCurrency(implied, currency)}</p>
                        </div>
                    );
                })}
            </div>
        </Rows>
    );
}

function MarketsTab({ params, currency, scenarioTotal }) {
    const query = useQuery({
        queryKey: ['ceo-dashboard', 'forecast-markets', params, currency],
        queryFn: () => api.get('/crm/dashboard/ceo/market-pie', {
            params: { ...params, currency },
        }).then((response) => response.data),
        staleTime: 120_000,
    });

    const markets = (query.data?.markets || query.data?.slices || query.data || []).filter?.(Boolean) || [];

    return (
        <Rows
            isLoading={query.isLoading}
            isError={query.isError}
            isEmpty={!markets.length}
            emptyText="No market revenue in this window to weight the scenario against."
            onRetry={() => query.refetch()}
        >
            <div className="mt-3 space-y-2">
                <p className="text-xs leading-relaxed text-slate-500">
                    The scenario weighted by each market&rsquo;s actual share of revenue in this window — not an even split.
                </p>
                {markets.slice(0, 10).map((market, index) => {
                    const share = Number(market.share_percent || 0) / 100;
                    return (
                        <div key={market.platform_id || market.label || index} className="flex items-baseline justify-between gap-3 border-b border-slate-100 pb-2 last:border-b-0">
                            <div className="min-w-0">
                                <p className="truncate text-xs font-semibold text-slate-900">{market.label || market.name || 'Market'}</p>
                                <p className="text-[10px] text-slate-500">{Number(market.share_percent || 0).toFixed(1)}% of collected revenue</p>
                            </div>
                            <p className="shrink-0 text-xs font-semibold tabular-nums text-slate-900">
                                {formatCurrency(share * Number(scenarioTotal || 0), currency)}
                            </p>
                        </div>
                    );
                })}
            </div>
        </Rows>
    );
}

export function OutcomeTabs({ params, currency, scenarioTotal, summary }) {
    const [tab, setTab] = useState('summary');

    return (
        <div className="space-y-3">
            <TabBar tabs={OUTCOME_TABS} active={tab} onChange={setTab} />
            {tab === 'summary' ? summary : null}
            {tab === 'floor' ? <FloorTab params={params} currency={currency} scenarioTotal={scenarioTotal} /> : null}
            {tab === 'markets' ? <MarketsTab params={params} currency={currency} scenarioTotal={scenarioTotal} /> : null}
        </div>
    );
}

const BAND_COPY = {
    conservative: 'Every rate held to what this market itself has already achieved.',
    balanced: 'Every rate held to the best any market in the book sustains.',
    stretch: 'Past every rate on record, and new markets may be proposed.',
    downside: 'What the horizon looks like if nothing improves.',
};

export function TargetPanel({
    targetAmount,
    onTargetAmountChange,
    reachByMonths,
    onReachByMonthsChange,
    onSolve,
    solving,
    routes,
    activeRoute,
    onActiveRouteChange,
    activeRouteData,
    currency,
    onOpenForward,
}) {
    const shortfall = Number(activeRouteData?.shortfall || 0);
    const reaches = shortfall <= 0;

    return (
        <div className="space-y-3">
            <div>
                <label className="block text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400" htmlFor="forecast-target">
                    Monthly revenue target
                </label>
                <div className="mt-1 flex gap-2">
                    <input
                        id="forecast-target"
                        type="number"
                        inputMode="numeric"
                        value={targetAmount}
                        onChange={(event) => onTargetAmountChange(event.target.value)}
                        placeholder="90000"
                        className="h-9 min-w-0 flex-1 rounded-md border border-slate-300 px-3 text-sm text-slate-900 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                    />
                    <select
                        value={reachByMonths}
                        onChange={(event) => onReachByMonthsChange(Number(event.target.value))}
                        className="h-9 rounded-md border border-slate-300 px-2 text-sm text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                    >
                        {[3, 6, 9, 12].map((months) => <option key={months} value={months}>{months} mo</option>)}
                    </select>
                </div>
                <p className="mt-1 text-[10px] leading-relaxed text-slate-500">
                    The figure the final month should bill, not an average across the period.
                </p>
                <button
                    type="button"
                    onClick={onSolve}
                    disabled={solving || !targetAmount}
                    className="mt-2 h-9 w-full rounded-md bg-slate-900 px-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {solving ? 'Solving…' : 'Solve target'}
                </button>
            </div>

            {!routes?.length ? (
                <p className="rounded-lg border border-dashed border-slate-200 px-3 py-4 text-xs leading-relaxed text-slate-500">
                    Name a monthly figure and this works backwards: which levers, in which markets, would close
                    the gap — and whether the target is reachable on rates the business has actually hit.
                </p>
            ) : (
                <>
                    <div className="grid grid-cols-4 overflow-hidden rounded-md border border-slate-200">
                        {routes.map((route) => (
                            <button
                                key={route.band}
                                type="button"
                                onClick={() => onActiveRouteChange(route.band)}
                                className={`border-r border-slate-200 px-2 py-2 text-left last:border-r-0 transition ${
                                    activeRoute === route.band ? 'bg-teal-50' : 'bg-white hover:bg-slate-50'
                                }`}
                            >
                                <span className="block text-[10px] font-semibold uppercase text-slate-400">{route.band}</span>
                                <span className="block text-xs font-bold tabular-nums text-slate-900">
                                    {formatCurrency(route.reached_monthly, currency)}
                                </span>
                                <span className={`block text-[10px] font-semibold ${Number(route.shortfall || 0) > 0 ? 'text-amber-700' : 'text-emerald-700'}`}>
                                    {Number(route.shortfall || 0) > 0 ? 'short' : 'reaches'}
                                </span>
                            </button>
                        ))}
                    </div>

                    <div className="rounded-lg border border-slate-200 p-3">
                        <p className="text-[10px] leading-relaxed text-slate-500">{BAND_COPY[activeRoute] || ''}</p>
                        <p className={`mt-2 text-sm font-semibold ${reaches ? 'text-emerald-800' : 'text-amber-800'}`}>
                            {reaches
                                ? 'Reaches the target on this evidence.'
                                : `Falls ${formatCurrency(shortfall, currency)}/month short.`}
                        </p>
                        {!reaches ? (
                            <p className="mt-1 text-[11px] leading-relaxed text-slate-600">
                                Every lever in this band is already at its ceiling. Closing the rest needs a rate
                                nothing here has sustained, a new market, or a longer runway.
                            </p>
                        ) : null}

                        <p className="mt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400">Moves</p>
                        <div className="mt-1 space-y-2">
                            {(activeRouteData?.moves || []).length === 0 ? (
                                <p className="text-[11px] text-slate-500">No move in this band moves the number.</p>
                            ) : null}
                            {(activeRouteData?.moves || []).map((move, index) => (
                                <div key={`${move.lever}-${move.platform_id ?? 'all'}-${index}`} className="border-b border-slate-100 pb-2 last:border-b-0">
                                    <div className="flex items-baseline justify-between gap-2">
                                        <p className="text-xs font-semibold text-slate-900">
                                            {move.label || move.lever}
                                            <span className="ml-1 font-normal text-slate-500">
                                                {Number(move.from || 0).toFixed(1)} → {Number(move.to || 0).toFixed(1)}
                                            </span>
                                        </p>
                                        <p className="shrink-0 text-xs font-semibold tabular-nums text-emerald-700">
                                            +{formatCurrency(move.contribution, currency)}
                                        </p>
                                    </div>
                                    <p className="mt-0.5 text-[10px] text-slate-500">
                                        {move.market_label || 'All markets'} · ceiling: {move.ceiling_basis || 'not stated'}
                                    </p>
                                </div>
                            ))}
                        </div>

                        <button
                            type="button"
                            onClick={onOpenForward}
                            className="mt-3 h-8 w-full rounded-md border border-teal-200 bg-teal-50 px-3 text-xs font-semibold text-teal-800 transition hover:bg-teal-100"
                        >
                            Load these moves into the sliders
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
