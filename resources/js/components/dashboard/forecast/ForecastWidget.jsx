import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../../services/api';
import { formatCurrency } from '../../../utils/currency';

function leverUpside(lever) {
    if (!lever) return 0;
    const actual = Number(lever.actual || 0);
    const suggested = Number(lever.suggested || actual);
    const eligible = Number(lever.eligible_units || 0);
    const unitValue = Number(lever.unit_value || 0);
    const units = lever.unit === 'count'
        ? Math.max(0, suggested - actual)
        : Math.max(0, suggested - actual) / 100 * eligible;

    return units * unitValue;
}

export default function ForecastWidget({ params, currency = 'USD', onOpen }) {
    const enabled = Boolean(params?.from && params?.to);
    const query = useQuery({
        queryKey: ['ceo-dashboard', 'forecast-widget', params],
        queryFn: () => api.get('/crm/dashboard/ceo/forecast/baseline', {
            params: {
                ...params,
                currency,
                cache_only: true,
            },
        }).then((response) => response.data),
        enabled,
        staleTime: 60_000,
    });

    const rows = useMemo(() => {
        const levers = query.data?.baseline?.levers || {};

        return Object.values(levers)
            .filter((lever) => !['agent_targets', 'new_market'].includes(lever.key))
            .map((lever) => ({ ...lever, upside: leverUpside(lever) }))
            .filter((lever) => lever.upside > 0)
            .sort((left, right) => right.upside - left.upside)
            .slice(0, 2);
    }, [query.data]);

    const cold = query.data?.state === 'cold' || !rows.length;
    const title = cold
        ? 'Model a target'
        : `Where ${formatCurrency(rows.reduce((sum, row) => sum + row.upside, 0), currency)} sits`;

    return (
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-slate-950">Revenue Forecast</h2>
                    <p className="mt-0.5 truncate text-xs text-slate-500">{query.isLoading ? 'Checking warm baseline' : title}</p>
                </div>
                <button
                    type="button"
                    onClick={onOpen}
                    className="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                >
                    Open forecast
                </button>
            </div>

            {query.isLoading ? (
                <div className="space-y-3 px-4 py-4" aria-busy="true" aria-label="Loading forecast headroom">
                    {[0, 1].map((row) => (
                        <div key={row} className="space-y-2">
                            <div className="flex justify-between">
                                <span className="h-3 w-24 animate-pulse rounded-sm bg-slate-100" />
                                <span className="h-3 w-16 animate-pulse rounded-sm bg-slate-100" />
                            </div>
                            <span className="block h-1 w-full animate-pulse rounded-sm bg-slate-100" />
                        </div>
                    ))}
                </div>
            ) : query.isError ? (
                <div className="px-4 py-4">
                    <p className="text-xs text-slate-600">Headroom could not be loaded.</p>
                    <button
                        type="button"
                        onClick={() => query.refetch()}
                        className="mt-2 h-8 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                    >
                        Retry
                    </button>
                </div>
            ) : cold ? (
                <button
                    type="button"
                    onClick={onOpen}
                    className="block w-full px-4 py-4 text-left text-xs text-slate-500 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-500"
                >
                    Build a scenario from the selected window.
                    <span className="mt-1 block text-slate-400">Opens instantly once a baseline is cached.</span>
                </button>
            ) : (
                <div>
                    {rows.map((lever) => {
                        const actual = Number(lever.actual || 0);
                        const suggested = Number(lever.suggested || actual);
                        const width = Math.max(0, Math.min(100, actual));
                        const marker = Math.max(0, Math.min(100, suggested));

                        return (
                            <div key={lever.key} className="border-b border-slate-100 px-4 py-3 last:border-b-0">
                                <div className="flex items-baseline justify-between gap-3">
                                    <p className="text-xs font-semibold text-slate-800">{lever.label}</p>
                                    <p className="text-xs font-bold text-emerald-700">{formatCurrency(lever.upside, currency)}</p>
                                </div>
                                <div className="relative mt-2 h-1 rounded-sm bg-slate-100">
                                    <div className="h-1 rounded-sm bg-teal-600" style={{ width: `${width}%` }} />
                                    <span className="absolute top-[-3px] h-2.5 w-0.5 bg-slate-950" style={{ left: `${marker}%` }} />
                                </div>
                                <p className="mt-1.5 text-[10px] text-slate-500">
                                    {actual.toFixed(1)} now · {suggested.toFixed(1)} suggested · {Number(lever.evidence?.lost_payments || lever.evidence?.eligible || lever.eligible_units || 0)} units
                                </p>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
