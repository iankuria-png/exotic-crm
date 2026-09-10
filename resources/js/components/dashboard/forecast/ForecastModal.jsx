import React, { useEffect, useMemo, useState } from 'react';
import {
    ForecastBuildingState,
    ForecastFailedState,
    ForecastLoadingState,
    ForecastRefusedState,
} from './ForecastStates';
import { useMutation, useQuery } from '@tanstack/react-query';
import api from '../../../services/api';
import FxNormalizationNotice from '../../FxNormalizationNotice';
import { formatCurrency } from '../../../utils/currency';

const DEFAULT_LEVERS = ['failed_recovery', 'new_activations', 'renewal'];

function leverDelta(lever, target) {
    const actual = Number(lever?.actual || 0);
    const eligible = Number(lever?.eligible_units || 0);
    const unitValue = Number(lever?.unit_value || 0);
    const units = lever?.unit === 'count'
        ? Math.max(0, Number(target || 0) - actual)
        : Math.max(0, Number(target || 0) - actual) / 100 * eligible;

    return units * unitValue;
}

function ModeButton({ active, children, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`h-8 rounded-md px-3 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                active ? 'bg-slate-950 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
            }`}
        >
            {children}
        </button>
    );
}

const MODE_COPY = {
    replay: 'What this window would have produced at different rates. The starting figure is the collected revenue on the dashboard behind this.',
    project: 'The selected window extended forward at its own daily rate. Nothing is assumed to grow on its own.',
    target: 'Name a monthly figure and the solver works backwards to the lever moves that reach it.',
};

/** The counts behind a lever's current value, so no figure is unexplained. */
function evidenceLine(key, lever) {
    const e = lever?.evidence || {};
    const n = (value) => Number(value || 0).toLocaleString();

    if (key === 'failed_recovery') {
        return `${n(e.failed_payments)} failed · ${n(e.recovered_payments)} recovered · ${n(e.lost_payments)} lost`;
    }
    if (key === 'new_activations') {
        return `${n(e.new_paid_activations)} first-time payers from ${n(e.created_profiles)} signups`;
    }
    if (key === 'renewal') {
        return `${n(e.renewed)} renewed of ${n(lever.eligible_units)} subscriptions that expired`;
    }
    if (key === 'churn_winback') {
        return `${n(lever.eligible_units)} clients churned in this window`;
    }
    if (key === 'signup_source_conversion') {
        return `${n(lever.eligible_units)} signups across sources`;
    }
    return null;
}

function DerivationNote({ mode, baseline, currency }) {
    const collected = Number(baseline?.baseline_revenue?.normalized_total || 0);
    const days = Number(baseline?.context?.days || 0);
    const daily = Number(baseline?.projection?.daily_run_rate || 0);
    const horizon = Number(baseline?.projection?.horizon_days || 0);

    if (mode === 'replay') {
        return (
            <p className="text-xs leading-relaxed text-slate-500">
                This is the <span className="font-semibold text-slate-700">{formatCurrency(collected, currency)}</span> actually
                collected over {days} days — the same figure as Collected Revenue on the dashboard.
            </p>
        );
    }

    return (
        <p className="text-xs leading-relaxed text-slate-500">
            <span className="font-semibold text-slate-700">{formatCurrency(collected, currency)}</span> collected over {days} days
            {' '}= <span className="font-semibold text-slate-700">{formatCurrency(daily, currency)}/day</span>, carried forward{' '}
            {horizon} days. A straight line — no growth assumed.
        </p>
    );
}

export default function ForecastModal({ open, onClose, params, currency = 'USD' }) {
    // Replay first: the opening number must equal the dashboard's collected revenue
    // for the same window, or nothing else in here is trustworthy.
    const [mode, setMode] = useState('replay');
    const [targets, setTargets] = useState({});
    const [expanded, setExpanded] = useState(false);
    const [targetAmount, setTargetAmount] = useState('');
    const [reachByMonths, setReachByMonths] = useState(3);
    const [activeRoute, setActiveRoute] = useState('balanced');
    const [computed, setComputed] = useState(null);
    const [rangeOverride, setRangeOverride] = useState(null);

    const effectiveParams = useMemo(
        () => (rangeOverride ? { ...params, ...rangeOverride } : params),
        [params, rangeOverride]
    );

    const enabled = open && Boolean(effectiveParams?.from && effectiveParams?.to);
    const baselineQuery = useQuery({
        queryKey: ['ceo-dashboard', 'forecast-baseline', effectiveParams, currency],
        queryFn: () => api.get('/crm/dashboard/ceo/forecast/baseline', {
            params: { ...effectiveParams, currency },
        }).then((response) => response.data),
        enabled,
        staleTime: 60_000,
    });

    // Once a build is queued the status route is the authority: it is the only one
    // that reports progress and, crucially, failure. Re-polling /baseline would sit
    // on 'building' forever after a failed job.
    const jobToken = baselineQuery.data?.state === 'building' ? baselineQuery.data?.job_token : null;
    const statusQuery = useQuery({
        queryKey: ['ceo-dashboard', 'forecast-baseline-status', jobToken],
        queryFn: () => api.get('/crm/dashboard/ceo/forecast/baseline/status', {
            params: { job_token: jobToken },
        }).then((response) => response.data),
        enabled: Boolean(jobToken),
        refetchInterval: (query) => query.state.data?.state === 'building'
            ? Number(query.state.data?.poll_after_ms || 1500)
            : false,
    });

    const buildState = jobToken ? (statusQuery.data || baselineQuery.data) : baselineQuery.data;

    const retryBuild = () => {
        setRangeOverride((current) => (current ? { ...current } : null));
        statusQuery.remove?.();
        baselineQuery.refetch();
    };

    const baseline = buildState?.baseline || baselineQuery.data?.baseline;

    const levers = baseline?.levers || {};
    const visibleKeys = expanded ? Object.keys(levers).filter((key) => key !== 'agent_targets') : DEFAULT_LEVERS;

    useEffect(() => {
        if (!baseline) return;
        setTargets((current) => {
            const next = { ...current };
            Object.entries(baseline.levers || {}).forEach(([key, lever]) => {
                if (!Object.prototype.hasOwnProperty.call(next, key)) {
                    next[key] = Number(lever.actual || 0);
                }
            });
            return next;
        });
    }, [baseline]);

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (event) => {
            if (event.key === 'Escape') onClose?.();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, open]);

    useEffect(() => {
        if (!open || !baseline || mode === 'target') return undefined;

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            const payload = Object.fromEntries(
                Object.entries(targets)
                    .filter(([key, value]) => Number(value) !== Number(levers[key]?.actual || 0))
                    .map(([key, value]) => [key, { target: Number(value) }])
            );

            api.post('/crm/dashboard/ceo/forecast/compute', {
                ...params,
                currency,
                mode,
                levers: payload,
            }, { signal: controller.signal })
                .then((response) => setComputed(response.data))
                .catch((error) => {
                    if (error?.name !== 'CanceledError' && error?.code !== 'ERR_CANCELED') {
                        setComputed(null);
                    }
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [baseline, currency, levers, mode, open, params, targets]);

    const solveMutation = useMutation({
        mutationFn: () => api.post('/crm/dashboard/ceo/forecast/solve', {
            ...params,
            currency,
            mode: 'target',
            monthly_target: Number(targetAmount),
            reach_by_months: Number(reachByMonths),
        }).then((response) => response.data),
    });

    const saveMutation = useMutation({
        mutationFn: () => api.post('/crm/dashboard/ceo/forecast/scenarios', {
            ...params,
            currency,
            mode,
            horizon_days: params?.horizon_days || 90,
            name: `Scenario ${new Date().toLocaleString()}`,
            levers: Object.fromEntries(Object.entries(targets).map(([key, value]) => [key, { target: Number(value) }])),
        }).then((response) => response.data),
    });

    const outcome = mode === 'target' ? solveMutation.data : computed;
    const activeRouteData = useMemo(() => {
        if (!solveMutation.data?.routes) return null;
        return solveMutation.data.routes.find((route) => route.band === activeRoute) || solveMutation.data.routes[0];
    }, [activeRoute, solveMutation.data]);
    const bridgeRows = useMemo(() => (
        mode === 'target'
            ? activeRouteData?.moves || []
            : (outcome?.bridge_rows || []).filter((row) => Math.abs(Number(row.contribution || 0)) > 0.005)
    ), [mode, activeRouteData, outcome]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/45 px-3 py-6 backdrop-blur-sm">
            <div className="w-full max-w-6xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl">
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div>
                        <h2 className="text-base font-semibold text-slate-950">Revenue forecast</h2>
                        <p className="mt-0.5 text-xs text-slate-500">
                            {effectiveParams?.from || '--'} to {effectiveParams?.to || '--'} · {currency}
                            {rangeOverride ? <span className="ml-1 rounded-sm bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">narrowed</span> : null}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <ModeButton active={mode === 'replay'} onClick={() => setMode('replay')}>Replay</ModeButton>
                        <ModeButton active={mode === 'project'} onClick={() => setMode('project')}>Project</ModeButton>
                        <ModeButton active={mode === 'target'} onClick={() => setMode('target')}>Reach a target</ModeButton>
                        <button
                            type="button"
                            onClick={onClose}
                            className="h-8 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        >
                            Close
                        </button>
                    </div>
                </div>

                {baselineQuery.isLoading ? (
                    <ForecastLoadingState />
                ) : baselineQuery.isError ? (
                    <ForecastFailedState
                        data={{ message: 'The forecast could not be reached. Nothing was cached, so retrying is safe.' }}
                        onRetry={retryBuild}
                    />
                ) : buildState?.state === 'failed' ? (
                    <ForecastFailedState data={buildState} onRetry={retryBuild} />
                ) : buildState?.state === 'building' ? (
                    <ForecastBuildingState data={buildState} onCancel={onClose} />
                ) : buildState?.state === 'refused' ? (
                    <ForecastRefusedState
                        data={buildState}
                        onUseSuggested={(suggested) => setRangeOverride({ from: suggested.from, to: suggested.to })}
                    />
                ) : !baseline ? (
                    <ForecastLoadingState />
                ) : (
                    <div className="grid gap-0 xl:grid-cols-[0.95fr_1.1fr_0.95fr]">
                        <aside className="border-b border-slate-200 p-4 xl:border-b-0 xl:border-r">
                            <div className="mb-3 flex items-center justify-between">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400">Levers</p>
                                <button
                                    type="button"
                                    onClick={() => setExpanded((value) => !value)}
                                    className="rounded-md border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-600"
                                >
                                    {expanded ? 'Show core' : '+ Add lever'}
                                </button>
                            </div>

                            <div className="space-y-2">
                                {visibleKeys.map((key) => {
                                    const lever = levers[key];
                                    if (!lever) return null;
                                    const target = targets[key] ?? lever.actual ?? 0;
                                    const delta = leverDelta(lever, target);
                                    const max = lever.unit === 'count' ? Math.max(Number(lever.suggested || 0), Number(lever.actual || 0) + 20) : 100;

                                    return (
                                        <label key={key} className="block rounded-lg border border-slate-200 bg-white p-3">
                                            <span className="flex items-center justify-between gap-3 text-xs font-semibold text-slate-800">
                                                <span>{lever.label}</span>
                                                <span>{lever.unit === 'count' ? Number(target).toFixed(0) : `${Number(target).toFixed(1)}%`}</span>
                                            </span>
                                            <input
                                                type="range"
                                                min="0"
                                                max={max}
                                                step={lever.unit === 'count' ? 1 : 0.5}
                                                value={target}
                                                onChange={(event) => setTargets((current) => ({ ...current, [key]: Number(event.target.value) }))}
                                                className="mt-2 w-full accent-teal-600"
                                            />
                                            <span className="mt-1 flex items-center justify-between text-[10px] text-slate-500">
                                                <span>now {Number(lever.actual || 0).toFixed(lever.unit === 'count' ? 0 : 1)}{lever.unit === 'count' ? '' : '%'}</span>
                                                <span className={delta > 0 ? 'font-semibold text-emerald-700' : 'text-slate-400'}>
                                                    {delta > 0 ? `+${formatCurrency(delta, currency)}` : 'no change'}
                                                </span>
                                            </span>
                                            {evidenceLine(key, lever) ? (
                                                <span className="mt-1 block text-[10px] leading-relaxed text-slate-400">{evidenceLine(key, lever)}</span>
                                            ) : null}
                                        </label>
                                    );
                                })}
                            </div>
                        </aside>

                        <section className="border-b border-slate-200 p-4 xl:border-b-0 xl:border-r">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400">Where the money comes from</p>
                            {mode !== 'target' ? <div className="mt-2"><DerivationNote mode={mode} baseline={baseline} currency={currency} /></div> : null}
                            <div className="mt-3 space-y-3">
                                {bridgeRows.length === 0 ? (
                                    <div className="rounded-lg border border-dashed border-slate-200 px-4 py-6 text-center">
                                        <p className="text-sm font-semibold text-slate-700">Nothing moved yet</p>
                                        <p className="mx-auto mt-1 max-w-xs text-xs leading-relaxed text-slate-500">
                                            Every lever sits at its current rate, so the total is simply the baseline.
                                            Raise one on the left and its contribution appears here.
                                        </p>
                                    </div>
                                ) : null}
                                {bridgeRows.map((row, index) => {
                                    const contribution = Number(row.contribution || 0);
                                    const width = Math.max(4, Math.min(100, Math.abs(contribution) / Math.max(1, Number(outcome?.scenario_total || activeRouteData?.reached_monthly || contribution)) * 100));

                                    return (
                                        <div key={`${row.key || row.lever}-${index}`}>
                                            <div className="mb-1 flex justify-between gap-3 text-xs text-slate-700">
                                                <span>{row.label || row.lever}</span>
                                                <span className="font-semibold">{formatCurrency(contribution, currency)}</span>
                                            </div>
                                            <div className="h-4 rounded-sm bg-slate-100">
                                                <div className={`h-4 rounded-sm ${row.is_baseline ? 'bg-slate-300' : 'bg-teal-600'}`} style={{ width: `${width}%` }} />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>

                        <aside className="p-4">
                            {mode === 'target' ? (
                                <div className="space-y-3">
                                    <div className="grid grid-cols-[1fr_auto] gap-2">
                                        <input
                                            type="number"
                                            min="1"
                                            value={targetAmount}
                                            onChange={(event) => setTargetAmount(event.target.value)}
                                            placeholder="90000"
                                            className="h-9 rounded-md border border-slate-300 px-3 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        />
                                        <select
                                            value={reachByMonths}
                                            onChange={(event) => setReachByMonths(Number(event.target.value))}
                                            className="h-9 rounded-md border border-slate-300 px-2 text-sm"
                                        >
                                            {[1, 2, 3, 4, 6, 9, 12].map((months) => <option key={months} value={months}>{months} mo</option>)}
                                        </select>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => solveMutation.mutate()}
                                        disabled={!Number(targetAmount) || solveMutation.isPending}
                                        className="w-full rounded-md bg-slate-950 px-3 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Solve target
                                    </button>
                                    {solveMutation.error ? <p className="text-xs text-rose-600">{solveMutation.error?.response?.data?.message || 'Target could not be solved.'}</p> : null}
                                    {solveMutation.data ? (
                                        <>
                                            <div className="grid grid-cols-4 overflow-hidden rounded-lg border border-slate-200">
                                                {solveMutation.data.routes.map((route) => (
                                                    <button
                                                        key={route.band}
                                                        type="button"
                                                        onClick={() => setActiveRoute(route.band)}
                                                        className={`border-r border-slate-200 px-2 py-2 text-left last:border-r-0 ${activeRoute === route.band ? 'bg-teal-50' : 'bg-white'}`}
                                                    >
                                                        <span className="block text-[10px] font-semibold uppercase text-slate-400">{route.band}</span>
                                                        <span className="block text-xs font-bold text-slate-900">{formatCurrency(route.reached_monthly, currency)}</span>
                                                    </button>
                                                ))}
                                            </div>
                                            <div className="rounded-lg border border-slate-200 p-3">
                                                <p className="text-sm font-semibold text-slate-950">{activeRouteData?.verdict}</p>
                                                <p className="mt-1 text-xs text-slate-500">Shortfall {formatCurrency(activeRouteData?.shortfall || 0, currency)} / month</p>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        const routeTargets = Object.fromEntries(
                                                            Object.entries(activeRouteData?.lever_inputs || {})
                                                                .map(([key, value]) => [key, Number(value?.target ?? value ?? 0)])
                                                        );
                                                        setTargets((current) => ({ ...current, ...routeTargets }));
                                                        setMode('project');
                                                    }}
                                                    className="mt-3 rounded-md border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800"
                                                >
                                                    Open in forward mode
                                                </button>
                                            </div>
                                        </>
                                    ) : null}
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400">Outcome</p>
                                    <div>
                                        <p className="text-2xl font-semibold tracking-tight text-slate-950">
                                            {formatCurrency(outcome?.scenario_total ?? baseline?.projection?.horizon_run_rate_total ?? 0, currency)}
                                        </p>
                                        <p className="mt-1 text-xs font-semibold text-emerald-700">
                                            {formatCurrency(outcome?.incremental_total || 0, currency)} upside
                                        </p>
                                    </div>
                                    <FxNormalizationNotice meta={outcome?.normalization_meta || baseline?.normalization_meta} />
                                    <p className="text-xs leading-5 text-slate-600">
                                        Straight-line run-rate from the selected window. Deterministic figures; AI narrative is optional.
                                    </p>
                                    <button
                                        type="button"
                                        onClick={() => saveMutation.mutate()}
                                        disabled={saveMutation.isPending}
                                        className="w-full rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Save scenario
                                    </button>
                                    {saveMutation.data ? <p className="text-xs text-emerald-700">Saved.</p> : null}
                                </div>
                            )}
                        </aside>
                    </div>
                )}
            </div>
        </div>
    );
}
