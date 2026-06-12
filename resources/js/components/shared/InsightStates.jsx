import React from 'react';

export function InsightEmptyState({ title, message }) {
    return (
        <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
            <p className="text-sm font-semibold text-slate-700">{title}</p>
            <p className="mt-1 text-sm text-slate-500">{message}</p>
        </div>
    );
}

export function BarList({ rows, colorClass = 'bg-teal-600', minimumPercent = 6 }) {
    const maxValue = rows.reduce((max, row) => Math.max(max, row.value), 0) || 1;

    return (
        <div className="space-y-3">
            {rows.map((row) => (
                <div key={row.label} className="space-y-2">
                    <div className="flex items-center justify-between gap-3">
                        <p className="min-w-0 truncate text-sm font-medium text-slate-700">{row.label}</p>
                        <p className="shrink-0 whitespace-nowrap text-right text-sm font-semibold text-slate-800">{row.formattedValue}</p>
                    </div>
                    <div className="h-3 overflow-hidden rounded-full bg-slate-100">
                        <div
                            className={`h-full rounded-full ${colorClass}`}
                            style={{
                                width: `${row.value > 0
                                    ? Math.max(minimumPercent, Math.round((row.value / maxValue) * 100))
                                    : 0}%`,
                            }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}
