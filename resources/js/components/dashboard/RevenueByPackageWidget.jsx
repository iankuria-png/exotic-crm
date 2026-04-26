import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import SectionFrame from '../SectionFrame';
import CurrencyAmount from '../CurrencyAmount';
import { formatCurrency, asNumber } from '../../utils/currency';

function EmptyState({ message }) {
    return (
        <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function PackageBar({ label, amount, breakdown, normalizedTotal, normalizedCurrency, isFlat, maxValue, fallbackCurrency }) {
    const barValue = isFlat && normalizedTotal != null ? normalizedTotal : asNumber(amount);
    const widthPct = maxValue > 0 ? Math.max(4, Math.round((barValue / maxValue) * 100)) : 0;

    const displayAmount = isFlat && normalizedTotal != null
        ? <span className="font-semibold text-slate-800">{formatCurrency(normalizedTotal, normalizedCurrency || fallbackCurrency)}</span>
        : <CurrencyAmount
            breakdown={breakdown}
            scalarAmount={asNumber(amount)}
            fallbackCurrency={fallbackCurrency}
            stackClassName="text-right text-xs leading-snug font-semibold text-slate-800"
            className="font-semibold text-slate-800"
          />;

    return (
        <div className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-3">
                <p className="min-w-0 truncate text-sm font-medium text-slate-700">{label}</p>
                <div className="shrink-0 text-right text-sm">{displayAmount}</div>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                <div
                    className="h-full rounded-full bg-teal-500 transition-all duration-500"
                    style={{ width: `${widthPct}%` }}
                />
            </div>
        </div>
    );
}

function LoadingBars() {
    return (
        <div className="space-y-4">
            {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="space-y-1.5">
                    <div className="flex justify-between gap-4">
                        <div className="h-4 w-20 animate-pulse rounded bg-slate-100" />
                        <div className="h-4 w-16 animate-pulse rounded bg-slate-100" />
                    </div>
                    <div className="h-1.5 w-full animate-pulse rounded-full bg-slate-100" />
                </div>
            ))}
        </div>
    );
}

export default function RevenueByPackageWidget({
    platformFilter,
    fromDate,
    toDate,
    reportingCurrency,
    onOpenReport,
}) {
    const sharedParams = useMemo(() => ({
        ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
        ...(fromDate ? { from: fromDate } : {}),
        ...(toDate ? { to: toDate } : {}),
        ...reportingCurrency.queryParams,
    }), [platformFilter, fromDate, toDate, reportingCurrency.queryParams]);

    const { data, isLoading, error } = useQuery({
        queryKey: ['dashboard-revenue-by-package', sharedParams],
        queryFn: () => api.get('/crm/reports/summary', { params: sharedParams }).then((r) => r.data),
        staleTime: 60_000,
    });

    const fallbackCurrency = data?.range_currency || 'KES';
    const isFlat = reportingCurrency.isFlat;

    const rows = useMemo(() => {
        const raw = data?.package_revenue || [];
        return raw.filter((r) => asNumber(r.value) > 0 || asNumber(r.normalized_total) > 0);
    }, [data?.package_revenue]);

    const maxValue = useMemo(() => rows.reduce((max, r) => {
        const v = isFlat && r.normalized_total != null ? asNumber(r.normalized_total) : asNumber(r.value);
        return Math.max(max, v);
    }, 0), [rows, isFlat]);

    return (
        <SectionFrame
            title="Revenue by Package"
            subtitle={`Collected revenue by tier${platformFilter ? ' · selected market' : ''}`}
            action={onOpenReport ? (
                <button
                    type="button"
                    onClick={onOpenReport}
                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                >
                    Full report
                </button>
            ) : null}
        >
            {isLoading ? (
                <LoadingBars />
            ) : error ? (
                <EmptyState message="Revenue breakdown is currently unavailable." />
            ) : rows.length === 0 ? (
                <EmptyState message="No revenue collected in the selected window." />
            ) : (
                <div className="space-y-4">
                    {rows.map((row) => (
                        <PackageBar
                            key={row.label}
                            label={row.label}
                            amount={row.value}
                            breakdown={row.revenue_breakdown ?? {}}
                            normalizedTotal={row.normalized_total ?? null}
                            normalizedCurrency={row.normalized_currency || reportingCurrency.targetCurrency}
                            isFlat={isFlat}
                            maxValue={maxValue}
                            fallbackCurrency={fallbackCurrency}
                        />
                    ))}
                </div>
            )}
        </SectionFrame>
    );
}
