import React, { useMemo, useState } from 'react';
import { formatCurrency } from '../../utils/currency';

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const HOURS = Array.from({ length: 24 }, (_, hour) => hour);
const INTENSITY = ['bg-slate-100', 'bg-teal-100', 'bg-teal-200', 'bg-teal-400', 'bg-teal-700'];
const METRICS = [
    { key: 'revenue', label: 'Revenue' },
    { key: 'payments', label: 'Payments' },
];

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

function intensityClass(metricValue, maxValue) {
    if (!metricValue || !maxValue) return INTENSITY[0];
    const ratio = metricValue / maxValue;
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

function hourRange(hour) {
    const next = (hour + 1) % 24;
    return `${String(hour).padStart(2, '0')}:00–${String(next).padStart(2, '0')}:00`;
}

export default function PeakHoursHeatmap({ data, isLoading, errorMessage, currency = 'USD' }) {
    const [metric, setMetric] = useState('revenue');
    const [hovered, setHovered] = useState(null);

    const cells = Array.isArray(data?.cells) ? data.cells : [];
    const cellMap = useMemo(
        () => new Map(cells.map((cell) => [`${cell.dow}-${cell.hour}`, cell])),
        [cells],
    );

    const metricValue = (cell) => (metric === 'revenue' ? Number(cell?.value || 0) : Number(cell?.payments_count || 0));
    const maxValue = useMemo(
        () => Math.max(...cells.map((cell) => metricValue(cell)), 0),
        [cells, metric],
    );

    const summary = useMemo(() => {
        const totalRevenue = cells.reduce((sum, cell) => sum + Number(cell.value || 0), 0);
        const totalPayments = cells.reduce((sum, cell) => sum + Number(cell.payments_count || 0), 0);
        const activeCells = cells.filter((cell) => metricValue(cell) > 0);
        const top = cells.reduce((best, cell) => (metricValue(cell) > metricValue(best) ? cell : best), cells[0] || null);
        const avgActive = activeCells.length
            ? (metric === 'revenue' ? totalRevenue : totalPayments) / activeCells.length
            : 0;
        return { totalRevenue, totalPayments, top, avgActive, activeCount: activeCells.length };
    }, [cells, metric]);

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

    if (!cells.length) {
        return <EmptyState message="No sales-by-hour data is available for this window yet." />;
    }

    const top = summary.top || {};
    const topDay = DAYS[Number(top.dow || 0)] || 'Mon';
    const topHour = Number(top.hour || 0);
    const peakLabel = metric === 'revenue' ? 'Peak revenue' : 'Busiest hour';
    const peakValue = metric === 'revenue'
        ? formatCurrency(Number(top.value || 0), currency)
        : `${Number(top.payments_count || 0).toLocaleString()} payments`;
    const avgLabel = metric === 'revenue' ? 'Avg/active hour' : 'Avg payments/hr';
    const avgValue = metric === 'revenue'
        ? formatCurrency(summary.avgActive || 0, currency)
        : (Math.round((summary.avgActive || 0) * 10) / 10).toLocaleString();

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 rounded-lg border border-teal-100 bg-teal-50/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="flex items-center gap-2">
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Sales by hour</p>
                        <div className="inline-flex rounded-md border border-teal-200 bg-white p-0.5" role="tablist" aria-label="Peak-hours metric">
                            {METRICS.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    role="tab"
                                    aria-selected={metric === item.key}
                                    onClick={() => setMetric(item.key)}
                                    className={`rounded px-2 py-0.5 text-[11px] font-semibold transition ${metric === item.key ? 'bg-teal-700 text-white' : 'text-teal-700 hover:bg-teal-50'}`}
                                >
                                    {item.label}
                                </button>
                            ))}
                        </div>
                    </div>
                    <p className="mt-1 text-lg font-semibold text-slate-950">
                        {topDay} {formatHour(topHour)}–{formatHour((topHour + 1) % 24)}
                    </p>
                </div>
                <div className="grid grid-cols-2 gap-3 text-right sm:flex sm:items-center">
                    <div>
                        <p className="text-xs font-semibold uppercase text-slate-500">{peakLabel}</p>
                        <p className="text-sm font-semibold text-slate-950">{peakValue}</p>
                    </div>
                    <div>
                        <p className="text-xs font-semibold uppercase text-slate-500">{avgLabel}</p>
                        <p className="text-sm font-semibold text-slate-950">{avgValue}</p>
                    </div>
                </div>
            </div>

            <div className="relative overflow-x-auto pb-2">
                <div className="min-w-[760px]">
                    <div className="grid grid-cols-[44px_repeat(24,minmax(22px,1fr))] gap-1">
                        <div />
                        {HOURS.map((hour) => (
                            <div key={hour} className="h-5 text-center text-[10px] font-semibold text-slate-400">
                                {hourLabel(hour)}
                            </div>
                        ))}
                        {DAYS.map((day, dow) => (
                            <React.Fragment key={day}>
                                <div className="flex h-7 items-center text-xs font-semibold text-slate-500">{day}</div>
                                {HOURS.map((hour) => {
                                    const cell = cellMap.get(`${dow}-${hour}`) || { dow, hour, value: 0, payments_count: 0 };
                                    const revenue = Number(cell.value || 0);
                                    const payments = Number(cell.payments_count || 0);
                                    const zeroRevenueActive = metric === 'revenue' && revenue === 0 && payments > 0;

                                    return (
                                        <div
                                            key={`${dow}-${hour}`}
                                            data-testid="peak-hours-cell"
                                            onMouseEnter={(event) => setHovered({ day, dow, hour, revenue, payments, x: event.clientX, y: event.clientY })}
                                            onMouseMove={(event) => setHovered((prev) => (prev ? { ...prev, x: event.clientX, y: event.clientY } : prev))}
                                            onMouseLeave={() => setHovered(null)}
                                            className={`h-7 rounded-[4px] ring-1 ring-white transition hover:scale-110 hover:ring-slate-500 ${
                                                zeroRevenueActive
                                                    ? 'border border-dashed border-teal-400 bg-slate-100'
                                                    : intensityClass(metricValue(cell), maxValue)
                                            }`}
                                        />
                                    );
                                })}
                            </React.Fragment>
                        ))}
                    </div>
                </div>

                {hovered ? (
                    <div
                        className="pointer-events-none fixed z-50 w-44 rounded-lg border border-slate-200 bg-white p-3 shadow-xl"
                        style={{ left: hovered.x + 14, top: hovered.y + 14 }}
                    >
                        <p className="text-xs font-semibold text-slate-500">{hovered.day} · {hourRange(hovered.hour)}</p>
                        <p className="mt-1 text-sm font-semibold text-slate-900">{formatCurrency(hovered.revenue, currency)}</p>
                        <p className="text-xs text-slate-500">
                            {hovered.payments.toLocaleString()} payments
                            {hovered.payments > 0 ? ` · Avg ${formatCurrency(hovered.revenue / hovered.payments, currency)}` : ''}
                        </p>
                        {hovered.revenue === 0 && hovered.payments > 0 ? (
                            <p className="mt-1 text-[11px] font-medium text-teal-700">Activity with no collected revenue (e.g. free trials).</p>
                        ) : null}
                    </div>
                ) : null}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 text-xs font-medium text-slate-500">
                <span className="flex items-center gap-3">
                    <span>Timezone: {data?.timezone || 'Africa/Nairobi'}</span>
                    {metric === 'revenue' ? (
                        <span className="flex items-center gap-1.5">
                            <span className="h-3 w-3 rounded-[3px] border border-dashed border-teal-400 bg-slate-100" />
                            Activity, no revenue
                        </span>
                    ) : null}
                </span>
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
