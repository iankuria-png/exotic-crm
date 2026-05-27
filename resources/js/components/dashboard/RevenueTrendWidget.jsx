import React from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import SectionFrame from '../SectionFrame';
import { formatCurrency } from '../../utils/currency';

function EmptyState({ message }) {
    return (
        <div className="flex h-72 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function TrendTooltip({ active, payload, label, currency }) {
    if (!active || !payload?.length) return null;
    const current = payload.find((entry) => entry.dataKey === 'value')?.value;
    const prior = payload.find((entry) => entry.dataKey === 'prior_value')?.value;

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">Current: {formatCurrency(current || 0, currency)}</p>
            <p className="text-sm font-medium text-slate-500">Prior: {formatCurrency(prior || 0, currency)}</p>
        </div>
    );
}

export default function RevenueTrendWidget({ data, isLoading, errorMessage, currency = 'USD' }) {
    const points = data?.points || [];
    const hasData = points.some((point) => Number(point.value || 0) > 0 || Number(point.prior_value || 0) > 0);

    return (
        <SectionFrame
            title="Revenue Trend"
            subtitle="Collected revenue by bucket, overlaid against the prior matching window."
            className="overflow-hidden"
            contentClassName="min-h-[330px]"
        >
            {isLoading ? (
                <div className="h-72 animate-pulse rounded-lg bg-slate-100" />
            ) : errorMessage ? (
                <EmptyState message={errorMessage} />
            ) : !hasData ? (
                <EmptyState message="No collected revenue in this window yet." />
            ) : (
                <div className="h-72">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={points} margin={{ top: 12, right: 16, left: 4, bottom: 0 }}>
                            <defs>
                                <linearGradient id="ceoRevenueTrend" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="5%" stopColor="#0f766e" stopOpacity={0.24} />
                                    <stop offset="95%" stopColor="#0f766e" stopOpacity={0.02} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                            <XAxis dataKey="label" tick={{ fontSize: 11, fill: '#64748b' }} tickLine={false} axisLine={false} minTickGap={20} />
                            <YAxis tick={{ fontSize: 11, fill: '#64748b' }} tickLine={false} axisLine={false} width={72} tickFormatter={(value) => formatCurrency(value, currency).replace(`${currency} `, '')} />
                            <Tooltip content={<TrendTooltip currency={currency} />} />
                            <Area type="monotone" dataKey="value" stroke="#0f766e" strokeWidth={2.5} fill="url(#ceoRevenueTrend)" name="Current" />
                            <Line type="monotone" dataKey="prior_value" stroke="#94a3b8" strokeWidth={2} strokeDasharray="5 5" dot={false} name="Prior" />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            )}
        </SectionFrame>
    );
}
