import React, { useEffect } from 'react';
import { getCountryFlag } from '../../utils/flags';
import { formatCurrency } from '../../utils/currency';
import { compactNumber, moneyRowsLabel, percentLabel } from './visitorFormat';

/**
 * Drill-down for a Web Visitors metric card. Every card answers "what is behind this number?"
 * in three ways: how it is measured, which markets it came from, and the exact rows — the last
 * one hands the reader off to the Unlocks trail with the matching filter already applied.
 */

function breakdownRows(breakdown = {}) {
    return Object.entries(breakdown).map(([currency, amount]) => ({ currency, amount: Number(amount || 0) }));
}

function Stat({ label, value, tone = 'slate' }) {
    const color = tone === 'positive' ? 'text-emerald-700' : tone === 'negative' ? 'text-rose-600' : 'text-slate-900';

    return (
        <div className="rounded-lg border border-slate-200 bg-white px-3 py-2.5">
            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className={`mt-1 text-lg font-semibold tracking-tight ${color}`}>{value}</p>
        </div>
    );
}

export default function VisitorMetricDrawer({
    metric,
    analytics,
    reportingCurrency,
    isLoading,
    onClose,
    onOpenUnlocks,
    onSelectMarket,
    onExport,
}) {
    useEffect(() => {
        if (!metric) return undefined;
        const onKey = (event) => {
            if (event.key === 'Escape') onClose?.();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [metric, onClose]);

    if (!metric) return null;

    const currency = analytics?.window?.target_currency || reportingCurrency?.targetCurrency || 'USD';
    const totals = analytics?.totals || {};
    const markets = analytics?.markets || [];
    const delta = totals.delta_percent;
    const showMoney = metric.kind === 'money';
    const showMarkets = metric.breakdown !== 'none' && markets.length > 0;

    const marketValue = (market) => {
        if (metric.marketValue) return metric.marketValue(market, currency);
        if (showMoney) {
            return market.normalized_total === null || market.normalized_total === undefined
                ? moneyRowsLabel(breakdownRows(market.source_breakdown), '--')
                : formatCurrency(market.normalized_total, market.normalized_currency || currency);
        }
        return compactNumber(market.payments_count);
    };

    return (
        <div className="fixed inset-0 z-[90] flex bg-slate-900/45" onClick={onClose} role="presentation">
            <aside
                className="ml-auto flex h-full w-full max-w-xl flex-col border-l border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-label={`${metric.label} detail`}
            >
                <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div className="min-w-0">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-teal-700">Visitor unlock metric</p>
                        <h3 className="mt-1 text-lg font-semibold tracking-tight text-slate-900">{metric.label}</h3>
                        <p className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{metric.value}</p>
                        {metric.hint ? <p className="mt-0.5 text-xs text-slate-500">{metric.hint}</p> : null}
                    </div>
                    <button type="button" onClick={onClose} className="crm-btn-secondary shrink-0 px-3 py-1.5 text-xs">Close</button>
                </header>

                <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <section className="rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-3">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">How it is measured</p>
                        <p className="mt-1.5 text-sm leading-5 text-slate-700">{metric.definition}</p>
                        {metric.caveat ? <p className="mt-1.5 text-xs text-slate-500">{metric.caveat}</p> : null}
                    </section>

                    {showMoney ? (
                        <section className="grid gap-2 sm:grid-cols-3">
                            <Stat
                                label="Window revenue"
                                value={totals.normalized_total === null || totals.normalized_total === undefined
                                    ? '--'
                                    : formatCurrency(totals.normalized_total, currency)}
                            />
                            <Stat label="Paid unlocks" value={compactNumber(totals.payments_count)} />
                            <Stat
                                label="Vs prior"
                                value={delta === null || delta === undefined ? 'No prior data' : `${delta > 0 ? '+' : ''}${Number(delta).toFixed(1)}%`}
                                tone={delta === null || delta === undefined ? 'slate' : delta >= 0 ? 'positive' : 'negative'}
                            />
                        </section>
                    ) : null}

                    {showMoney && Object.keys(totals.source_breakdown || {}).length > 0 ? (
                        <section>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Native currencies collected</p>
                            <div className="mt-2 grid gap-1.5">
                                {breakdownRows(totals.source_breakdown).map((row) => (
                                    <div key={row.currency} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 text-sm">
                                        <span className="font-medium text-slate-700">{row.currency}</span>
                                        <span className="tabular-nums text-slate-900">{formatCurrency(row.amount, row.currency)}</span>
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    <section>
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">By market</p>
                            {isLoading ? <span className="text-[11px] text-slate-400">Loading…</span> : null}
                        </div>
                        {isLoading ? (
                            <div className="mt-2 space-y-2">
                                {Array.from({ length: 4 }).map((_, index) => <div key={index} className="h-11 animate-pulse rounded bg-slate-100" />)}
                            </div>
                        ) : !showMarkets ? (
                            <p className="mt-2 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-center text-sm text-slate-500">
                                {metric.breakdown === 'none'
                                    ? 'This metric is computed across the whole selection and has no per-market split.'
                                    : 'No market recorded activity for this metric in the selected window.'}
                            </p>
                        ) : (
                            <div className="mt-2 space-y-1.5">
                                {markets.map((market) => (
                                    <button
                                        key={market.platform_id}
                                        type="button"
                                        onClick={() => onSelectMarket?.(String(market.platform_id))}
                                        className="flex w-full items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-2 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium text-slate-800">
                                                {getCountryFlag(market.country)} {market.name}
                                            </span>
                                            <span className="block truncate text-[11px] text-slate-500">
                                                {compactNumber(market.payments_count)} paid · {compactNumber(market.checkout_starts)} checkouts · {percentLabel(market.conversion_percent)} convert
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-right">
                                            <span className="block text-sm font-semibold tabular-nums text-slate-900">{marketValue(market)}</span>
                                            <span className="block text-[11px] text-slate-500">{Number(market.share_percent || 0).toFixed(1)}% share</span>
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </section>
                </div>

                <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-5 py-3.5">
                    <button type="button" className="crm-btn-secondary text-xs" onClick={onExport}>Export unlocks</button>
                    {metric.unlockFilters ? (
                        <button type="button" className="crm-btn-primary text-xs" onClick={() => onOpenUnlocks?.(metric.unlockFilters)}>
                            {metric.unlockCta || 'Open matching unlocks'}
                        </button>
                    ) : (
                        <span className="text-xs text-slate-400">No row-level list for this metric.</span>
                    )}
                </footer>
            </aside>
        </div>
    );
}
