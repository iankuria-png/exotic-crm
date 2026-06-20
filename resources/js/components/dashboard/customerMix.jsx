import React from 'react';
import { formatCurrency } from '../../utils/currency';

export const MIX_META = {
    new_active: { label: 'New users', shortLabel: 'New', color: '#14b8a6' },
    existing_active: { label: 'Existing users', shortLabel: 'Existing', color: '#4f46e5' },
    unattributed: { label: 'Unattributed', shortLabel: 'Unattributed', color: '#0284c7' },
    other_matched: { label: 'Other matched', shortLabel: 'Other', color: '#94a3b8' },
};

export function customerMixRows(mix) {
    const buckets = mix?.buckets || {};

    return ['new_active', 'existing_active', 'unattributed', 'other_matched']
        .map((key) => ({
            key,
            ...(MIX_META[key] || {}),
            ...(buckets[key] || {}),
        }))
        .filter((row) => Number(row.normalized_amount || 0) > 0 || Number(row.payments_count || 0) > 0);
}

export function CustomerMixCompact({ mix, currency }) {
    const rows = customerMixRows(mix);

    if (!rows.length) return null;

    return (
        <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Customer revenue mix</p>
                    <p className="mt-1 text-xs text-slate-500">Revenue grouped by whether matched active clients were created in this window.</p>
                </div>
            </div>
            <div className="mt-3 flex h-2 overflow-hidden rounded-full bg-white ring-1 ring-slate-200">
                {rows.map((row) => (
                    <span
                        key={row.key}
                        style={{
                            width: `${Math.max(2, Number(row.share_percent || 0))}%`,
                            backgroundColor: row.color,
                        }}
                    />
                ))}
            </div>
            <div className="mt-3 grid gap-2 sm:grid-cols-2 2xl:grid-cols-4">
                {rows.map((row) => (
                    <div key={row.key} className="rounded-md border border-slate-200 bg-white px-3 py-2">
                        <div className="flex items-center justify-between gap-2">
                            <span className="flex min-w-0 items-center gap-2">
                                <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: row.color }} />
                                <span className="truncate text-xs font-semibold text-slate-700">{row.label}</span>
                            </span>
                            <span className="text-xs font-semibold text-slate-900">{Number(row.share_percent || 0).toFixed(1)}%</span>
                        </div>
                        <p className="mt-1 text-sm font-semibold text-slate-950">{formatCurrency(row.normalized_amount || 0, row.normalized_currency || currency)}</p>
                        <p className="text-[11px] text-slate-500">{Number(row.payments_count || 0).toLocaleString()} payments</p>
                    </div>
                ))}
            </div>
        </div>
    );
}
