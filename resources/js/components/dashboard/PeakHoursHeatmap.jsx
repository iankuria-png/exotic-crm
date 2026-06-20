import React from 'react';
import { formatCurrency } from '../../utils/currency';

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const HOURS = Array.from({ length: 24 }, (_, hour) => hour);
const INTENSITY = ['bg-slate-100', 'bg-teal-100', 'bg-teal-200', 'bg-teal-400', 'bg-teal-700'];

function EmptyState({ message }) {
    return (
        <div className="flex h-72 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function hourLabel(hour) {
    if (hour === 0) return '12a';
    if (hour === 6) return '6a';
    if (hour === 12) return '12p';
    if (hour === 18) return '6p';
    if (hour === 23) return '11p';

    return '';
}

function cellClass(value, maxValue) {
    if (!value || !maxValue) return INTENSITY[0];
    const ratio = value / maxValue;
    if (ratio >= 0.75) return INTENSITY[4];
    if (ratio >= 0.5) return INTENSITY[3];
    if (ratio >= 0.25) return INTENSITY[2];

    return INTENSITY[1];
}

function formatHour(hour) {
    const suffix = hour >= 12 ? 'p' : 'a';
    const normalized = hour % 12 || 12;

    return `${normalized}${suffix}`;
}

export default function PeakHoursHeatmap({ data, isLoading, errorMessage, currency = 'USD' }) {
    if (isLoading) {
        return (
            <div className="space-y-4">
                <div className="h-16 animate-pulse rounded-lg bg-slate-100" />
                <div className="h-72 animate-pulse rounded-lg bg-slate-100" />
            </div>
        );
    }

    if (errorMessage) {
        return <EmptyState message={errorMessage} />;
    }

    const cells = Array.isArray(data?.cells) ? data.cells : [];
    if (!cells.length) {
        return <EmptyState message="No sales-by-hour data is available for this window yet." />;
    }

    const cellMap = new Map(cells.map((cell) => [`${cell.dow}-${cell.hour}`, cell]));
    const maxValue = Math.max(...cells.map((cell) => Number(cell.value || 0)), 0);
    const peak = data?.peak || {};
    const peakDay = DAYS[Number(peak.dow || 0)] || 'Mon';
    const peakHour = Number(peak.hour || 0);

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 rounded-lg border border-teal-100 bg-teal-50/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Sales by hour</p>
                    <p className="mt-1 text-lg font-semibold text-slate-950">
                        Peak / {peakDay} {formatHour(peakHour)}-{formatHour((peakHour + 1) % 24)}
                    </p>
                </div>
                <div className="grid grid-cols-2 gap-3 text-right sm:flex sm:items-center">
                    <div>
                        <p className="text-xs font-semibold uppercase text-slate-500">Peak revenue</p>
                        <p className="text-sm font-semibold text-slate-950">{formatCurrency(peak.value || 0, currency)}</p>
                    </div>
                    <div>
                        <p className="text-xs font-semibold uppercase text-slate-500">Avg/active hour</p>
                        <p className="text-sm font-semibold text-slate-950">{formatCurrency(data?.avg_per_active_hour || 0, currency)}</p>
                    </div>
                </div>
            </div>

            <div className="overflow-x-auto pb-2">
                <div className="min-w-[720px]">
                    <div className="grid grid-cols-[44px_repeat(24,minmax(20px,1fr))] gap-1">
                        <div />
                        {HOURS.map((hour) => (
                            <div key={hour} className="h-5 text-center text-[10px] font-semibold text-slate-400">
                                {hourLabel(hour)}
                            </div>
                        ))}
                        {DAYS.map((day, dow) => (
                            <React.Fragment key={day}>
                                <div className="flex h-6 items-center text-xs font-semibold text-slate-500">{day}</div>
                                {HOURS.map((hour) => {
                                    const cell = cellMap.get(`${dow}-${hour}`) || { dow, hour, value: 0, payments_count: 0 };
                                    const value = Number(cell.value || 0);
                                    const payments = Number(cell.payments_count || 0);

                                    return (
                                        <div
                                            key={`${dow}-${hour}`}
                                            data-testid="peak-hours-cell"
                                            title={`${day} ${String(hour).padStart(2, '0')}:00 / ${formatCurrency(value, currency)} / ${payments} payments`}
                                            className={`h-6 rounded-[4px] ring-1 ring-white transition hover:scale-110 hover:ring-slate-500 ${cellClass(value, maxValue)}`}
                                        />
                                    );
                                })}
                            </React.Fragment>
                        ))}
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 text-xs font-medium text-slate-500">
                <span>Timezone: {data?.timezone || 'Africa/Nairobi'}</span>
                <span className="flex items-center gap-2">
                    Lower
                    {INTENSITY.map((className) => (
                        <span key={className} className={`h-3 w-3 rounded-[3px] ${className} ring-1 ring-white`} />
                    ))}
                    Higher
                </span>
            </div>
        </div>
    );
}
