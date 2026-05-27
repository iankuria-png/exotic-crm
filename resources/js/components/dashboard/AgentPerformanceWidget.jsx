import React, { useMemo, useState } from 'react';
import { Line, LineChart, ResponsiveContainer } from 'recharts';
import SectionFrame from '../SectionFrame';
import { getCountryFlag } from '../../utils/flags';
import { formatDelta, moneyFromBreakdown } from './ceoFormatters';

const SORTERS = {
    revenue: (agent) => Number(agent.revenue?.normalized_total ?? Object.values(agent.revenue?.source_breakdown || {}).reduce((sum, amount) => sum + Number(amount || 0), 0)),
    renewals: (agent) => Number(agent.renewals?.rate ?? -1),
    activations: (agent) => Number(agent.activations?.count || 0),
    name: (agent) => agent.name || '',
};

function AgentAvatar({ name }) {
    const initials = String(name || '?')
        .split(' ')
        .map((part) => part[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase();

    return (
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-slate-900 text-xs font-semibold text-white">
            {initials}
        </span>
    );
}

export default function AgentPerformanceWidget({ data, reporting, isLoading, errorMessage, focusedAgentId, onOpenTeam }) {
    const [sortKey, setSortKey] = useState('revenue');
    const agents = data?.agents || [];
    const sortedAgents = useMemo(() => {
        const sorter = SORTERS[sortKey] || SORTERS.revenue;
        return [...agents].sort((a, b) => {
            const aValue = sorter(a);
            const bValue = sorter(b);
            if (typeof aValue === 'string') return aValue.localeCompare(bValue);
            return bValue - aValue;
        });
    }, [agents, sortKey]);

    const topThree = sortedAgents.slice(0, 3);

    return (
        <SectionFrame
            title="Agent Portfolio Performance"
            subtitle="Sales and CS accountability by assigned market portfolio."
            action={(
                <button
                    type="button"
                    onClick={onOpenTeam}
                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                >
                    Open Team report
                </button>
            )}
            className="overflow-hidden"
            contentClassName="p-0"
        >
            {isLoading ? (
                <div className="space-y-2 p-4">
                    {Array.from({ length: 6 }).map((_, index) => <div key={index} className="h-16 animate-pulse rounded-lg bg-slate-100" />)}
                </div>
            ) : errorMessage ? (
                <div className="m-4 rounded-lg border border-dashed border-rose-200 bg-rose-50 px-4 py-8 text-center text-sm text-rose-700">{errorMessage}</div>
            ) : sortedAgents.length === 0 ? (
                <div className="m-4 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No active agents found.</div>
            ) : (
                <>
                    <div className="grid gap-2 border-b border-slate-100 bg-slate-50/70 p-4 md:grid-cols-3">
                        {topThree.map((agent, index) => (
                            <article key={agent.id} className="rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-sm">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Top {index + 1}</p>
                                <p className="mt-1 truncate text-sm font-semibold text-slate-900">{agent.name}</p>
                                <p className="mt-1 text-xs text-teal-700">
                                    {moneyFromBreakdown(agent.revenue?.source_breakdown, agent.revenue?.normalized_total, agent.revenue?.normalized_currency || reporting?.targetCurrency, reporting?.displayMode)}
                                </p>
                            </article>
                        ))}
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100">
                            <thead className="bg-white">
                                <tr>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        <button type="button" onClick={() => setSortKey('name')} className="hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">Agent</button>
                                    </th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Markets</th>
                                    <th className="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        <button type="button" onClick={() => setSortKey('revenue')} className="hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">Revenue</button>
                                    </th>
                                    <th className="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        <button type="button" onClick={() => setSortKey('renewals')} className="hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">Renewals</button>
                                    </th>
                                    <th className="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        <button type="button" onClick={() => setSortKey('activations')} className="hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">Activations</button>
                                    </th>
                                    <th className="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Trend</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {sortedAgents.map((agent) => {
                                    const focused = Number(focusedAgentId) === Number(agent.id);
                                    const markets = agent.markets || [];
                                    return (
                                        <tr key={agent.id} className={focused ? 'bg-teal-50/70' : 'bg-white'}>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-3">
                                                    <AgentAvatar name={agent.name} />
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-900">{agent.name}</p>
                                                        <p className="text-xs capitalize text-slate-500">{String(agent.role || '').replace('_', ' ')}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex max-w-[260px] flex-wrap gap-1.5">
                                                    {markets.slice(0, 3).map((market) => (
                                                        <span key={market.id} className="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-medium text-slate-700">
                                                            {getCountryFlag(market.country)} {market.name}
                                                        </span>
                                                    ))}
                                                    {markets.length > 3 ? (
                                                        <span className="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-medium text-slate-500">+{markets.length - 3} more</span>
                                                    ) : null}
                                                    {markets.length === 0 ? <span className="text-xs text-slate-400">All admin markets</span> : null}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <p className="text-sm font-semibold text-slate-900">
                                                    {moneyFromBreakdown(agent.revenue?.source_breakdown, agent.revenue?.normalized_total, agent.revenue?.normalized_currency || reporting?.targetCurrency, reporting?.displayMode)}
                                                </p>
                                                <p className="text-xs text-slate-500">{formatDelta(agent.revenue?.delta_percent)}</p>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <p className="text-sm font-semibold text-slate-900">{agent.renewals?.recovered || 0} / {agent.renewals?.due || 0}</p>
                                                <p className="text-xs text-slate-500">{agent.renewals?.rate === null || agent.renewals?.rate === undefined ? 'No due base' : `${Number(agent.renewals.rate).toFixed(1)}%`}</p>
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm font-semibold text-slate-900">{Number(agent.activations?.count || 0).toLocaleString()}</td>
                                            <td className="px-4 py-3">
                                                <div className="ml-auto h-10 w-28">
                                                    <ResponsiveContainer width="100%" height="100%">
                                                        <LineChart data={agent.sparkline || []}>
                                                            <Line type="monotone" dataKey="value" stroke="#0f766e" strokeWidth={2} dot={false} isAnimationActive={false} />
                                                        </LineChart>
                                                    </ResponsiveContainer>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </SectionFrame>
    );
}
