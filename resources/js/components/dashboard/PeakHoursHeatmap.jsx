import React, { useEffect, useMemo, useRef, useState } from 'react';
import { formatCurrency } from '../../utils/currency';
import { getCountryFlag } from '../../utils/flags';

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
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

function formatDelta(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return 'No baseline';
    const numeric = Number(value);
    return `${numeric >= 0 ? '+' : ''}${numeric.toFixed(1)}%`;
}

function trendCopy(direction, delta) {
    if (direction === 'increasing') return `${formatDelta(delta)} vs baseline`;
    if (direction === 'decreasing') return `${formatDelta(delta)} vs baseline`;
    if (direction === 'new') return 'New revenue vs baseline';
    if (direction === 'flat') return 'Flat vs baseline';

    return 'No baseline';
}

function trendTone(direction) {
    if (direction === 'increasing' || direction === 'new') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (direction === 'decreasing') return 'border-amber-200 bg-amber-50 text-amber-800';

    return 'border-slate-200 bg-slate-50 text-slate-700';
}

function formatDateLabel(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
    if (!match) return value;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    if (Number.isNaN(date.getTime())) return value;

    return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}

function formatWindow(window) {
    if (!window?.from || !window?.to) return 'Selected period';
    if (window.from === window.to) return formatDateLabel(window.from);

    return `${formatDateLabel(window.from)} - ${formatDateLabel(window.to)}`;
}

function StatTile({ label, value, note }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className="mt-1 text-lg font-semibold text-slate-950">{value}</p>
            {note ? <p className="mt-1 text-xs font-medium text-slate-500">{note}</p> : null}
        </div>
    );
}

function WeekdayRevenueModal({ weekday, cells, currency, window, onClose }) {
    const closeButtonRef = useRef(null);
    const dayCells = cells.filter((cell) => Number(cell.dow) === Number(weekday?.dow));
    const maxRevenue = Math.max(...dayCells.map((cell) => Number(cell.value || 0)), 0);
    const topHours = [...dayCells]
        .filter((cell) => Number(cell.value || 0) > 0 || Number(cell.payments_count || 0) > 0)
        .sort((left, right) => Number(right.value || 0) - Number(left.value || 0))
        .slice(0, 3);
    const baselineValue = Number(weekday?.baseline?.value || 0);
    const topAgent = weekday?.top_agent;
    const topCountry = weekday?.top_country;
    const gapDates = Array.isArray(weekday?.gap_dates) ? weekday.gap_dates : [];

    useEffect(() => {
        closeButtonRef.current?.focus();
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', handleKeyDown);

        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [onClose]);

    if (!weekday) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 px-4 py-6 backdrop-blur-sm" role="presentation" onMouseDown={onClose}>
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="weekday-revenue-title"
                className="max-h-[92vh] w-full max-w-4xl overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-2xl"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-teal-700">Weekday revenue</p>
                        <h2 id="weekday-revenue-title" className="mt-1 text-2xl font-semibold text-slate-950">
                            {weekday.label} brings in {formatCurrency(Number(weekday.value || 0), currency)}
                        </h2>
                        <p className="mt-1 text-sm text-slate-500">
                            {formatWindow(window)} · {Number(weekday.occurrences || 0).toLocaleString()} {weekday.label.toLowerCase()}s in scope
                        </p>
                    </div>
                    <button
                        type="button"
                        ref={closeButtonRef}
                        onClick={onClose}
                        className="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-teal-500"
                    >
                        Close
                    </button>
                </div>

                <div className="max-h-[calc(92vh-104px)] overflow-y-auto p-5">
                    <div className="grid gap-3 md:grid-cols-4">
                        <StatTile
                            label="Revenue"
                            value={formatCurrency(Number(weekday.value || 0), currency)}
                            note={`${Number(weekday.active_days || 0).toLocaleString()} active ${weekday.label.toLowerCase()}s`}
                        />
                        <StatTile
                            label="Payments"
                            value={Number(weekday.payments_count || 0).toLocaleString()}
                            note="Successful collected payments"
                        />
                        <StatTile
                            label="Avg ticket"
                            value={formatCurrency(Number(weekday.average_ticket || 0), currency)}
                            note="Revenue per payment"
                        />
                        <div className={`rounded-lg border px-4 py-3 ${trendTone(weekday.trend_direction)}`}>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] opacity-75">Trend</p>
                            <p className="mt-1 text-lg font-semibold capitalize">{weekday.trend_direction || 'baseline'}</p>
                            <p className="mt-1 text-xs font-semibold">{trendCopy(weekday.trend_direction, weekday.delta_percent)}</p>
                        </div>
                    </div>

                    <div className="mt-4 grid gap-3 lg:grid-cols-3">
                        <div className="rounded-lg border border-slate-200 bg-white p-4">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Best sales member</p>
                            {topAgent ? (
                                <>
                                    <p className="mt-2 text-base font-semibold text-slate-950">{topAgent.name}</p>
                                    <p className="mt-1 text-sm font-semibold text-teal-700">{formatCurrency(Number(topAgent.value || 0), currency)}</p>
                                    <p className="text-xs text-slate-500">{Number(topAgent.payments_count || 0).toLocaleString()} payments · Avg {formatCurrency(Number(topAgent.average_ticket || 0), currency)}</p>
                                </>
                            ) : (
                                <p className="mt-2 text-sm text-slate-500">No assigned sales revenue on this weekday.</p>
                            )}
                        </div>

                        <div className="rounded-lg border border-slate-200 bg-white p-4">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Best country</p>
                            {topCountry ? (
                                <>
                                    <p className="mt-2 text-base font-semibold text-slate-950">{getCountryFlag(topCountry.country)} {topCountry.country}</p>
                                    <p className="mt-1 text-sm font-semibold text-teal-700">{formatCurrency(Number(topCountry.value || 0), currency)}</p>
                                    <p className="text-xs text-slate-500">{Number(topCountry.payments_count || 0).toLocaleString()} payments · Avg {formatCurrency(Number(topCountry.average_ticket || 0), currency)}</p>
                                </>
                            ) : (
                                <p className="mt-2 text-sm text-slate-500">No market revenue on this weekday.</p>
                            )}
                        </div>

                        <div className="rounded-lg border border-slate-200 bg-white p-4">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{weekday.label} gaps</p>
                            <p className="mt-2 text-base font-semibold text-slate-950">
                                {Number(weekday.gap_count || 0).toLocaleString()} quiet {Number(weekday.gap_count || 0) === 1 ? 'day' : 'days'}
                            </p>
                            {gapDates.length ? (
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {gapDates.map((date) => (
                                        <span key={date} className="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                                            {formatDateLabel(date)}
                                        </span>
                                    ))}
                                </div>
                            ) : (
                                <p className="mt-1 text-sm text-slate-500">No zero-revenue {weekday.label.toLowerCase()}s in this window.</p>
                            )}
                        </div>
                    </div>

                    <div className="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{weekday.label} hour rhythm</p>
                                <p className="mt-1 text-sm text-slate-500">Baseline revenue: {formatCurrency(baselineValue, currency)}</p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {topHours.length ? topHours.map((cell) => (
                                    <span key={`${cell.dow}-${cell.hour}`} className="rounded-md bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-800">
                                        {formatHour(Number(cell.hour))}: {formatCurrency(Number(cell.value || 0), currency)}
                                    </span>
                                )) : (
                                    <span className="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500">No peak hour yet</span>
                                )}
                            </div>
                        </div>
                        <div className="mt-4 grid gap-1" style={{ gridTemplateColumns: 'repeat(24, minmax(0, 1fr))' }}>
                            {dayCells.map((cell) => {
                                const value = Number(cell.value || 0);
                                const height = maxRevenue > 0 ? Math.max(10, Math.round((value / maxRevenue) * 54)) : 10;

                                return (
                                    <div key={`${cell.dow}-${cell.hour}-bar`} className="flex h-16 flex-col justify-end">
                                        <div
                                            className={`rounded-t-[3px] ${value > 0 ? 'bg-teal-600' : 'bg-slate-100'}`}
                                            style={{ height }}
                                            title={`${hourRange(Number(cell.hour))}: ${formatCurrency(value, currency)}`}
                                        />
                                    </div>
                                );
                            })}
                        </div>
                        <div className="mt-2 grid grid-cols-4 text-[10px] font-semibold text-slate-400">
                            <span>12a</span>
                            <span className="text-center">6a</span>
                            <span className="text-center">12p</span>
                            <span className="text-right">11p</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function PeakHoursHeatmap({ data, isLoading, errorMessage, currency = 'USD' }) {
    const [metric, setMetric] = useState('revenue');
    const [hovered, setHovered] = useState(null);
    const [selectedWeekday, setSelectedWeekday] = useState(null);

    const cells = Array.isArray(data?.cells) ? data.cells : [];
    const weekdays = Array.isArray(data?.weekdays) ? data.weekdays : [];
    const cellMap = useMemo(
        () => new Map(cells.map((cell) => [`${cell.dow}-${cell.hour}`, cell])),
        [cells],
    );
    const weekdayMap = useMemo(
        () => new Map(weekdays.map((day) => [Number(day.dow), day])),
        [weekdays],
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
    const openWeekday = (dow) => {
        setHovered(null);
        setSelectedWeekday(weekdayMap.get(dow) || { dow, label: DAY_NAMES[dow] });
    };

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
                                <button
                                    type="button"
                                    onClick={() => openWeekday(dow)}
                                    className="flex h-7 items-center rounded-md px-1 text-left text-xs font-semibold text-slate-500 transition hover:bg-teal-50 hover:text-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500"
                                >
                                    {day}
                                </button>
                                {HOURS.map((hour) => {
                                    const cell = cellMap.get(`${dow}-${hour}`) || { dow, hour, value: 0, payments_count: 0 };
                                    const revenue = Number(cell.value || 0);
                                    const payments = Number(cell.payments_count || 0);
                                    const zeroRevenueActive = metric === 'revenue' && revenue === 0 && payments > 0;

                                    return (
                                        <button
                                            key={`${dow}-${hour}`}
                                            type="button"
                                            data-testid="peak-hours-cell"
                                            aria-label={`${DAY_NAMES[dow]} ${hourRange(hour)} revenue summary`}
                                            onClick={() => openWeekday(dow)}
                                            onMouseEnter={(event) => setHovered({ day, dow, hour, revenue, payments, x: event.clientX, y: event.clientY })}
                                            onMouseMove={(event) => setHovered((prev) => (prev ? { ...prev, x: event.clientX, y: event.clientY } : prev))}
                                            onMouseLeave={() => setHovered(null)}
                                            className={`h-7 rounded-[4px] ring-1 ring-white transition hover:scale-110 hover:ring-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-700 ${
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

            {selectedWeekday ? (
                <WeekdayRevenueModal
                    weekday={selectedWeekday}
                    cells={cells}
                    currency={currency}
                    window={data?.window}
                    onClose={() => setSelectedWeekday(null)}
                />
            ) : null}
        </div>
    );
}
