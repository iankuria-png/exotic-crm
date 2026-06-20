import React from 'react';
import { formatCurrency } from '../../utils/currency';
import { customerMixRows, MIX_META } from './customerMix';

function EmptyMix() {
    return (
        <div className="flex min-h-72 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
            No customer revenue mix is available for this window.
        </div>
    );
}

function SplitFigure({ row, currency }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4">
            <div className="flex items-center justify-between gap-3">
                <span className="flex min-w-0 items-center gap-2">
                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: row.color }} />
                    <span className="truncate text-sm font-semibold text-slate-700">{row.label}</span>
                </span>
                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                    {Number(row.share_percent || 0).toFixed(1)}%
                </span>
            </div>
            <p className="mt-4 text-2xl font-semibold tracking-tight text-slate-950">
                {formatCurrency(row.normalized_amount || 0, row.normalized_currency || currency)}
            </p>
            <p className="mt-1 text-xs font-medium text-slate-500">
                {Number(row.payments_count || 0).toLocaleString()} payments / {Number(row.clients_count || 0).toLocaleString()} clients
            </p>
        </div>
    );
}

export default function CustomerMixView({ mix, currency = 'USD' }) {
    const rows = customerMixRows(mix);
    const newRow = rows.find((row) => row.key === 'new_active') || { key: 'new_active', ...MIX_META.new_active };
    const existingRow = rows.find((row) => row.key === 'existing_active') || { key: 'existing_active', ...MIX_META.existing_active };

    if (!rows.length) {
        return <EmptyMix />;
    }

    return (
        <div className="space-y-5">
            <div className="grid gap-3 lg:grid-cols-2">
                <SplitFigure row={newRow} currency={currency} />
                <SplitFigure row={existingRow} currency={currency} />
            </div>

            <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Customer mix</p>
                        <p className="mt-1 text-sm text-slate-600">Confirmed revenue this period, grouped by matched client creation date.</p>
                    </div>
                    <p className="text-sm font-semibold text-slate-900">
                        {formatCurrency(mix?.total || 0, mix?.target_currency || currency)}
                    </p>
                </div>

                <div className="mt-4 flex h-3 overflow-hidden rounded-full bg-white ring-1 ring-slate-200">
                    {rows.map((row) => (
                        <span
                            key={row.key}
                            title={`${row.label}: ${Number(row.share_percent || 0).toFixed(1)}%`}
                            style={{
                                width: `${Math.max(2, Number(row.share_percent || 0))}%`,
                                backgroundColor: row.color,
                            }}
                        />
                    ))}
                </div>

                <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {rows.map((row) => (
                        <div key={row.key} className="rounded-md border border-slate-200 bg-white px-3 py-3">
                            <div className="flex items-center justify-between gap-2">
                                <span className="flex min-w-0 items-center gap-2">
                                    <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: row.color }} />
                                    <span className="truncate text-xs font-semibold text-slate-700">{row.label}</span>
                                </span>
                                <span className="text-xs font-semibold text-slate-900">{Number(row.share_percent || 0).toFixed(1)}%</span>
                            </div>
                            <p className="mt-2 text-base font-semibold text-slate-950">
                                {formatCurrency(row.normalized_amount || 0, row.normalized_currency || currency)}
                            </p>
                            <p className="mt-1 text-[11px] text-slate-500">
                                {Number(row.payments_count || 0).toLocaleString()} payments / {Number(row.clients_count || 0).toLocaleString()} clients
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
